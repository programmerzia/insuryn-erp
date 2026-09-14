<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Application;

use App\Modules\Insurance\Policy\Domain\Events\PolicyRenewed;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Renewal\Domain\ExpiryRegisterStatus;
use App\Modules\Insurance\Renewal\Domain\RenewalReasons;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 3 design §4 "Nightly job builds the expiry register: policies expiring in 60/30/15/7 days by branch/producer" (slice R9, D-42).
 *
 * - `build(today)` is idempotent: one row per policy (unique), inserted when an issued or active policy comes within the largest bucket of its expiry
 *   (erp.renewals.buckets, A-125), its bucket and days left refreshed while the row is open; a rerun changes nothing else and never duplicates.
 *   Open rows close by themselves: `renewed` when the policy was renewed (also the Phase 1 renewal quote), `lapsed` when it was cancelled
 *   (policy_cancelled) or lapsed for non-payment (policy_lapsed) — the open renewal quotation is declined — or expired without a renewal (no_response, A-132).
 * - `recordNotRenewed` (renewal.manage on the branch): a person records why the customer does not renew; the open renewal quotation is declined.
 * - `markRenewed` (listener of PolicyRenewed): the renewal was issued — the row becomes renewed, even after it lapsed (a late renewal).
 */
final class ExpiryRegister
{
    public const PERMISSION = 'renewal.manage';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly QuotationService $quotations,
        private readonly Audit $audit,
    ) {}

    /** @return non-empty-list<int> the configured buckets in days, smallest first */
    public static function buckets(): array
    {
        $buckets = array_values(array_unique(array_filter(array_map('intval', (array) config('erp.renewals.buckets', [60, 30, 15, 7])), fn (int $b): bool => $b > 0)));
        sort($buckets);

        return $buckets === [] ? [60] : $buckets;
    }

    /** ASSUMPTION: A-125 — the smallest bucket not below the days left; null once the policy has expired. */
    public static function bucketFor(int $daysLeft): ?int
    {
        if ($daysLeft < 0) {
            return null;
        }
        foreach (self::buckets() as $bucket) {
            if ($daysLeft <= $bucket) {
                return $bucket;
            }
        }

        return null;
    }

    /** Builds the register as of $today in the current tenant. Returns how many policies are open in it. */
    public function build(CarbonImmutable $today): int
    {
        $day = $today->toDateString();
        $last = $today->addDays(max(self::buckets()))->toDateString();
        $due = DB::table('policies as p')->join('product_versions as v', 'v.id', '=', 'p.product_version_id')
            ->whereIn('p.status', ['issued', 'active'])->whereNotNull('p.number')->whereBetween('p.expiry', [$day, $last])
            ->get(['p.id', 'p.entity_id', 'p.branch_id', 'p.number', 'p.product_id', 'v.class_code', 'p.agent_id', 'p.policyholder_party_id', 'p.expiry']);
        foreach ($due as $policy) {
            $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse((string) $policy->expiry), false);
            DB::statement(<<<'SQL'
                INSERT INTO expiry_register (id, tenant_id, entity_id, branch_id, policy_id, policy_number, product_id, class_code, agent_id, policyholder_party_id, expiry, rated,
                    bucket, days_left, as_of, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'upcoming', now(), now())
                ON CONFLICT (tenant_id, policy_id) DO UPDATE SET bucket = EXCLUDED.bucket, days_left = EXCLUDED.days_left, as_of = EXCLUDED.as_of, branch_id = EXCLUDED.branch_id,
                    agent_id = EXCLUDED.agent_id, expiry = EXCLUDED.expiry, updated_at = now()
                WHERE expiry_register.status IN ('upcoming', 'renewal_offered') AND (expiry_register.as_of <> EXCLUDED.as_of OR expiry_register.branch_id <> EXCLUDED.branch_id
                    OR expiry_register.agent_id IS DISTINCT FROM EXCLUDED.agent_id)
                SQL, [(string) Str::uuid7(), TenantContext::id(), $policy->entity_id, $policy->branch_id, $policy->id, $policy->number, $policy->product_id, $policy->class_code,
                $policy->agent_id, $policy->policyholder_party_id, $policy->expiry, $policy->class_code !== null ? 'true' : 'false', self::bucketFor($daysLeft), $daysLeft, $day]);
        }
        $this->closeFinished($today);

        return DB::table('expiry_register')->whereIn('status', ExpiryRegisterStatus::open())->count();
    }

    /**
     * Records that the customer does not renew, with a configured reason (`other` needs a note). Allowed while the row is open or lapsed by the system.
     *
     * @throws BusinessRuleViolation RENEWAL_REASON_INVALID, REASON_NOTE_REQUIRED, RENEWAL_ALREADY_CLOSED
     */
    public function recordNotRenewed(string $entryId, string $reason, ?string $note, string $actorUserId): void
    {
        $entry = DB::table('expiry_register')->where('id', $entryId)->first();
        if (! $entry instanceof \stdClass) {
            throw new BusinessRuleViolation('RENEWAL_UNKNOWN', 'That policy is not in the expiry register.');
        }
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::branch((string) $entry->entity_id, (string) $entry->branch_id));
        if (! array_key_exists($reason, RenewalReasons::choices())) {
            throw new BusinessRuleViolation('RENEWAL_REASON_INVALID', 'Choose why the policy is not renewed from the list.');
        }
        $note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 1000);
        if ($reason === RenewalReasons::OTHER && $note === null) {
            throw new BusinessRuleViolation('REASON_NOTE_REQUIRED', 'Say why in a few words when the reason is Other.');
        }

        DB::transaction(function () use ($entryId, $reason, $note, $actorUserId): void {
            $entry = DB::table('expiry_register')->where('id', $entryId)->lockForUpdate()->first();
            if (! $entry instanceof \stdClass || ! in_array($entry->status, [...ExpiryRegisterStatus::open(), ExpiryRegisterStatus::Lapsed->value], true)) {
                throw new BusinessRuleViolation('RENEWAL_ALREADY_CLOSED', 'This policy was already renewed or recorded as not renewed.');
            }
            DB::table('expiry_register')->where('id', $entryId)->update(['status' => ExpiryRegisterStatus::NotRenewed->value, 'reason' => $reason, 'reason_note' => $note,
                'closed_by' => $actorUserId, 'closed_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);
            $label = (string) RenewalReasons::label($reason);
            $open = QuotationService::openRenewal((string) $entry->policy_id);
            if ($open !== null) {
                $this->quotations->declineRenewal($open, "Not renewed: {$label}".($note === null ? '' : " — {$note}"), $actorUserId);
            }
            $this->audit->record('renewal.not_renewed', AuditSubject::of('policy', (string) $entry->policy_id), ['status' => $entry->status],
                ['status' => 'not_renewed', 'reason' => $reason, 'declined_quotation_id' => $open], $note ?? $label, self::PERMISSION, Actor::user($actorUserId));
        });
    }

    /** Listener: the expiring policy was renewed (PolicyLifecycle::issueFromProposal, inside its transaction). */
    public function markRenewed(PolicyRenewed $event): void
    {
        $this->closeAsRenewed($event->previousPolicyId, $event->renewalPolicyId, CarbonImmutable::today());
    }

    /** The open renewal quotation was offered: the row moves to renewal_offered (A-127: the quotation itself stays issued). */
    public static function offered(string $entryId, string $quotationId): void
    {
        DB::table('expiry_register')->where('id', $entryId)->update(['status' => ExpiryRegisterStatus::RenewalOffered->value, 'renewal_quotation_id' => $quotationId,
            'quote_problem_code' => null, 'quote_problem' => null, 'updated_at' => CarbonImmutable::now()]);
    }

    private function closeFinished(CarbonImmutable $today): void
    {
        $open = DB::table('expiry_register as r')->join('policies as p', 'p.id', '=', 'r.policy_id')->whereIn('r.status', ExpiryRegisterStatus::open())
            ->get(['r.id', 'r.policy_id', 'r.status', 'r.expiry', 'p.status as policy_status']);
        foreach ($open as $row) {
            if ($row->policy_status === 'renewed') {
                // A Phase 1 renewal quote (typed premium) counts once it is issued.
                $renewal = DB::table('policies')->where('renewal_of_policy_id', $row->policy_id)->where('status', '<>', 'quote')->orderByDesc('created_at')->value('id');
                if ($renewal !== null) {
                    $this->closeAsRenewed((string) $row->policy_id, (string) $renewal, $today);
                }

                continue;
            }
            $reason = match (true) {
                $row->policy_status === 'cancelled' => RenewalReasons::POLICY_CANCELLED,
                $row->policy_status === 'lapsed' => RenewalReasons::POLICY_LAPSED,
                (string) $row->expiry < $today->toDateString() => RenewalReasons::NO_RESPONSE,
                default => null,
            };
            if ($reason === null) {
                $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse((string) $row->expiry), false);
                DB::table('expiry_register')->where('id', $row->id)->where('as_of', '<>', $today->toDateString())
                    ->update(['days_left' => $daysLeft, 'bucket' => self::bucketFor($daysLeft), 'as_of' => $today->toDateString(), 'updated_at' => CarbonImmutable::now()]);

                continue;
            }
            DB::transaction(function () use ($row, $reason, $today): void {
                $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse((string) $row->expiry), false);
                DB::table('expiry_register')->where('id', $row->id)->whereIn('status', ExpiryRegisterStatus::open())->update(['status' => ExpiryRegisterStatus::Lapsed->value,
                    'reason' => $reason, 'days_left' => $daysLeft, 'bucket' => self::bucketFor($daysLeft), 'as_of' => $today->toDateString(), 'closed_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now()]);
                $open = QuotationService::openRenewal((string) $row->policy_id);
                if ($open !== null && $reason !== RenewalReasons::NO_RESPONSE) {
                    $this->quotations->declineRenewal($open, (string) RenewalReasons::label($reason), null);
                }
                $this->audit->record('renewal.lapsed', AuditSubject::of('policy', (string) $row->policy_id), ['status' => $row->status], ['status' => 'lapsed', 'reason' => $reason],
                    RenewalReasons::label($reason), null, Actor::system());
            });
        }
    }

    private function closeAsRenewed(string $previousPolicyId, string $renewalPolicyId, CarbonImmutable $today): void
    {
        $renewal = DB::table('policies')->where('id', $renewalPolicyId)->first(['quotation_id']);
        $quotationId = $renewal?->quotation_id === null ? null : (string) $renewal->quotation_id;
        $entry = DB::table('expiry_register')->where('policy_id', $previousPolicyId)->first(['id', 'status', 'renewal_quotation_id']);
        $now = CarbonImmutable::now();
        if ($entry === null) {
            $policy = DB::table('policies as p')->join('product_versions as v', 'v.id', '=', 'p.product_version_id')->where('p.id', $previousPolicyId)
                ->first(['p.entity_id', 'p.branch_id', 'p.number', 'p.product_id', 'v.class_code', 'p.agent_id', 'p.policyholder_party_id', 'p.expiry']);
            if ($policy === null) {
                return;
            }
            $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse((string) $policy->expiry), false);
            DB::table('expiry_register')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'entity_id' => $policy->entity_id, 'branch_id' => $policy->branch_id,
                'policy_id' => $previousPolicyId, 'policy_number' => (string) $policy->number, 'product_id' => $policy->product_id, 'class_code' => $policy->class_code,
                'agent_id' => $policy->agent_id, 'policyholder_party_id' => $policy->policyholder_party_id, 'expiry' => $policy->expiry, 'rated' => $policy->class_code !== null,
                'bucket' => self::bucketFor($daysLeft), 'days_left' => $daysLeft, 'as_of' => $today->toDateString(), 'status' => ExpiryRegisterStatus::Renewed->value,
                'renewal_quotation_id' => $quotationId, 'renewal_policy_id' => $renewalPolicyId, 'closed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

            return;
        }
        if ($entry->status === ExpiryRegisterStatus::Renewed->value) {
            return;
        }
        DB::table('expiry_register')->where('id', $entry->id)->update(['status' => ExpiryRegisterStatus::Renewed->value, 'renewal_policy_id' => $renewalPolicyId,
            'renewal_quotation_id' => $quotationId ?? $entry->renewal_quotation_id, 'reason' => null, 'reason_note' => null, 'closed_at' => $now, 'updated_at' => $now]);
    }
}
