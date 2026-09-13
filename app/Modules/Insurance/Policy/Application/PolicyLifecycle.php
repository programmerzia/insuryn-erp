<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Insurance\Policy\Domain\EarningSchedule;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyTransactionType;
use App\Modules\Insurance\Policy\Domain\Events\PolicyCancelled;
use App\Modules\Insurance\Policy\Domain\Events\PolicyEndorsed;
use App\Modules\Insurance\Policy\Domain\Events\PolicyIssued;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;
use App\Modules\Insurance\Policy\Domain\PremiumMath;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use App\Modules\Platform\Tax\TaxRates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Design §5.4 policy lifecycle:
 *   quote ─issue─▶ issued ─inception reached─▶ active ─▶ expired
 *   issued | active ─cancel─▶ cancelled;  active ─lapse─▶ lapsed ─reinstate─▶ active;  active | expired ─renew─▶ renewed (+ new quote)
 *   endorse: issued | active, bumps version, creates a policy transaction.
 * Issue, endorse and cancel emit their accounting event (§4.1, §4.4) in the same transaction as the policy change (§8.2).
 */
final class PolicyLifecycle
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ProductCatalogue $products,
        private readonly TaxRates $taxRates,
        private readonly DocumentNumberer $numbers,
        private readonly InstallmentPlanner $installments,
        private readonly PolicyAccountingEvents $accounting,
        private readonly Audit $audit,
    ) {}

    public function quote(QuoteRequest $request, string $actorUserId): Policy
    {
        $this->permissions->authorize($actorUserId, 'policy.create', AuthorizationScope::branch($request->entityId, $request->branchId));
        if ($request->premiumMinor <= 0 || $request->installmentCount < 1) {
            throw new BusinessRuleViolation('INVALID_PREMIUM', 'A quote needs a positive premium and at least one installment.');
        }
        $this->assertPayers($request->payers);
        $version = $this->products->versionOn($request->productId, $request->inception);
        $premium = PremiumMath::splitTax($request->premiumMinor, $this->taxRate($version, $request->inception), (bool) $version->tax_profile['inclusive']);

        return DB::transaction(function () use ($request, $version, $premium, $actorUserId): Policy {
            $policy = Policy::query()->create([
                'entity_id' => $request->entityId, 'branch_id' => $request->branchId, 'product_id' => $request->productId, 'product_version_id' => $version->id,
                'policyholder_party_id' => $request->policyholderPartyId, 'agent_id' => $request->agentId, 'channel' => $request->agentId === null ? 'direct' : 'agent',
                'status' => PolicyStatus::Quote->value, 'inception' => $request->inception->toDateString(),
                'expiry' => $request->inception->addMonthsNoOverflow($version->term_months)->subDay()->toDateString(),
                'currency' => $request->currency, 'gross_premium_minor' => $premium['gross'], 'tax_minor' => $premium['tax'], 'net_premium_minor' => $premium['net'],
                'installment_count' => $request->installmentCount, 'version' => 1, 'created_by' => $actorUserId,
            ]);
            foreach ($request->payers as $payer) {
                DB::table('policy_payers')->insert(['id' => (string) \Illuminate\Support\Str::uuid7(), 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id,
                    'party_id' => $payer->partyId, 'share_bp' => $payer->shareBp, 'created_at' => now()]);
            }
            $this->audit->record('policy.quoted', AuditSubject::of('policy', $policy->id), null, ['premium' => $premium, 'product_version' => $version->version,
                'payers' => array_map(fn (PayerShare $p): array => ['party_id' => $p->partyId, 'share_bp' => $p->shareBp], $request->payers)], null, 'policy.create', Actor::user($actorUserId));

            return $policy;
        });
    }

    public function issue(string $policyId, CarbonImmutable $on, string $actorUserId): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->authorize($actorUserId, 'policy.issue', $policy);
        $this->assertStatus($policy, [PolicyStatus::Quote], 'issue');
        $number = $this->numbers->reserve(new DocumentNumberScope($policy->entity_id, $policy->branch_id, 'policy', 'POL', $on), $actorUserId);

        return DB::transaction(function () use ($policyId, $on, $actorUserId, $number): Policy {
            $policy = $this->lock($policyId, [PolicyStatus::Quote], 'issue');
            $policy->forceFill(['status' => PolicyStatus::Issued->value, 'number' => $number->number, 'issued_at' => CarbonImmutable::now()])->save();
            $this->numbers->markUsed($number->id, 'policy', $policy->id);
            $transaction = $this->record($policy, PolicyTransactionType::New, $policy->inception, $policy->gross_premium_minor, $policy->net_premium_minor, $policy->tax_minor, null, null, $actorUserId, $on);
            $this->installments->planFor($policy);
            $this->accounting->issued($policy, $transaction, $on);
            $this->audit->record('policy.issued', AuditSubject::of('policy', $policy->id), ['status' => 'quote'], ['status' => 'issued', 'number' => $policy->number], null, 'policy.issue', Actor::user($actorUserId));
            Event::dispatch(new PolicyIssued($policy->id, $transaction->id));

            return $policy;
        });
    }

    /** @param int $premiumDeltaMinor change in the charged premium under the product's tax profile (negative decreases) */
    public function endorse(string $policyId, CarbonImmutable $effectiveDate, int $premiumDeltaMinor, string $reason, string $actorUserId): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->authorize($actorUserId, 'policy.endorse', $policy);

        return DB::transaction(function () use ($policyId, $effectiveDate, $premiumDeltaMinor, $reason, $actorUserId): Policy {
            $policy = $this->lock($policyId, [PolicyStatus::Issued, PolicyStatus::Active], 'endorse');
            if ($effectiveDate->lessThan($policy->inception) || $effectiveDate->greaterThan($policy->expiry)) {
                throw new BusinessRuleViolation('ENDORSEMENT_OUTSIDE_COVER', "An endorsement must take effect between {$policy->inception->toDateString()} and {$policy->expiry->toDateString()}.");
            }
            $version = ProductVersion::query()->findOrFail($policy->product_version_id);
            $delta = PremiumMath::splitTax(abs($premiumDeltaMinor), $this->taxRate($version, $policy->inception), (bool) $version->tax_profile['inclusive']);
            $sign = $premiumDeltaMinor < 0 ? -1 : 1;
            [$gross, $net, $tax] = [$sign * $delta['gross'], $sign * $delta['net'], $sign * $delta['tax']];

            $policy->forceFill(['version' => $policy->version + 1, 'gross_premium_minor' => $policy->gross_premium_minor + $gross,
                'net_premium_minor' => $policy->net_premium_minor + $net, 'tax_minor' => $policy->tax_minor + $tax])->save();
            $transaction = $this->record($policy, PolicyTransactionType::Endorsement, $effectiveDate, $gross, $net, $tax, $reason, null, $actorUserId);
            $gross >= 0 ? $this->installments->addIncrease($policy, $effectiveDate, $gross) : $this->installments->credit($policy, -$gross);
            $this->accounting->endorsed($policy, $transaction);
            $this->audit->record('policy.endorsed', AuditSubject::of('policy', $policy->id), null,
                ['version' => $policy->version, 'gross_delta' => $gross, 'net_delta' => $net, 'tax_delta' => $tax], $reason, 'policy.endorse', Actor::user($actorUserId));
            Event::dispatch(new PolicyEndorsed($policy->id, $transaction->id));

            return $policy;
        });
    }

    /**
     * Design §4.4 pro-rata cancellation effective $cancelDate (cover ends the day before). Earning catch-up for the
     * premium earned to date is posted by the premium earning module when it handles PolicyCancelled.
     */
    public function cancel(string $policyId, CarbonImmutable $cancelDate, string $reason, string $actorUserId): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->authorize($actorUserId, 'policy.cancel', $policy);
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'Cancelling a policy requires a reason.');
        }

        return DB::transaction(function () use ($policyId, $cancelDate, $reason, $actorUserId): Policy {
            $policy = $this->lock($policyId, [PolicyStatus::Issued, PolicyStatus::Active], 'cancel');
            if ($cancelDate->lessThan($policy->inception) || $cancelDate->greaterThan($policy->expiry)) {
                throw new BusinessRuleViolation('CANCELLATION_OUTSIDE_COVER', 'A cancellation must take effect during the cover period.');
            }
            $amounts = $this->cancellationAmounts($policy, $cancelDate);
            $transaction = $this->record($policy, PolicyTransactionType::Cancellation, $cancelDate, 0, 0, 0, $reason, $amounts, $actorUserId);
            if ($amounts['receivable_outstanding'] > 0) {
                $this->installments->credit($policy, $amounts['receivable_outstanding']);
            }
            $policy->forceFill(['status' => PolicyStatus::Cancelled->value, 'cancelled_at' => CarbonImmutable::now(),
                'cancel_date' => $cancelDate->toDateString(), 'cancel_reason' => $reason])->save();
            $this->accounting->cancelled($policy, $transaction, $amounts);
            $this->audit->record('policy.cancelled', AuditSubject::of('policy', $policy->id), null, $amounts, $reason, 'policy.cancel', Actor::user($actorUserId));
            Event::dispatch(new PolicyCancelled($policy->id, $transaction->id, $amounts['unearned_remaining'], $policy->net_premium_minor));

            return $policy;
        });
    }

    public function lapse(string $policyId, string $reason, string $actorUserId): Policy
    {
        return $this->simpleTransition($policyId, [PolicyStatus::Active], PolicyStatus::Lapsed, 'lapse', 'policy.cancel', $reason, $actorUserId);
    }

    /** Reinstatement starts a fresh grace period for automatic lapse (dunning, A-10). */
    public function reinstate(string $policyId, string $reason, string $actorUserId): Policy
    {
        $policy = $this->simpleTransition($policyId, [PolicyStatus::Lapsed], PolicyStatus::Active, 'reinstate', 'policy.issue', $reason, $actorUserId);
        $policy->forceFill(['reinstated_on' => CarbonImmutable::today()->toDateString()])->save();

        return $policy;
    }

    /**
     * Automatic lapse for non-payment by the dunning run (spec §4 auto-lapse): the system acts, so no user permission applies; audited with the
     * reason. Returns false when the policy is no longer active.
     */
    public function lapseForNonPayment(string $policyId, string $reason): bool
    {
        return DB::transaction(function () use ($policyId, $reason): bool {
            $policy = Policy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();
            if ($policy->status !== PolicyStatus::Active) {
                return false;
            }
            $policy->forceFill(['status' => PolicyStatus::Lapsed->value])->save();
            $this->audit->record('policy.lapsed', AuditSubject::of('policy', $policy->id), ['status' => 'active'], ['status' => 'lapsed'], $reason, null, Actor::system());

            return true;
        });
    }

    /** Marks the policy renewed and creates the renewal as a new quote from the day after expiry (it is issued separately). */
    public function renew(string $policyId, string $actorUserId): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);

        return DB::transaction(function () use ($policy, $actorUserId): Policy {
            $this->simpleTransition($policy->id, [PolicyStatus::Active, PolicyStatus::Expired], PolicyStatus::Renewed, 'renew', 'policy.create', null, $actorUserId);
            $renewal = $this->quote(new QuoteRequest($policy->entity_id, $policy->branch_id, $policy->product_id, $policy->policyholder_party_id, $policy->agent_id,
                $policy->expiry->addDay(), $policy->gross_premium_minor, $policy->currency, $policy->installment_count,
                DB::table('policy_payers')->where('policy_id', $policy->id)->exists() ? $this->installments->payers($policy) : []), $actorUserId);
            $renewal->forceFill(['renewal_of_policy_id' => $policy->id])->save();

            return $renewal;
        });
    }

    /** Design §5.4 "inception reached": issued policies whose cover has started become active. Returns how many. */
    public function activateDue(CarbonImmutable $today): int
    {
        return Policy::query()->where('status', PolicyStatus::Issued->value)->where('inception', '<=', $today->toDateString())
            ->update(['status' => PolicyStatus::Active->value, 'updated_at' => now()]);
    }

    /** Active policies whose cover ended before $today expire. Returns how many. */
    public function expireDue(CarbonImmutable $today): int
    {
        return Policy::query()->where('status', PolicyStatus::Active->value)->where('expiry', '<', $today->toDateString())
            ->update(['status' => PolicyStatus::Expired->value, 'updated_at' => now()]);
    }

    /**
     * @return array{earned_to_date: int, unearned_remaining: int, tax_reversal: int, receivable_outstanding: int, refund_due: int}
     */
    private function cancellationAmounts(Policy $policy, CarbonImmutable $cancelDate): array
    {
        $version = ProductVersion::query()->findOrFail($policy->product_version_id);
        $earned = EarningSchedule::earnedBefore($version->earning_method, EarningLayers::of($policy), $cancelDate);
        $unearned = $policy->net_premium_minor - $earned;
        // ASSUMPTION: A-1 / D-06 — see ProductVersion::refundsTaxOnCancellation (OPEN #2).
        $taxReversal = $version->refundsTaxOnCancellation() && $policy->net_premium_minor > 0
            ? PremiumMath::prorate($policy->tax_minor, $unearned, $policy->net_premium_minor) : 0;
        $receivableCredit = min($this->installments->outstanding($policy), $unearned + $taxReversal);

        return ['earned_to_date' => $earned, 'unearned_remaining' => $unearned, 'tax_reversal' => $taxReversal,
            'receivable_outstanding' => $receivableCredit, 'refund_due' => $unearned + $taxReversal - $receivableCredit];
    }

    /**
     * @param list<PayerShare> $payers
     *
     * @throws BusinessRuleViolation PAYER_SHARES_INVALID | UNKNOWN_PAYER
     */
    private function assertPayers(array $payers): void
    {
        if ($payers === []) {
            return;
        }
        $ids = array_map(fn (PayerShare $p): string => $p->partyId, $payers);
        $positive = array_filter($payers, fn (PayerShare $p): bool => $p->shareBp > 0);
        if (count($positive) !== count($payers) || count(array_unique($ids)) !== count($ids) || array_sum(array_map(fn (PayerShare $p): int => $p->shareBp, $payers)) !== 10_000) {
            throw new BusinessRuleViolation('PAYER_SHARES_INVALID', 'Payers must be distinct parties with positive shares totalling 10000 basis points.');
        }
        $valid = array_values(array_filter($ids, fn (string $id): bool => \Illuminate\Support\Str::isUuid($id)));
        if (count($valid) !== count($ids) || DB::table('parties')->whereIn('id', $valid)->count() !== count($ids)) {
            throw new BusinessRuleViolation('UNKNOWN_PAYER', 'Every payer must be a party of this tenant.');
        }
    }

    /** @param list<PolicyStatus> $from */
    private function simpleTransition(string $policyId, array $from, PolicyStatus $to, string $action, string $permission, ?string $reason, string $actorUserId): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->authorize($actorUserId, $permission, $policy);

        return DB::transaction(function () use ($policyId, $from, $to, $action, $permission, $reason, $actorUserId): Policy {
            $policy = $this->lock($policyId, $from, $action);
            $before = $policy->status->value;
            $policy->forceFill(['status' => $to->value])->save();
            $this->audit->record("policy.{$to->value}", AuditSubject::of('policy', $policy->id), ['status' => $before], ['status' => $to->value], $reason, $permission, Actor::user($actorUserId));

            return $policy;
        });
    }

    /**
     * @param array<string, int>|null $amounts
     * @param CarbonImmutable|null $accountingDate the date the transaction's accounting event posts on (default: effective date)
     */
    private function record(Policy $policy, PolicyTransactionType $type, CarbonImmutable $effectiveDate, int $gross, int $net, int $tax, ?string $reason, ?array $amounts,
        string $actorUserId, ?CarbonImmutable $accountingDate = null): PolicyTransaction
    {
        return PolicyTransaction::query()->create([
            'policy_id' => $policy->id, 'type' => $type->value, 'effective_date' => $effectiveDate->toDateString(),
            'accounting_date' => ($accountingDate ?? $effectiveDate)->toDateString(),
            'premium_delta_minor' => $gross, 'net_delta_minor' => $net, 'tax_delta_minor' => $tax, 'policy_version' => $policy->version,
            'reason' => $reason, 'amounts' => $amounts, 'created_by' => $actorUserId,
        ]);
    }

    private function taxRate(ProductVersion $version, CarbonImmutable $on): int
    {
        $taxType = $version->tax_profile['tax_type'] ?? null;

        return $taxType === null ? 0 : $this->taxRates->rateOn((string) $version->tax_profile['jurisdiction'], $taxType, $on);
    }

    private function authorize(string $actorUserId, string $permission, Policy $policy): void
    {
        $this->permissions->authorize($actorUserId, $permission, AuthorizationScope::branch($policy->entity_id, $policy->branch_id));
    }

    /** @param list<PolicyStatus> $allowed */
    private function lock(string $policyId, array $allowed, string $action): Policy
    {
        $policy = Policy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();
        $this->assertStatus($policy, $allowed, $action);

        return $policy;
    }

    /** @param list<PolicyStatus> $allowed */
    private function assertStatus(Policy $policy, array $allowed, string $action): void
    {
        if (! in_array($policy->status, $allowed, true)) {
            throw new BusinessRuleViolation('INVALID_POLICY_TRANSITION', "A {$policy->status->value} policy cannot {$action}.");
        }
    }
}
