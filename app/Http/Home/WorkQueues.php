<?php

declare(strict_types=1);

namespace App\Http\Home;

use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Application\Events\StuckAccountingEvents;
use App\Http\Distribution\ProducersPageController;
use App\Modules\Insurance\Claims\Domain\Enums\ClaimPaymentStatus;
use App\Modules\Insurance\Claims\Http\Controllers\ClaimPageController;
use App\Modules\Insurance\Collections\Http\Controllers\CollectionsPageController;
use App\Modules\Insurance\CoverNote\Http\Controllers\CoverNotesPageController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController;
use App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController;
use App\Modules\Insurance\Renewal\Application\ExpiryRegister;
use App\Modules\Insurance\Renewal\Domain\ExpiryRegisterStatus;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingDecisions;
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
        // Gap fix GA-14: 'bounced_premium' (policies in force whose premium cheque bounced and is still unpaid) for the branch and the accountant.
        // GA-26: overdue premium, renewals due, cover notes ending and agent cash not deposited are worked at the branch (receipt.create, renewal.manage,
        // cover_note.issue; an agent's deposit is recorded with receipt.create).
        'branch_officer' => ['installments_due', 'overdue_premium', 'lapsing_policies', 'receipts_to_record', 'quotes', 'renewals_due', 'cover_notes_expiring', 'agent_cash_undeposited', 'bounced_premium'],
        // GA-03 (D-65): the branch manager allocates the premium officers record into suspense for a policy. GA-26: and decides referrals (underwriting.decide,
        // A-86) and keeps producer licences current (agent.manage).
        'branch_manager' => ['installments_due', 'overdue_premium', 'lapsing_policies', 'receipts_to_record', 'quotes', 'receipts_to_allocate', 'referrals', 'renewals_due',
            'cover_notes_expiring', 'agent_cash_undeposited', 'licences_expiring', 'bounced_premium'],
        // GA-13: journals_to_approve becomes journals_submitted for someone who cannot approve journals (the accountant template, §7.2) — see keysFor.
        // GA-26: the accountant pays approved commission statements (commission.pay, A-138).
        'accountant' => ['unallocated_receipts', 'unmatched_bank_lines', 'journals_to_approve', 'failed_events', 'commission_to_pay', 'bounced_premium'],
        'claims_officer' => ['claims_awaiting_reserve', 'claim_approvals', 'payments_to_release'],
        // Flow fix X3: the claims manager decides settlements of reserved claims; finance releases requested claim payments.
        'claims_manager' => ['claims_awaiting_reserve', 'claims_to_settle', 'claim_approvals', 'payments_to_release'],
        // GA-08: the finance manager and CFO requeue accounting events that did not post, so they see them too.
        // Slices 2.3/2.4: the finance manager approves supplier bills and payment runs; the CFO releases approved runs.
        // GA-26: they also decide referrals (A-86) and release refunds (receipt.refund_release).
        'finance_manager' => ['close_progress', 'reconciliation_variances', 'approvals_over_threshold', 'cash_position', 'payments_to_release', 'failed_events', 'referrals', 'refunds_to_release', 'bills_to_approve', 'payment_runs_to_approve'],
        'cfo' => ['close_progress', 'reconciliation_variances', 'approvals_over_threshold', 'cash_position', 'payments_to_release', 'failed_events', 'referrals', 'refunds_to_release'],
        'auditor' => ['recent_reversals', 'period_reopens', 'control_manual_postings'],
    ];

    /** Sidebar badge → the queue whose count it shows (lib/navigation.ts badge keys). */
    private const BADGES = ['receipts' => 'installments_due', 'policies' => 'lapsing_policies', 'bank' => ['receipts_to_record', 'unmatched_bank_lines'],
        'suspense' => 'unallocated_receipts', 'journals' => 'journals_to_approve', 'claims' => ['claims_awaiting_reserve', 'claims_to_settle', 'payments_to_release'], 'close' => 'reconciliation_variances'];

    private const TOP = 5;

    /** @var array<string, AreaReach> */
    private array $reaches = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $decidable = [];

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
        // GA-13: "Journals awaiting my approval" was always empty for someone who cannot approve; they follow the journals they submitted instead.
        if (in_array('journals_to_approve', $keys, true) && ! $this->permissions->has($userId, 'accounting.approve_journal')) {
            $keys = array_map(fn (string $key): string => $key === 'journals_to_approve' ? 'journals_submitted' : $key, $keys);
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
            'claim_approvals', 'approvals_over_threshold' => count($this->approvals($userId, $key)),
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
            // GA-26: installments already past their due date; "due this week" and "lapsing" left them out until they were close to lapsing.
            'overdue_premium' => ['Overdue premium', '/receipts/create', 'No premium is overdue.', ['Record a receipt', '/receipts/create']],
            'referrals' => ['Referrals waiting for my decision', '/underwriting/referrals?f.status=submitted', 'No referral is waiting for your decision.', ['Open referrals', '/underwriting/referrals']],
            'renewals_due' => ['Renewals due', '/renewals', 'No policy is due for renewal in the next '.max(ExpiryRegister::buckets()).' days.', ['Open renewals', '/renewals']],
            'cover_notes_expiring' => ['Cover notes ending', '/cover-notes?within='.self::coverNoteDays(), 'No cover note ends in the next '.self::coverNoteDays().' days.', ['Open cover notes', '/cover-notes']],
            'agent_cash_undeposited' => ['Agent cash not deposited', '/agent-cash', 'No agent holds cash that is not deposited.', ['Open agent cash', '/agent-cash']],
            'licences_expiring' => ['Producer licences expiring', '/distribution/producers?f.attention='.rawurlencode('Licence expiring'), 'No producer licence expires in the next '.self::licenceDays().' days.', ['Open producers', '/distribution/producers']],
            'refunds_to_release' => ['Refunds to release', '/refunds?f.status=requested', 'No refund is waiting to be released.', ['Open refunds', '/refunds']],
            'commission_to_pay' => ['Commission to pay', '/distribution/statements?f.status=approved', 'No approved commission statement is waiting to be paid.', ['Open commission statements', '/distribution/statements']],
            'quotes' => ['Quotes to follow up', '/quotations', 'No open quotes.', ['New quote', '/quotations/create']],
            'receipts_to_allocate' => ['Receipts to allocate', '/suspense', 'No receipt taken for a policy is waiting to be allocated.', ['Record a receipt', '/receipts/create']],
            'bounced_premium' => ['Policies with bounced premium', '/cheques', 'No policy in force has an unpaid bounced cheque.', ['Open the cheque register', '/cheques']],
            'unallocated_receipts' => ['Unallocated receipts', '/suspense', 'No unallocated receipts.', ['Import a bank statement', '/bank']],
            'unmatched_bank_lines' => ['Unmatched bank lines', '/bank', 'Every statement line is matched or explained.', ['Import a bank statement', '/bank']],
            'journals_submitted' => ['Journals I submitted', '/accounting/journals?f.status=pending_approval', 'None of your journals is in draft, waiting for approval or recently rejected.', ['New manual journal', '/accounting/journals/create']],
            'journals_to_approve' => ['Journals awaiting my approval', '/accounting/journals?f.status=pending_approval', 'No journals are waiting for you.', ['Open journals', '/accounting/journals']],
            'failed_events' => ['Failed accounting events', '/accounting/events', 'Every accounting event posted.', ['Open journals', '/accounting/journals']],
            'claims_awaiting_reserve' => ['Claims awaiting reserve', '/claims?f.status=registered', 'Every open claim has a reserve.', ['Register a claim', '/claims/create']],
            'claims_to_settle' => ['Claims to settle', '/claims?f.status=reserved', 'No reserved claim is waiting for a settlement decision.', ['Open claims', '/claims']],
            'claim_approvals' => ['Awaiting my approval', '/approvals', 'No claim approvals are waiting for you.', ['Open claims', '/claims']],
            // GA-26: the claim payments queue, filtered to what this user releases.
            'payments_to_release' => ['Payments to release', $this->paymentsReleasedOnly($userId) ? '/claims/payments?f.status=release_requested' : '/claims/payments', 'No approved payments are waiting to be paid.', ['Open claim payments', '/claims/payments']],
            'close_progress' => ['Close progress', '/close', 'No month-end close is running.', ['Start the close', '/close']],
            'reconciliation_variances' => ['Reconciliation variances', '/close', 'Every subledger reconciles to the ledger.', ['Open the close', '/close']],
            // GA-26: every approval this user may decide (a manual journal needs one whatever its amount), so not "over threshold".
            'approvals_over_threshold' => ['Waiting for my approval', '/approvals', 'Nothing over a limit is waiting for you.', ['Open approvals', '/approvals']],
            'cash_position' => ['Cash position', '/bank', 'No bank account is set up.', ['Add a bank account', '/bank']],
            'recent_reversals' => ['Recent reversals and adjustments', '/accounting/journals', 'No reversals or adjustments in the last 30 days.', ['Open journals', '/accounting/journals']],
            'period_reopens' => ['Period reopen events', '/close', 'No period has been reopened.', ['Open the close', '/close']],
            'control_manual_postings' => ['Control-account manual postings', '/accounting/journals', 'No manual postings to control accounts.', ['Open journals', '/accounting/journals']],
            'bills_to_approve' => ['Bills to approve', '/payables/bills?view=awaiting_approval', 'No supplier bill is waiting for your approval.', ['Open supplier bills', '/payables/bills']],
            'payment_runs_to_approve' => ['Payment runs to approve', '/payables/payment-runs', 'No payment run is waiting for your approval.', ['Open payment runs', '/payables/payment-runs']],
            'payment_runs_to_release' => ['Payment runs to release', '/payables/payment-runs', 'No approved payment run is waiting to be released.', ['Open payment runs', '/payables/payment-runs']],
            default => throw new \InvalidArgumentException("Unknown work queue {$key}."),
        };
        $block = ['key' => $key, 'title' => $title, 'href' => $href, 'empty' => $empty, 'emptyAction' => ['label' => $action[0], 'href' => $action[1]], 'count' => 0, 'columns' => [], 'rows' => []];

        $filled = match ($key) {
            'claim_approvals', 'approvals_over_threshold' => $this->approvalBlock($block, $this->approvals($userId, $key)),
            'close_progress' => $this->closeBlock($block),
            'cash_position' => $this->cashBlock($block, $today),
            default => [...$block, ...$this->rows($key, $userId)],
        };

        return $key === 'receipts_to_record' ? $this->receiptsToRecordLinks($filled, $userId) : $filled;
    }

    /**
     * GA-07: branch roles cannot open the bank screens, so their "Receipts to record" opens the receipt form filled in from the statement line.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function receiptsToRecordLinks(array $block, string $userId): array
    {
        if (array_intersect(\App\Modules\Finance\Bank\Http\Controllers\BankPageController::AREA, $this->permissions->permissionsOf($userId)) !== []) {
            return $block;
        }
        $rows = is_array($block['rows'] ?? null) ? array_values($block['rows']) : [];
        $lineIds = $this->query('receipts_to_record', $userId)->limit(self::TOP)->pluck('l.id')->map(fn (mixed $id): string => (string) $id)->values()->all();
        foreach ($rows as $index => $row) {
            $rows[$index] = [...(array) $row, 'href' => '/receipts/create?statement_line='.($lineIds[$index] ?? '')];
        }

        return [...$block, 'href' => '/receipts/create', 'emptyAction' => ['label' => 'Record a receipt', 'href' => '/receipts/create'], 'rows' => $rows];
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
            'overdue_premium' => $this->within($userId, PolicyPageController::AREA, DB::table('installments as i'), 'p')->join('policies as p', 'p.id', '=', 'i.policy_id')->leftJoin('parties as h', 'h.id', '=', 'i.payer_party_id')
                ->whereIn('p.status', ['issued', 'active'])->whereRaw("{$unpaid} > 0")->where('i.due_date', '<', $today->toDateString())
                ->orderBy('i.due_date')->orderBy('p.number')->select(['p.id', 'p.number', 'h.display_name', 'i.due_date', 'p.currency', DB::raw("{$unpaid} as outstanding")]),
            // Referred proposals of the user's branches whose current approval step this user may decide (the approvals inbox; never their own).
            'referrals' => $this->within($userId, [UnderwritingDecisions::PERMISSION], DB::table('proposals as pr'), 'pr')->leftJoin('parties as c', 'c.id', '=', 'pr.customer_party_id')
                ->where('pr.status', 'submitted')->whereIn('pr.id', array_column(array_filter($this->inbox($userId), fn (array $a): bool => $a['object_type'] === ProposalService::REFERRAL), 'object_id'))
                ->orderBy('pr.submitted_at')->select(['pr.id', 'pr.number', 'c.display_name', 'pr.submitted_at', 'pr.gross_premium_minor', 'pr.currency']),
            // Open expiry register entries (upcoming or offered) expiring from today to the register's largest bucket.
            'renewals_due' => $this->within($userId, [ExpiryRegister::PERMISSION], DB::table('expiry_register as r'), 'r')->leftJoin('parties as c', 'c.id', '=', 'r.policyholder_party_id')
                ->whereIn('r.status', ExpiryRegisterStatus::open())->whereBetween('r.expiry', [$today->toDateString(), $today->addDays(max(ExpiryRegister::buckets()))->toDateString()])
                ->orderBy('r.expiry')->orderBy('r.policy_number')->select(['r.policy_id', 'r.policy_number', 'c.display_name', 'r.expiry', 'r.status']),
            'cover_notes_expiring' => $this->within($userId, CoverNotesPageController::AREA, DB::table('cover_notes as n'), 'n')->join('proposals as pr', 'pr.id', '=', 'n.proposal_id')
                ->leftJoin('parties as c', 'c.id', '=', 'pr.customer_party_id')->where('n.status', 'active')
                ->whereBetween('n.valid_to', [$today->toDateString(), $today->addDays(self::coverNoteDays())->toDateString()])
                ->orderBy('n.valid_to')->orderBy('n.number')->select(['n.id', 'n.number', 'c.display_name', 'n.valid_to', 'pr.id as proposal_id']),
            // Cash an agent collected and has not deposited (AgentCashPositionQuery::undepositedMinor), per agent of the user's branches (A-159).
            'agent_cash_undeposited' => $this->withinColumns($userId, CollectionsPageController::AREA, DB::table('producers as a')->join('branches as br', 'br.id', '=', 'a.branch_id'), 'br.entity_id', 'a.branch_id')
                ->leftJoin('parties as ap', 'ap.id', '=', 'a.party_id')
                ->joinSub(DB::table('receipts')->whereNotNull('collected_by_agent_id')->where('channel', 'cash')->where('status', '<>', 'bounced')->groupBy('collected_by_agent_id', 'currency')
                    ->select(['collected_by_agent_id', 'currency', DB::raw('sum(amount_minor) as collected')]), 'col', 'col.collected_by_agent_id', '=', 'a.id')
                ->leftJoinSub(DB::table('agent_deposits')->groupBy('agent_id')->select(['agent_id', DB::raw('sum(amount_minor) as deposited')]), 'dep', 'dep.agent_id', '=', 'a.id')
                ->whereRaw('col.collected - coalesce(dep.deposited, 0) > 0')->orderBy('a.code')
                ->select(['a.id', 'a.code', 'ap.display_name', 'col.currency', DB::raw('col.collected - coalesce(dep.deposited, 0) as undeposited_minor')]),
            'licences_expiring' => $this->withinColumns($userId, ProducersPageController::AREA, DB::table('producer_licences as l')->join('producers as a', 'a.id', '=', 'l.producer_id')
                ->join('branches as br', 'br.id', '=', 'a.branch_id'), 'br.entity_id', 'a.branch_id')->leftJoin('parties as ap', 'ap.id', '=', 'a.party_id')
                ->where('l.status', 'active')->whereBetween('l.expires_on', [$today->toDateString(), $today->addDays(self::licenceDays())->toDateString()])
                ->orderBy('l.expires_on')->orderBy('a.code')->select(['a.id', 'a.code', 'ap.display_name', 'l.licence_no', 'l.expires_on']),
            // Requested refunds someone else asked for (maker ≠ checker, non-negotiable #9), in the branches where this user releases refunds.
            'refunds_to_release' => $this->within($userId, ['receipt.refund_release'], DB::table('refunds as r'), 'r')->leftJoin('policies as p', 'p.id', '=', 'r.policy_id')
                ->where('r.status', 'requested')->where('r.requested_by', '<>', $userId)
                ->orderBy('r.requested_at')->select(['r.id', 'p.number', 'r.reason', 'r.requested_at', 'r.amount_minor', 'r.currency']),
            // Approved statements not paid yet that this user did not approve (commission.approve ✕ commission.pay). Statements carry no branch.
            'commission_to_pay' => DB::table('commission_statements as s')->join('producers as a', 'a.id', '=', 's.agent_id')->leftJoin('parties as ap', 'ap.id', '=', 'a.party_id')
                ->where('s.status', 'approved')->where('s.approved_by', '<>', $userId)
                ->orderBy('s.approved_on')->orderBy('s.number')->select(['s.id', 's.number', 'a.code', 'ap.display_name', 's.period_end', 's.approved_on', 's.net_minor', 's.currency']),
            'lapsing_policies' => $this->within($userId, PolicyPageController::AREA, DB::table('policies as p'), 'p')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')
                ->joinSub(DB::table('installments as i')->whereRaw("{$unpaid} > 0")->groupBy('i.policy_id')->select(['i.policy_id', DB::raw('min(i.due_date) as oldest_due')]), 'o', 'o.policy_id', '=', 'p.id')
                ->whereIn('p.status', ['issued', 'active'])->where('o.oldest_due', '<=', $today->addDays(14)->subDays((int) config('erp.collections.grace_days', 30))->toDateString())
                ->orderBy('o.oldest_due')->select(['p.id', 'p.number', 'h.display_name', 'o.oldest_due']),
            'receipts_to_record', 'unmatched_bank_lines' => DB::table('bank_statement_lines as l')->join('bank_accounts as b', 'b.id', '=', 'l.bank_account_id')->where('l.match_status', 'unmatched')
                ->when($key === 'receipts_to_record', fn (Builder $q) => self::withoutReceipt($q->where('l.amount_minor', '>', 0)))
                ->orderBy('l.posted_on')->orderBy('l.id')->select(['l.id', 'l.bank_account_id', 'l.posted_on', 'l.reference', 'l.description', 'l.amount_minor', 'b.currency', 'b.bank_name']),
            // Issued quotations still valid (Phase 3 quote workbench) and Phase 1 policy quotes, earliest cover start first.
            'quotes' => DB::query()->fromSub($this->within($userId, QuotationPageController::AREA, DB::table('quotations as q'), 'q')->leftJoin('parties as h', 'h.id', '=', 'q.customer_party_id')->where('q.status', 'issued')
                ->where('q.valid_until', '>=', $today->toDateString())
                ->select([DB::raw("'quotation' as kind"), 'q.id', 'q.number', DB::raw("coalesce(h.display_name, '') as display_name"), 'q.inception', 'q.gross_premium_minor', 'q.currency'])
                ->unionAll($this->within($userId, PolicyPageController::AREA, DB::table('policies as p'), 'p')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')->where('p.status', 'quote')
                    ->select([DB::raw("'policy' as kind"), 'p.id', DB::raw('null as number'), 'h.display_name', 'p.inception', 'p.gross_premium_minor', 'p.currency'])), 'f')
                ->orderBy('inception')->select(['kind', 'id', 'number', 'display_name', 'inception', 'gross_premium_minor', 'currency']),
            'unallocated_receipts' => $this->within($userId, CollectionsPageController::AREA, DB::table('suspense_items as s'), 'r')->join('receipts as r', 'r.id', '=', 's.receipt_id')->where('s.status', 'open')
                ->orderBy('s.aged_since')->select(['s.id', 'r.id as receipt_id', 'r.number', 'r.reference', 's.aged_since', 'r.currency', DB::raw('s.amount_minor - s.allocated_minor as open_minor')]),
            'receipts_to_allocate' => $this->within($userId, CollectionsPageController::AREA, DB::table('suspense_items as s'), 'r')->join('receipts as r', 'r.id', '=', 's.receipt_id')->join('policies as p', 'p.id', '=', 'r.for_policy_id')
                ->where('s.status', 'open')->whereColumn('s.allocated_minor', '<', 's.amount_minor')
                ->orderBy('s.aged_since')->select(['r.id as receipt_id', 'r.number', 'p.number as policy_number', 's.aged_since', 'r.currency', DB::raw('s.amount_minor - s.allocated_minor as open_minor')]),
            'journals_to_approve' => DB::table('journals as j')->where('j.status', 'pending_approval')->where('j.created_by', '<>', $userId)
                ->when(! $this->permissions->has($userId, 'accounting.approve_journal'), fn (Builder $q) => $q->whereRaw('false'))
                ->orderBy('j.created_at')->select(['j.id', 'j.transaction_date', 'j.description', 'j.currency',
                    DB::raw("(select coalesce(sum(amount_minor), 0) from journal_lines l where l.journal_id = j.id and l.side = 'debit') as total_minor")]),
            // Gap fix GA-14, follow-up H1: limited to the branches the user's collections permissions reach.
            'bounced_premium' => $this->within($userId, CollectionsPageController::AREA, app(\App\Modules\Insurance\Collections\Application\BouncedPremiumQuery::class)->policiesInForce(), 'b'),
            // GA-13: manual journals this user prepared that are still drafts, wait for approval, or were rejected (cancelled) in the last 30 days, newest first.
            'journals_submitted' => DB::table('journals as j')->where('j.created_by', $userId)->whereIn('j.kind', ['manual', 'adjustment'])
                ->where(fn (Builder $q) => $q->whereIn('j.status', ['draft', 'pending_approval'])->orWhere(fn (Builder $c) => $c->where('j.status', 'cancelled')->where('j.created_at', '>=', $today->subDays(30))))
                ->orderByDesc('j.created_at')->select(['j.id', 'j.transaction_date', 'j.description', 'j.status', 'j.currency',
                    DB::raw("(select coalesce(sum(amount_minor), 0) from journal_lines l where l.journal_id = j.id and l.side = 'debit') as total_minor")]),
            // GA-08: failed events and events queued longer than erp.posting.stale_after_minutes (the posting worker is not running).
            'failed_events' => app(StuckAccountingEvents::class)->query(CarbonImmutable::now())->reorder()->orderByDesc('created_at')->select(['id', 'event_type', 'status', 'transaction_date', 'failure_reason']),
            'claims_awaiting_reserve' => $this->within($userId, ClaimPageController::AREA, DB::table('claims as c'), 'c')->join('policies as p', 'p.id', '=', 'c.policy_id')->where('c.status', 'registered')->where('c.reserve_minor', 0)
                ->orderBy('c.reported_on')->select(['c.id', 'c.number', 'p.number as policy_number', 'c.description', 'c.reported_on']),
            // Reserved claims with nothing committed against the reserve yet: the settlement decision (approve a payment) is still to make.
            'claims_to_settle' => $this->within($userId, ClaimPageController::AREA, DB::table('claims as c'), 'c')->join('policies as p', 'p.id', '=', 'c.policy_id')->where('c.status', 'reserved')->where('c.reserve_minor', '>', 0)
                ->whereNotExists(fn (Builder $q) => $q->from('claim_payments as cp')->whereColumn('cp.claim_id', 'c.id')->whereIn('cp.status', ClaimPaymentStatus::committed()))
                ->orderBy('c.reported_on')->orderBy('c.number')->select(['c.id', 'c.number', 'p.number as policy_number', 'c.reserve_minor', 'c.currency', 'c.reported_on']),
            // Someone who releases but does not request releases (finance) only has the requested ones to act on.
            'payments_to_release' => $this->within($userId, ClaimPageController::AREA, DB::table('claim_payments as cp'), 'c')->join('claims as c', 'c.id', '=', 'cp.claim_id')
                ->whereIn('cp.status', $this->paymentsReleasedOnly($userId) ? ['release_requested'] : ['approved', 'release_requested'])
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
            // Slices 2.3/2.4: supplier bills and payment runs someone else prepared (maker ≠ checker; the release also ≠ the approver).
            'bills_to_approve' => DB::table('ap_bills as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')->join('parties as p', 'p.id', '=', 's.party_id')
                ->where('b.status', 'pending_approval')->where('b.created_by', '<>', $userId)->where(fn (Builder $q) => $q->whereNull('b.submitted_by')->orWhere('b.submitted_by', '<>', $userId))
                ->when(! $this->permissions->has($userId, 'ap.approve_bills'), fn (Builder $q) => $q->whereRaw('false'))
                ->orderBy('b.due_date')->select(['b.id', 'b.number', 'p.display_name', 'b.due_date', 'b.payable_minor', 'b.currency']),
            'payment_runs_to_approve', 'payment_runs_to_release' => DB::table('payment_runs as r')->where('r.created_by', '<>', $userId)
                ->when($key === 'payment_runs_to_approve', fn (Builder $q) => $q->where('r.status', 'pending_approval')
                    ->when(! $this->permissions->has($userId, 'ap.approve_payments'), fn (Builder $n) => $n->whereRaw('false')))
                ->when($key === 'payment_runs_to_release', fn (Builder $q) => $q->where('r.status', 'approved')->where('r.approved_by', '<>', $userId)
                    ->when(! $this->permissions->has($userId, 'ap.release_payments'), fn (Builder $n) => $n->whereRaw('false')))
                ->orderBy('r.pay_date')->select(['r.id', 'r.number', 'r.pay_date', 'r.item_count', 'r.total_minor', 'r.currency']),
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
        return $this->withinColumns($userId, $area, $query, "{$alias}.entity_id", "{$alias}.branch_id");
    }

    /** @param list<string> $area */
    private function withinColumns(string $userId, array $area, Builder $query, string $entityColumn, string $branchColumn): Builder
    {
        $key = $userId.'|'.implode(',', $area);
        $this->reaches[$key] ??= $this->permissions->reach($userId, $area);

        return $this->reaches[$key]->constrain($query, $entityColumn, $branchColumn);
    }

    /** Someone who releases claim payments but does not request releases (finance) only has the requested ones to act on. */
    private function paymentsReleasedOnly(string $userId): bool
    {
        return $this->permissions->has($userId, 'claim.pay_release') && ! $this->permissions->has($userId, 'claim.pay_request');
    }

    /**
     * GA-26: a credit line is no receipt still to record when the bank matching screen already suggests a posted receipt for it — an unmatched debit on the
     * account's ledger account of the same amount within the auto-match window (BankMatcher::suggestions) — or when a receipt that is not bounced has the same
     * amount and a reference the line quotes (ASSUMPTION A-221).
     */
    private static function withoutReceipt(Builder $lines): Builder
    {
        $window = (int) config('erp.bank.auto_match_date_window_days', 3);

        return $lines
            ->whereNotExists(fn (Builder $q) => $q->from('journal_lines as jl')->join('journals as j', 'j.id', '=', 'jl.journal_id')
                ->whereColumn('jl.account_id', 'b.gl_account_id')->where('j.status', 'posted')->whereNull('j.reverses_journal_id')
                ->where('jl.side', 'debit')->whereColumn('jl.amount_minor', 'l.amount_minor')->whereRaw('abs(j.posting_date - l.posted_on) <= ?', [$window])
                ->whereNotExists(fn (Builder $m) => $m->from('bank_matches as m')->whereColumn('m.journal_line_id', 'jl.id')))
            ->whereNotExists(fn (Builder $q) => $q->from('receipts as r')->where('r.status', '<>', 'bounced')->whereColumn('r.amount_minor', 'l.amount_minor')
                ->whereRaw("length(trim(coalesce(r.reference, ''))) >= 3")
                ->whereRaw("position(upper(trim(r.reference)) in upper(coalesce(l.reference, '') || ' ' || coalesce(l.description, ''))) > 0")
                ->where(fn (Builder $b) => $b->whereNull('r.bank_account_id')->orWhereColumn('r.bank_account_id', 'l.bank_account_id')));
    }

    private static function coverNoteDays(): int
    {
        return max(1, (int) config('erp.cover_notes.expiring_within_days', 7));
    }

    /** The earliest licence-expiry alert (distribution design note §3), 60 days by default. */
    private static function licenceDays(): int
    {
        $days = array_map('intval', (array) config('erp.distribution.licence_alert_days', [60]));

        return $days === [] ? 60 : max($days);
    }

    /** @return array{0: list<array{id: string, label: string, type: string}>, 1: callable(\stdClass): array{href: string|null, cells: array<string, string|null>}} */
    private function shape(string $key): array
    {
        $money = fn (\stdClass $row, string $field): string => PageSupport::money((int) $row->{$field}, (string) $row->currency);
        $col = fn (string $id, string $label, string $type = 'text'): array => ['id' => $id, 'label' => $label, 'type' => $type];

        return match ($key) {
            'installments_due' => [[$col('policy', 'Policy'), $col('payer', 'Payer'), $col('due', 'Due', 'date'), $col('amount', 'Outstanding', 'money')],
                fn (\stdClass $r): array => ['href' => "/policies/{$r->id}", 'cells' => ['policy' => $r->number, 'payer' => $r->display_name, 'due' => $r->due_date, 'amount' => $money($r, 'outstanding')]]],
            'overdue_premium' => [[$col('policy', 'Policy'), $col('payer', 'Payer'), $col('due', 'Was due', 'date'), $col('amount', 'Outstanding', 'money')],
                fn (\stdClass $r): array => ['href' => "/policies/{$r->id}", 'cells' => ['policy' => $r->number, 'payer' => $r->display_name, 'due' => $r->due_date, 'amount' => $money($r, 'outstanding')]]],
            'referrals' => [[$col('proposal', 'Proposal'), $col('customer', 'Customer'), $col('submitted', 'Referred', 'date'), $col('premium', 'Gross premium', 'money')],
                fn (\stdClass $r): array => ['href' => "/proposals/{$r->id}", 'cells' => ['proposal' => $r->number, 'customer' => $r->display_name, 'submitted' => substr((string) $r->submitted_at, 0, 10), 'premium' => $money($r, 'gross_premium_minor')]]],
            'renewals_due' => [[$col('policy', 'Policy'), $col('holder', 'Policyholder'), $col('expiry', 'Expires', 'date'), $col('status', 'Status', 'status')],
                fn (\stdClass $r): array => ['href' => "/policies/{$r->policy_id}", 'cells' => ['policy' => $r->policy_number, 'holder' => $r->display_name, 'expiry' => $r->expiry, 'status' => $r->status]]],
            'cover_notes_expiring' => [[$col('note', 'Cover note'), $col('customer', 'Customer'), $col('until', 'Until', 'date')],
                fn (\stdClass $r): array => ['href' => "/proposals/{$r->proposal_id}", 'cells' => ['note' => $r->number, 'customer' => $r->display_name, 'until' => $r->valid_to]]],
            'agent_cash_undeposited' => [[$col('agent', 'Agent'), $col('name', 'Name'), $col('amount', 'Not deposited', 'money')],
                fn (\stdClass $r): array => ['href' => '/agent-cash', 'cells' => ['agent' => $r->code, 'name' => $r->display_name, 'amount' => $money($r, 'undeposited_minor')]]],
            'licences_expiring' => [[$col('producer', 'Producer'), $col('name', 'Name'), $col('licence', 'Licence'), $col('expires', 'Expires', 'date')],
                fn (\stdClass $r): array => ['href' => "/distribution/producers/{$r->id}", 'cells' => ['producer' => $r->code, 'name' => $r->display_name, 'licence' => $r->licence_no, 'expires' => $r->expires_on]]],
            'refunds_to_release' => [[$col('policy', 'Policy'), $col('reason', 'Reason'), $col('requested', 'Requested', 'date'), $col('amount', 'Amount', 'money')],
                fn (\stdClass $r): array => ['href' => '/refunds?f.status=requested', 'cells' => ['policy' => $r->number, 'reason' => $r->reason, 'requested' => substr((string) $r->requested_at, 0, 10), 'amount' => $money($r, 'amount_minor')]]],
            'commission_to_pay' => [[$col('statement', 'Statement'), $col('producer', 'Producer'), $col('approved', 'Approved', 'date'), $col('amount', 'Net', 'money')],
                fn (\stdClass $r): array => ['href' => '/distribution/statements?'.($r->period_end === null ? '' : 'period_end='.substr((string) $r->period_end, 0, 10).'&').'f.status=approved',
                    'cells' => ['statement' => $r->number, 'producer' => trim("{$r->code} {$r->display_name}"), 'approved' => $r->approved_on, 'amount' => $money($r, 'net_minor')]]],
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
            'receipts_to_allocate' => [[$col('receipt', 'Receipt'), $col('policy', 'Taken for'), $col('since', 'Waiting since', 'date'), $col('amount', 'To allocate', 'money')],
                fn (\stdClass $r): array => ['href' => "/receipts/{$r->receipt_id}/allocate", 'cells' => ['receipt' => $r->number, 'policy' => $r->policy_number, 'since' => $r->aged_since, 'amount' => $money($r, 'open_minor')]]],
            'journals_to_approve' => [[$col('description', 'Description'), $col('date', 'Date', 'date'), $col('amount', 'Amount', 'money')],
                fn (\stdClass $r): array => ['href' => "/accounting/journals/{$r->id}", 'cells' => ['description' => $r->description, 'date' => $r->transaction_date, 'amount' => $money($r, 'total_minor')]]],
            'journals_submitted' => [[$col('description', 'Description'), $col('status', 'Status', 'status'), $col('date', 'Date', 'date'), $col('amount', 'Amount', 'money')],
                fn (\stdClass $r): array => ['href' => "/accounting/journals/{$r->id}", 'cells' => ['description' => $r->description, 'status' => $r->status, 'date' => $r->transaction_date, 'amount' => $money($r, 'total_minor')]]],
            'failed_events' => [[$col('event', 'Event', 'event'), $col('date', 'Date', 'date'), $col('reason', 'Why it did not post')],
                fn (\stdClass $r): array => ['href' => '/accounting/events', 'cells' => ['event' => $r->event_type, 'date' => $r->transaction_date,
                    'reason' => $r->failure_reason ?? 'Not posted after '.StuckAccountingEvents::staleAfterMinutes().' minutes']]],
            'bounced_premium' => [[$col('policy', 'Policy'), $col('holder', 'Policyholder'), $col('bounced', 'Bounced', 'date'), $col('amount', 'Unpaid', 'money')],
                fn (\stdClass $r): array => ['href' => "/policies/{$r->id}", 'cells' => ['policy' => $r->number, 'holder' => $r->display_name, 'bounced' => $r->bounced_on, 'amount' => $money($r, 'outstanding_minor')]]],
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
            'bills_to_approve' => [[$col('bill', 'Bill'), $col('supplier', 'Supplier'), $col('due', 'Due', 'date'), $col('amount', 'Payable', 'money')],
                fn (\stdClass $r): array => ['href' => "/payables/bills/{$r->id}", 'cells' => ['bill' => $r->number, 'supplier' => $r->display_name, 'due' => $r->due_date, 'amount' => $money($r, 'payable_minor')]]],
            'payment_runs_to_approve', 'payment_runs_to_release' => [[$col('run', 'Payment run'), $col('bills', 'Bills'), $col('date', 'Pay date', 'date'), $col('amount', 'Total', 'money')],
                fn (\stdClass $r): array => ['href' => "/payables/payment-runs/{$r->id}", 'cells' => ['run' => $r->number, 'bills' => (string) $r->item_count, 'date' => $r->pay_date, 'amount' => $money($r, 'total_minor')]]],
            default => throw new \InvalidArgumentException("Unknown work queue {$key}."),
        };
    }

    /**
     * The approvals a block lists: claims only for claim_approvals; for "Waiting for my approval" everything, except referrals when the user has their own
     * Referrals block (GA-26), so nothing is counted twice.
     *
     * @return list<array<string, mixed>>
     */
    private function approvals(string $userId, string $key): array
    {
        $referralsApart = $key === 'approvals_over_threshold' && in_array('referrals', $this->keysFor($userId), true);

        return array_values(array_filter($this->inbox($userId), fn (array $a): bool => $key === 'claim_approvals'
            ? str_starts_with((string) $a['object_type'], 'claim') : ! ($referralsApart && $a['object_type'] === ProposalService::REFERRAL)));
    }

    /** @return list<array<string, mixed>> the approvals this user may decide, read once per request */
    private function inbox(string $userId): array
    {
        return $this->decidable[$userId] ??= $this->inbox->decidableBy($userId);
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
