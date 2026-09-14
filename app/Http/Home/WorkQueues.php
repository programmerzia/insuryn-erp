<?php

declare(strict_types=1);

namespace App\Http\Home;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Claims\Domain\Enums\ClaimPaymentStatus;
use App\Modules\Insurance\Claims\Http\Controllers\ClaimPageController;
use App\Modules\Insurance\Collections\Http\Controllers\CollectionsPageController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController;
use App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController;
use App\Modules\Platform\Approvals\ApprovalInboxQuery;
use App\Modules\Platform\Authorization\AreaReach;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Role work queues (UX brief §1.1, §5): what needs action for this user, per seeded role template. The same queries give the home blocks
 * (count + top five rows) and the sidebar badge counts. App-level composition over module tables, read-only.
 */
final class WorkQueues
{
    /** Brief §5 blocks per role template, top to bottom. SLA breaches are not listed: claim SLA timers are not built (exit checklist). */
    public const BY_ROLE = [
        'branch_officer' => ['installments_due', 'lapsing_policies', 'receipts_to_record', 'quotes'],
        'branch_manager' => ['installments_due', 'lapsing_policies', 'receipts_to_record', 'quotes'],
        'accountant' => ['unallocated_receipts', 'unmatched_bank_lines', 'journals_to_approve', 'failed_events'],
        'claims_officer' => ['claims_awaiting_reserve', 'claim_approvals', 'payments_to_release'],
        // Flow fix X3: the claims manager decides settlements of reserved claims; finance releases requested claim payments.
        'claims_manager' => ['claims_awaiting_reserve', 'claims_to_settle', 'claim_approvals', 'payments_to_release'],
        'finance_manager' => ['close_progress', 'reconciliation_variances', 'approvals_over_threshold', 'cash_position', 'payments_to_release'],
        'cfo' => ['close_progress', 'reconciliation_variances', 'approvals_over_threshold', 'cash_position', 'payments_to_release'],
        'auditor' => ['recent_reversals', 'period_reopens', 'control_manual_postings'],
    ];

    /** Sidebar badge → the queue whose count it shows (lib/navigation.ts badge keys). */
    private const BADGES = ['receipts' => 'installments_due', 'policies' => 'lapsing_policies', 'bank' => ['receipts_to_record', 'unmatched_bank_lines'],
        'suspense' => 'unallocated_receipts', 'journals' => 'journals_to_approve', 'claims' => ['claims_awaiting_reserve', 'claims_to_settle', 'payments_to_release'], 'close' => 'reconciliation_variances'];

    private const TOP = 5;

    /** @var array<string, AreaReach> */
    private array $reaches = [];

    public function __construct(private readonly PermissionChecker $permissions, private readonly ApprovalInboxQuery $inbox) {}

    /** @return list<string> queue keys for the user's role templates, each once, in role order */
    public function keysFor(string $userId): array
    {
        $roles = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $userId)->pluck('r.code')->map(fn ($c): string => (string) $c)->all();
        $keys = [];
        foreach (self::BY_ROLE as $role => $queues) {
            if (in_array($role, $roles, true)) {
                array_push($keys, ...$queues);
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<array<string, mixed>> */
    public function blocks(string $userId): array
    {
        return array_map(fn (string $key): array => $this->block($key, $userId), $this->keysFor($userId));
    }

    /** @return array<string, int> */
    public function badges(string $userId): array
    {
        $keys = $this->keysFor($userId);
        $counts = [];
        foreach (self::BADGES as $badge => $queues) {
            foreach ((array) $queues as $queue) {
                if (in_array($queue, $keys, true)) {
                    $counts[$badge] = ($counts[$badge] ?? 0) + $this->count($queue, $userId);
                }
            }
        }

        return array_filter($counts);
    }

    private function count(string $key, string $userId): int
    {
        return match ($key) {
            'claim_approvals' => count($this->approvals($userId, true)),
            'approvals_over_threshold' => count($this->approvals($userId, false)),
            'close_progress' => $this->closeRun() === null ? 0 : (int) DB::table('period_close_tasks')->where('close_run_id', $this->closeRun()->id)->whereNotIn('status', ['done', 'skipped'])->count(),
            'cash_position' => 0,
            default => $this->query($key, $userId)->count(),
        };
    }

    /** @return array<string, mixed> */
    private function block(string $key, string $userId): array
    {
        $today = app(BusinessClock::class)->today();
        // Brief §4 empty state: one sentence and one action (label, href).
        [$title, $href, $empty, $action] = match ($key) {
            'installments_due' => ['Installments due this week', '/receipts/create', 'No installments fall due in the next seven days.', ['Record a receipt', '/receipts/create']],
            'lapsing_policies' => ['Lapsing policies', '/dunning', 'No policy is close to lapsing.', ['See payment reminders', '/dunning']],
            'receipts_to_record' => ['Receipts to record', '/bank', 'Every credit on the bank statements has a receipt.', ['Import a bank statement', '/bank']],
            'quotes' => ['Quotes to follow up', '/quotations', 'No open quotes.', ['New quote', '/quotations/create']],
            'unallocated_receipts' => ['Unallocated receipts', '/suspense', 'No unallocated receipts.', ['Import a bank statement', '/bank']],
            'unmatched_bank_lines' => ['Unmatched bank lines', '/bank', 'Every statement line is matched or explained.', ['Import a bank statement', '/bank']],
            'journals_to_approve' => ['Journals awaiting my approval', '/accounting/journals?f.status=pending_approval', 'No journals are waiting for you.', ['Open journals', '/accounting/journals']],
            'failed_events' => ['Failed accounting events', null, 'Every accounting event posted.', ['Open journals', '/accounting/journals']],
            'claims_awaiting_reserve' => ['Claims awaiting reserve', '/claims?status=registered', 'Every open claim has a reserve.', ['Register a claim', '/claims/create']],
            'claims_to_settle' => ['Claims to settle', '/claims?status=reserved', 'No reserved claim is waiting for a settlement decision.', ['Open claims', '/claims']],
            'claim_approvals' => ['Awaiting my approval', '/approvals', 'No claim approvals are waiting for you.', ['Open claims', '/claims']],
            'payments_to_release' => ['Payments to release', '/claims', 'No approved payments are waiting to be paid.', ['Open claims', '/claims']],
            'close_progress' => ['Close progress', '/close', 'No month-end close is running.', ['Start the close', '/close']],
            'reconciliation_variances' => ['Reconciliation variances', '/close', 'Every subledger reconciles to the ledger.', ['Open the close', '/close']],
            'approvals_over_threshold' => ['Approvals over threshold', '/approvals', 'Nothing over a limit is waiting for you.', ['Open approvals', '/approvals']],
            'cash_position' => ['Cash position', '/bank', 'No bank account is set up.', ['Add a bank account', '/bank']],
            'recent_reversals' => ['Recent reversals and adjustments', '/accounting/journals', 'No reversals or adjustments in the last 30 days.', ['Open journals', '/accounting/journals']],
            'period_reopens' => ['Period reopen events', '/close', 'No period has been reopened.', ['Open the close', '/close']],
            'control_manual_postings' => ['Control-account manual postings', '/accounting/journals', 'No manual postings to control accounts.', ['Open journals', '/accounting/journals']],
            default => throw new \InvalidArgumentException("Unknown work queue {$key}."),
        };
        $block = ['key' => $key, 'title' => $title, 'href' => $href, 'empty' => $empty, 'emptyAction' => ['label' => $action[0], 'href' => $action[1]], 'count' => 0, 'columns' => [], 'rows' => []];

        return match ($key) {
            'claim_approvals', 'approvals_over_threshold' => $this->approvalBlock($block, $this->approvals($userId, $key === 'claim_approvals')),
            'close_progress' => $this->closeBlock($block),
            'cash_position' => $this->cashBlock($block, $today),
            default => [...$block, ...$this->rows($key, $userId)],
        };
    }

    /** @return array{count: int, columns: list<array{id: string, label: string, type: string}>, rows: list<array{href: string|null, cells: array<string, string|null>}>} */
    private function rows(string $key, string $userId): array
    {
        $query = $this->query($key, $userId);
        $count = (clone $query)->count();
        [$columns, $map] = $this->shape($key);
        $rows = [];
        foreach ($query->limit(self::TOP)->get() as $row) {
            $rows[] = $map($row);
        }

        return ['count' => $count, 'columns' => $columns, 'rows' => $rows];
    }

    private function query(string $key, string $userId): Builder
    {
        $today = app(BusinessClock::class)->today();
        $unpaid = 'i.amount_minor - i.paid_minor - i.cancelled_minor';

        return match ($key) {
            'installments_due' => $this->within($userId, PolicyPageController::AREA, DB::table('installments as i'), 'p')->join('policies as p', 'p.id', '=', 'i.policy_id')->leftJoin('parties as h', 'h.id', '=', 'i.payer_party_id')
                ->whereIn('p.status', ['issued', 'active'])->whereRaw("{$unpaid} > 0")->whereBetween('i.due_date', [$today->toDateString(), $today->addDays(7)->toDateString()])
                ->orderBy('i.due_date')->select(['p.id', 'p.number', 'h.display_name', 'i.due_date', 'p.currency', DB::raw("{$unpaid} as outstanding")]),
            'lapsing_policies' => $this->within($userId, PolicyPageController::AREA, DB::table('policies as p'), 'p')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')
                ->joinSub(DB::table('installments as i')->whereRaw("{$unpaid} > 0")->groupBy('i.policy_id')->select(['i.policy_id', DB::raw('min(i.due_date) as oldest_due')]), 'o', 'o.policy_id', '=', 'p.id')
                ->whereIn('p.status', ['issued', 'active'])->where('o.oldest_due', '<=', $today->addDays(14)->subDays((int) config('erp.collections.grace_days', 30))->toDateString())
                ->orderBy('o.oldest_due')->select(['p.id', 'p.number', 'h.display_name', 'o.oldest_due']),
            'receipts_to_record', 'unmatched_bank_lines' => DB::table('bank_statement_lines as l')->join('bank_accounts as b', 'b.id', '=', 'l.bank_account_id')->where('l.match_status', 'unmatched')
                ->when($key === 'receipts_to_record', fn (Builder $q) => $q->where('l.amount_minor', '>', 0))
                ->orderBy('l.posted_on')->select(['l.id', 'l.bank_account_id', 'l.posted_on', 'l.reference', 'l.description', 'l.amount_minor', 'b.currency', 'b.bank_name']),
            // Issued quotations still valid (Phase 3 quote workbench) and Phase 1 policy quotes, earliest cover start first.
            'quotes' => DB::query()->fromSub($this->within($userId, QuotationPageController::AREA, DB::table('quotations as q'), 'q')->leftJoin('parties as h', 'h.id', '=', 'q.customer_party_id')->where('q.status', 'issued')
                ->where('q.valid_until', '>=', $today->toDateString())
                ->select([DB::raw("'quotation' as kind"), 'q.id', 'q.number', DB::raw("coalesce(h.display_name, '') as display_name"), 'q.inception', 'q.gross_premium_minor', 'q.currency'])
                ->unionAll($this->within($userId, PolicyPageController::AREA, DB::table('policies as p'), 'p')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')->where('p.status', 'quote')
                    ->select([DB::raw("'policy' as kind"), 'p.id', DB::raw('null as number'), 'h.display_name', 'p.inception', 'p.gross_premium_minor', 'p.currency'])), 'f')
                ->orderBy('inception')->select(['kind', 'id', 'number', 'display_name', 'inception', 'gross_premium_minor', 'currency']),
            'unallocated_receipts' => $this->within($userId, CollectionsPageController::AREA, DB::table('suspense_items as s'), 'r')->join('receipts as r', 'r.id', '=', 's.receipt_id')->where('s.status', 'open')
                ->orderBy('s.aged_since')->select(['s.id', 'r.id as receipt_id', 'r.number', 'r.reference', 's.aged_since', 'r.currency', DB::raw('s.amount_minor - s.allocated_minor as open_minor')]),
            'journals_to_approve' => DB::table('journals as j')->where('j.status', 'pending_approval')->where('j.created_by', '<>', $userId)
                ->when(! $this->permissions->has($userId, 'accounting.approve_journal'), fn (Builder $q) => $q->whereRaw('false'))
                ->orderBy('j.created_at')->select(['j.id', 'j.transaction_date', 'j.description', 'j.currency',
                    DB::raw("(select coalesce(sum(amount_minor), 0) from journal_lines l where l.journal_id = j.id and l.side = 'debit') as total_minor")]),
            'failed_events' => DB::table('accounting_events')->where('status', 'failed')->orderByDesc('created_at')->select(['id', 'event_type', 'transaction_date', 'failure_reason']),
            'claims_awaiting_reserve' => $this->within($userId, ClaimPageController::AREA, DB::table('claims as c'), 'c')->join('policies as p', 'p.id', '=', 'c.policy_id')->where('c.status', 'registered')->where('c.reserve_minor', 0)
                ->orderBy('c.reported_on')->select(['c.id', 'c.number', 'p.number as policy_number', 'c.description', 'c.reported_on']),
            // Reserved claims with nothing committed against the reserve yet: the settlement decision (approve a payment) is still to make.
            'claims_to_settle' => $this->within($userId, ClaimPageController::AREA, DB::table('claims as c'), 'c')->join('policies as p', 'p.id', '=', 'c.policy_id')->where('c.status', 'reserved')->where('c.reserve_minor', '>', 0)
                ->whereNotExists(fn (Builder $q) => $q->from('claim_payments as cp')->whereColumn('cp.claim_id', 'c.id')->whereIn('cp.status', ClaimPaymentStatus::committed()))
                ->orderBy('c.reported_on')->orderBy('c.number')->select(['c.id', 'c.number', 'p.number as policy_number', 'c.reserve_minor', 'c.currency', 'c.reported_on']),
            // Someone who releases but does not request releases (finance) only has the requested ones to act on.
            'payments_to_release' => $this->within($userId, ClaimPageController::AREA, DB::table('claim_payments as cp'), 'c')->join('claims as c', 'c.id', '=', 'cp.claim_id')
                ->whereIn('cp.status', $this->permissions->has($userId, 'claim.pay_release') && ! $this->permissions->has($userId, 'claim.pay_request') ? ['release_requested'] : ['approved', 'release_requested'])
                ->orderBy('cp.approved_on')->select(['c.id', 'c.number', 'cp.status', 'cp.approved_on', 'cp.amount_minor', 'cp.currency']),
            'reconciliation_variances' => DB::table('reconciliation_runs as r')->join('fiscal_periods as f', 'f.id', '=', 'r.period_id')->where('r.status', 'variance')
                ->orderByDesc('r.run_at')->select(['r.id', 'r.subledger', 'f.starts', 'r.variance_minor']),
            'recent_reversals' => DB::table('journals as j')->whereIn('j.kind', ['reversal', 'adjustment'])->where('j.posting_date', '>=', $today->subDays(30)->toDateString())
                ->orderByDesc('j.posting_date')->select(['j.id', 'j.number', 'j.kind', 'j.posting_date', 'j.reason']),
            'period_reopens' => DB::table('audit_events as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')->whereIn('a.action', ['period.reopened', 'period.reopen_requested'])
                ->orderByDesc('a.occurred_at')->select(['a.id', 'a.action', 'a.occurred_at', 'a.reason', 'u.name']),
            'control_manual_postings' => DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('a.is_control', true)->whereIn('j.kind', ['manual', 'adjustment'])->where('j.status', 'posted')
                ->orderByDesc('j.posting_date')->select(['j.id', 'j.number', 'j.posting_date', 'a.code', 'a.name', 'l.side', 'l.amount_minor', 'j.currency']),
            default => throw new \InvalidArgumentException("Unknown work queue {$key}."),
        };
    }

    /**
     * Follow-up H1 (design §7.2, D-43): a branch-bound queue lists only the rows the user could open — those within their reach for the area of the page the
     * row links to ($alias names the table carrying entity_id and branch_id). Tenant-wide users are unchanged.
     *
     * @param list<string> $area
     */
    private function within(string $userId, array $area, Builder $query, string $alias): Builder
    {
        $key = $userId.'|'.implode(',', $area);
        $this->reaches[$key] ??= $this->permissions->reach($userId, $area);

        return $this->reaches[$key]->constrain($query, "{$alias}.entity_id", "{$alias}.branch_id");
    }

    /** @return array{0: list<array{id: string, label: string, type: string}>, 1: callable(\stdClass): array{href: string|null, cells: array<string, string|null>}} */
    private function shape(string $key): array
    {
        $money = fn (\stdClass $row, string $field): string => PageSupport::money((int) $row->{$field}, (string) $row->currency);
        $col = fn (string $id, string $label, string $type = 'text'): array => ['id' => $id, 'label' => $label, 'type' => $type];

        return match ($key) {
            'installments_due' => [[$col('policy', 'Policy'), $col('payer', 'Payer'), $col('due', 'Due', 'date'), $col('amount', 'Outstanding', 'money')],
                fn (\stdClass $r): array => ['href' => "/policies/{$r->id}", 'cells' => ['policy' => $r->number, 'payer' => $r->display_name, 'due' => $r->due_date, 'amount' => $money($r, 'outstanding')]]],
            'lapsing_policies' => [[$col('policy', 'Policy'), $col('holder', 'Policyholder'), $col('oldest', 'Oldest unpaid', 'date'), $col('lapses', 'Lapses', 'date')],
                fn (\stdClass $r): array => ['href' => "/policies/{$r->id}", 'cells' => ['policy' => $r->number, 'holder' => $r->display_name, 'oldest' => $r->oldest_due,
                    'lapses' => CarbonImmutable::parse((string) $r->oldest_due)->addDays((int) config('erp.collections.grace_days', 30))->toDateString()]]],
            'receipts_to_record', 'unmatched_bank_lines' => [[$col('date', 'Date', 'date'), $col('bank', 'Bank'), $col('reference', 'Reference'), $col('amount', 'Amount', 'money')],
                fn (\stdClass $r): array => ['href' => "/bank/{$r->bank_account_id}", 'cells' => ['date' => $r->posted_on, 'bank' => $r->bank_name, 'reference' => $r->reference ?? $r->description, 'amount' => $money($r, 'amount_minor')]]],
            'quotes' => [[$col('number', 'Quote'), $col('holder', 'Customer'), $col('inception', 'Starts', 'date'), $col('premium', 'Premium', 'money')],
                fn (\stdClass $r): array => ['href' => $r->kind === 'quotation' ? "/quotations/{$r->id}" : "/policies/{$r->id}",
                    'cells' => ['number' => $r->number ?? 'Policy quote', 'holder' => $r->display_name, 'inception' => $r->inception, 'premium' => $money($r, 'gross_premium_minor')]]],
            'unallocated_receipts' => [[$col('receipt', 'Receipt'), $col('reference', 'Reference'), $col('since', 'Waiting since', 'date'), $col('amount', 'Unallocated', 'money')],
                fn (\stdClass $r): array => ['href' => "/receipts/{$r->receipt_id}", 'cells' => ['receipt' => $r->number, 'reference' => $r->reference, 'since' => $r->aged_since, 'amount' => $money($r, 'open_minor')]]],
            'journals_to_approve' => [[$col('description', 'Description'), $col('date', 'Date', 'date'), $col('amount', 'Amount', 'money')],
                fn (\stdClass $r): array => ['href' => "/accounting/journals/{$r->id}", 'cells' => ['description' => $r->description, 'date' => $r->transaction_date, 'amount' => $money($r, 'total_minor')]]],
            'failed_events' => [[$col('event', 'Event'), $col('date', 'Date', 'date'), $col('reason', 'Why it failed')],
                fn (\stdClass $r): array => ['href' => null, 'cells' => ['event' => $r->event_type, 'date' => $r->transaction_date, 'reason' => $r->failure_reason]]],
            'claims_awaiting_reserve' => [[$col('claim', 'Claim'), $col('policy', 'Policy'), $col('description', 'What happened'), $col('reported', 'Reported', 'date')],
                fn (\stdClass $r): array => ['href' => "/claims/{$r->id}", 'cells' => ['claim' => $r->number, 'policy' => $r->policy_number, 'description' => $r->description, 'reported' => $r->reported_on]]],
            'claims_to_settle' => [[$col('claim', 'Claim'), $col('policy', 'Policy'), $col('reported', 'Reported', 'date'), $col('reserve', 'Reserve', 'money')],
                fn (\stdClass $r): array => ['href' => "/claims/{$r->id}", 'cells' => ['claim' => $r->number, 'policy' => $r->policy_number, 'reported' => $r->reported_on, 'reserve' => $money($r, 'reserve_minor')]]],
            'payments_to_release' => [[$col('claim', 'Claim'), $col('status', 'Status', 'status'), $col('approved', 'Approved', 'date'), $col('amount', 'Amount', 'money')],
                fn (\stdClass $r): array => ['href' => "/claims/{$r->id}", 'cells' => ['claim' => $r->number, 'status' => $r->status, 'approved' => $r->approved_on, 'amount' => $money($r, 'amount_minor')]]],
            'reconciliation_variances' => [[$col('subledger', 'Subledger'), $col('period', 'Period'), $col('variance', 'Variance', 'money')],
                fn (\stdClass $r): array => ['href' => '/close', 'cells' => ['subledger' => ucfirst((string) $r->subledger), 'period' => CarbonImmutable::parse((string) $r->starts)->format('M Y'),
                    'variance' => PageSupport::money((int) $r->variance_minor, 'BDT')]]],
            'recent_reversals' => [[$col('journal', 'Journal'), $col('kind', 'Kind'), $col('date', 'Date', 'date'), $col('reason', 'Reason')],
                fn (\stdClass $r): array => ['href' => "/accounting/journals/{$r->id}", 'cells' => ['journal' => $r->number, 'kind' => ucfirst((string) $r->kind), 'date' => $r->posting_date, 'reason' => $r->reason]]],
            'period_reopens' => [[$col('what', 'What happened'), $col('who', 'By'), $col('when', 'When', 'date'), $col('reason', 'Reason')],
                fn (\stdClass $r): array => ['href' => '/close', 'cells' => ['what' => $r->action === 'period.reopened' ? 'Period reopened' : 'Reopen requested', 'who' => $r->name, 'when' => substr((string) $r->occurred_at, 0, 10), 'reason' => $r->reason]]],
            'control_manual_postings' => [[$col('journal', 'Journal'), $col('account', 'Account'), $col('date', 'Date', 'date'), $col('amount', 'Amount', 'money')],
                fn (\stdClass $r): array => ['href' => "/accounting/journals/{$r->id}", 'cells' => ['journal' => $r->number, 'account' => "{$r->code} {$r->name}", 'date' => $r->posting_date,
                    'amount' => ($r->side === 'credit' ? '-' : '').$money($r, 'amount_minor')]]],
            default => throw new \InvalidArgumentException("Unknown work queue {$key}."),
        };
    }

    /** @return list<array<string, mixed>> */
    private function approvals(string $userId, bool $claimsOnly): array
    {
        return array_values(array_filter($this->inbox->decidableBy($userId), fn (array $a): bool => ! $claimsOnly || str_starts_with((string) $a['object_type'], 'claim')));
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $approvals
     * @return array<string, mixed>
     */
    private function approvalBlock(array $block, array $approvals): array
    {
        $rows = [];
        foreach (array_slice($approvals, 0, self::TOP) as $a) {
            $amount = $a['amount_minor'] === null ? null : PageSupport::money((int) $a['amount_minor'], (string) $a['currency']);
            $rows[] = ['href' => $a['link'], 'cells' => ['title' => $a['title'], 'by' => $a['requested_by'], 'step' => "Step {$a['step']}", 'amount' => $amount]];
        }

        return [...$block, 'count' => count($approvals), 'rows' => $rows, 'columns' => [
            ['id' => 'title', 'label' => 'Waiting for approval', 'type' => 'text'], ['id' => 'by', 'label' => 'Requested by', 'type' => 'text'],
            ['id' => 'step', 'label' => 'Over limit', 'type' => 'text'], ['id' => 'amount', 'label' => 'Amount', 'type' => 'money']]];
    }

    private function closeRun(): ?\stdClass
    {
        return DB::table('period_close_runs as r')->join('fiscal_periods as f', 'f.id', '=', 'r.period_id')->where('r.status', 'running')->orderBy('f.starts')->first(['r.id', 'f.starts']);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function closeBlock(array $block): array
    {
        $run = $this->closeRun();
        if ($run === null) {
            return $block;
        }
        $tasks = DB::table('period_close_tasks')->where('close_run_id', $run->id)->orderBy('order_no')->get(['code', 'owner_role', 'status']);
        $rows = [];
        foreach ($tasks->whereNotIn('status', ['done', 'skipped'])->take(self::TOP) as $task) {
            $rows[] = ['href' => "/close/runs/{$run->id}", 'cells' => ['task' => ucfirst(str_replace('_', ' ', (string) $task->code)), 'owner' => ucwords(str_replace('_', ' ', (string) $task->owner_role)), 'status' => $task->status]];
        }
        $done = $tasks->whereIn('status', ['done', 'skipped'])->count();

        return [...$block, 'title' => 'Close progress · '.CarbonImmutable::parse((string) $run->starts)->format('M Y'), 'href' => "/close/runs/{$run->id}",
            'count' => $tasks->count() - $done, 'progress' => ['done' => $done, 'total' => $tasks->count()], 'rows' => $rows,
            'columns' => [['id' => 'task', 'label' => 'Task', 'type' => 'text'], ['id' => 'owner', 'label' => 'Owner', 'type' => 'text'], ['id' => 'status', 'label' => 'Status', 'type' => 'status']]];
    }

    /**
     * Brief §5 "Cash position (single number + 30-day bars)": the balance of every bank ledger account and its daily net movement.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function cashBlock(array $block, CarbonImmutable $today): array
    {
        $accounts = DB::table('bank_accounts')->pluck('gl_account_id')->merge(DB::table('account_role_mappings')->where('role_code', 'bank_main')->pluck('account_id'))->unique()->values()->all();
        $currency = (string) (DB::table('legal_entities')->value('base_currency') ?? 'BDT');
        $signed = "case when l.side = 'debit' then l.base_amount_minor else -l.base_amount_minor end";
        $lines = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->whereIn('l.account_id', $accounts)->whereIn('j.status', ['posted', 'reversed']);
        $balance = (int) (clone $lines)->sum(DB::raw($signed));
        $from = $today->subDays(29);
        $daily = [];
        foreach ((clone $lines)->where('j.posting_date', '>=', $from->toDateString())->groupBy('j.posting_date')->get(['j.posting_date', DB::raw("sum({$signed}) as net")]) as $day) {
            $daily[substr((string) $day->posting_date, 0, 10)] = (int) $day->net;
        }
        $days = [];
        for ($d = 0; $d < 30; $d++) {
            $date = $from->addDays($d)->toDateString();
            $days[] = ['date' => $date, 'net' => PageSupport::money($daily[$date] ?? 0, $currency)];
        }

        return [...$block, 'count' => $accounts === [] ? 0 : 1, 'cash' => ['balance' => PageSupport::money($balance, $currency), 'currency' => $currency, 'days' => $days]];
    }
}
