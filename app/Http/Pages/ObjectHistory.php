<?php

declare(strict_types=1);

namespace App\Http\Pages;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An object's story for its page (UX brief §6.2): the audit trail as plain sentences ("Reserve increased to 350,000.00 by Rafiq Islam"),
 * the journals it posted (UX brief §1.5, accounting one click away) and the raw audit rows. Read-only, app-level composition.
 */
final class ObjectHistory
{
    private const ACTION_WORDS = ['policy.quoted' => 'Policy quoted', 'policy.issued' => 'Policy issued', 'policy.endorsed' => 'Policy endorsed', 'policy.cancelled' => 'Policy cancelled',
        'claim.registered' => 'Claim registered', 'claim.reserved' => 'Reserve changed', 'claim_payment.approved' => 'Claim payment approved', 'receipt.recorded' => 'Receipt recorded'];

    /**
     * @param list<array{0: string, 1: string}> $subjects [object_type, object_id] pairs
     * @return list<array{sentence: string, date: string, reason: string|null}>
     */
    public function timeline(array $subjects): array
    {
        $timeline = [];
        foreach ($this->events($subjects) as $event) {
            // A payment below every approval limit is requested and approved in one step: only an approval that exists is "sent for approval".
            if ($event->action === 'claim_payment.approval_requested' && ! DB::table('approvals')->where('object_type', 'claim_payment')->where('object_id', DB::table('audit_events')->where('id', $event->id)->value('object_id'))->exists()) {
                continue;
            }
            $timeline[] = ['sentence' => $this->sentence($event), 'date' => substr((string) $event->occurred_at, 0, 10), 'reason' => $event->reason === null ? null : (string) $event->reason];
        }

        return $timeline;
    }

    /**
     * @param list<array{0: string, 1: string}> $subjects
     * @return list<array{action: string, by: string, at: string, reason: string|null, changes: list<array{field: string, before: string|null, after: string|null}>}>
     */
    public function audit(array $subjects): array
    {
        $rows = [];
        foreach ($this->events($subjects) as $event) {
            $before = (array) json_decode((string) ($event->before ?? '{}'), true);
            $after = (array) json_decode((string) ($event->after ?? '{}'), true);
            $changes = [];
            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
                $changes[] = ['field' => str_replace('_', ' ', (string) $field), 'before' => self::scalar($before[$field] ?? null), 'after' => self::scalar($after[$field] ?? null)];
            }
            $rows[] = ['action' => self::ACTION_WORDS[$event->action] ?? ucfirst(str_replace(['_', '.'], ' ', (string) $event->action)), 'by' => $this->actor($event), 'at' => (string) $event->occurred_at,
                'reason' => $event->reason === null ? null : (string) $event->reason, 'changes' => $changes];
        }

        return $rows;
    }

    /**
     * Journals touching the object, newest first, with their lines.
     *
     * @param list<string> $journalIds
     * @return list<array{id: string, number: string|null, event: string|null, date: string, status: string, lines: list<array{account: string, name: string, debit: string|null, credit: string|null, role: string|null}>}>
     */
    public function accounting(array $journalIds): array
    {
        if ($journalIds === []) {
            return [];
        }
        $journals = DB::table('journals as j')->leftJoin('accounting_events as e', 'e.id', '=', 'j.source_id')->whereIn('j.id', $journalIds)
            ->orderByDesc('j.posting_date')->orderByDesc('j.created_at')->get(['j.id', 'j.number', 'j.posting_date', 'j.status', 'j.currency', 'j.posting_rule_code', 'j.description']);
        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('l.journal_id', $journalIds)->orderBy('l.line_no')
            ->get(['l.journal_id', 'l.account_id', 'l.role_code', 'a.code', 'a.name', 'l.side', 'l.amount_minor'])->groupBy('journal_id');
        $roles = PageSupport::accountRoles(array_values(array_unique($lines->flatten(1)->whereNull('role_code')->pluck('account_id')->map(fn (mixed $id): string => (string) $id)->all())));
        $result = [];
        foreach ($journals as $journal) {
            $rows = [];
            foreach ($lines->get($journal->id, collect()) as $line) {
                $amount = PageSupport::money((int) $line->amount_minor, (string) $journal->currency);
                $rows[] = ['account' => (string) $line->code, 'name' => (string) $line->name, 'debit' => $line->side === 'debit' ? $amount : null, 'credit' => $line->side === 'credit' ? $amount : null,
                    'role' => $line->role_code === null ? ($roles[(string) $line->account_id] ?? null) : (string) $line->role_code];
            }
            $event = $journal->posting_rule_code === null ? null : explode('.', (string) $journal->posting_rule_code)[0];
            $result[] = ['id' => (string) $journal->id, 'number' => $journal->number === null ? null : (string) $journal->number, 'event' => $event ?? (string) $journal->description,
                'date' => (string) $journal->posting_date, 'status' => (string) $journal->status, 'lines' => $rows];
        }

        return $result;
    }

    /** @return list<string> journals with a line on the dimension (policy, claim) */
    public function journalsOnDimension(string $column, string $id): array
    {
        return array_values(DB::table('journal_lines')->where($column === 'dim_claim' ? 'dim_claim' : 'dim_policy', $id)->distinct()->pluck('journal_id')->map(fn ($j): string => (string) $j)->all());
    }

    /** @return list<string> journals posted for a receipt: its own events and its allocations' */
    public function journalsForReceipt(string $receiptId): array
    {
        $allocations = DB::table('receipt_allocations')->where('receipt_id', $receiptId)->pluck('id')->map(fn ($a): string => (string) $a)->all();

        return array_values(DB::table('journals')->where(fn ($q) => $q->where('source_type', 'receipt')->where('source_id', $receiptId))
            ->orWhere(fn ($q) => $q->where('source_type', 'receipt_allocation')->whereIn('source_id', $allocations))->pluck('id')->map(fn ($j): string => (string) $j)->all());
    }

    /**
     * @param list<array{0: string, 1: string}> $subjects
     * @return list<\stdClass>
     */
    private function events(array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }
        $query = DB::table('audit_events as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->where(function ($q) use ($subjects): void {
                foreach ($subjects as [$type, $id]) {
                    $q->orWhere(fn ($s) => $s->where('a.object_type', $type)->where('a.object_id', $id));
                }
            })
            ->orderByDesc('a.occurred_at')->orderByDesc('a.id')->limit(200);

        return array_values($query->get(['a.id', 'a.action', 'a.object_type', 'a.occurred_at', 'a.actor_type', 'a.before', 'a.after', 'a.reason', 'u.name'])->all());
    }

    private function sentence(\stdClass $event): string
    {
        $after = (array) json_decode((string) ($event->after ?? '{}'), true);
        $before = (array) json_decode((string) ($event->before ?? '{}'), true);
        $money = fn (mixed $minor): string => PageSupport::money((int) $minor, 'BDT');
        $by = ' by '.$this->actor($event);
        $why = $event->reason === null || $event->reason === '' ? '' : " ({$event->reason})";
        $date = fn (mixed $value): string => CarbonImmutable::parse((string) $value)->format('j M Y');

        return match ((string) $event->action) {
            'policy.quoted' => 'Quoted at '.$money(is_array($after['premium'] ?? null) ? ($after['premium']['gross'] ?? 0) : ($after['premium'] ?? 0)).$by,
            'policy.issued' => 'Issued'.$by,
            'policy.endorsed' => 'Endorsed: premium '.((int) ($after['gross_delta'] ?? 0) < 0 ? 'down' : 'up').' by '.$money(abs((int) ($after['gross_delta'] ?? 0))).$why.$by,
            'policy.cancelled' => 'Cancelled'.$why.$by.((int) ($after['refund_due'] ?? 0) > 0 ? '; refund due '.$money($after['refund_due']) : ''),
            'policy.lapsed' => 'Lapsed'.$why.$by, 'policy.active' => 'Reinstated'.$why.$by, 'policy.renewed' => 'Renewed'.$by, 'policy.expired' => 'Expired'.$by,
            'claim.registered' => 'Registered'.$by.($this->claimDescription($event) ?? ''),
            'claim.reserved' => ((int) ($before['reserve_minor'] ?? 0) === 0 ? 'Reserve set to ' : ((int) ($after['reserve_minor'] ?? 0) > (int) ($before['reserve_minor'] ?? 0) ? 'Reserve increased to ' : 'Reserve reduced to '))
                .$money($after['reserve_minor'] ?? 0).$why.$by,
            'claim_payment.approved' => 'Payment of '.$money($after['amount_minor'] ?? 0).' approved'.$by,
            'claim_payment.approval_requested' => 'Payment of '.$money($after['amount_minor'] ?? 0).' sent for approval'.$by,
            'claim_payment.release_requested' => 'Payment release requested'.$by,
            'claim_payment.released' => 'Paid on '.$date($after['paid_on'] ?? 'today').$by,
            'claim_payment.rejected', 'claim_payment.release_rejected' => 'Payment rejected'.$why.$by,
            'claim.recovered' => 'Recovery of '.$money($after['amount_minor'] ?? 0).' ('.($after['type'] ?? 'recovery').') received'.$by,
            'claim.closed' => 'Closed'.$why.$by, 'claim.rejected' => 'Rejected'.$why.$by, 'claim.reopened' => 'Reopened'.$why.$by,
            'receipt.recorded' => 'Recorded '.$money($after['amount_minor'] ?? 0).$by.': '.$money($after['allocated_minor'] ?? 0).' allocated'
                .((int) ($after['suspense_minor'] ?? 0) > 0 ? ', '.$money($after['suspense_minor']).' held in suspense' : ''),
            'receipt.bounced' => 'Cheque bounced on '.$date($after['bounced_on'] ?? 'today').$why.$by,
            'suspense.allocated' => $money($after['amount_minor'] ?? 0).' allocated from suspense'.$by,
            'user.invited' => 'Invited'.$by, 'user.invitation_sent' => 'Invitation sent again'.$by,
            'user.deactivated' => 'Deactivated'.$by, 'user.reactivated' => 'Reactivated'.$by,
            'user_role.assigned' => 'Given '.$this->roleAndScope($after).$by,
            'user_role.revoked' => 'Removed from '.$this->roleAndScope($before).$by,
            default => ucfirst(str_replace(['_', '.'], ' ', (string) $event->action)).$why.$by,
        };
    }

    /** @param array<mixed> $assignment role_id, role_code, scope_type, scope_id → "Branch Officer for branch HO" */
    private function roleAndScope(array $assignment): string
    {
        $role = DB::table('roles')->where('id', $assignment['role_id'] ?? null)->value('name') ?? str_replace('_', ' ', (string) ($assignment['role_code'] ?? 'a role'));
        $scopeType = (string) ($assignment['scope_type'] ?? 'tenant');
        $place = match ($scopeType) {
            'entity' => DB::table('legal_entities')->where('id', $assignment['scope_id'] ?? null)->value('code'),
            'branch' => DB::table('branches')->where('id', $assignment['scope_id'] ?? null)->value('code'),
            default => null,
        };

        return $role.($scopeType === 'tenant' ? ' for the whole organisation' : " for {$scopeType} ".($place ?? 'no longer set up'));
    }

    private function claimDescription(\stdClass $event): ?string
    {
        $description = DB::table('claims')->where('id', DB::table('audit_events')->where('id', $event->id)->value('object_id'))->value('description');

        return is_string($description) ? ": {$description}" : null;
    }

    private function actor(\stdClass $event): string
    {
        return $event->actor_type === 'system' ? 'the system' : (string) ($event->name ?? 'someone who has left');
    }

    private static function scalar(mixed $value): ?string
    {
        return $value === null ? null : (is_scalar($value) ? (string) $value : (string) json_encode($value));
    }
}
