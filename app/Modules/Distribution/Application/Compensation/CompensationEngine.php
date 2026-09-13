<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Compensation;

use App\Modules\Distribution\Application\Hierarchy\HierarchyNode;
use App\Modules\Distribution\Application\Hierarchy\HierarchyQuery;
use App\Modules\Distribution\Application\Licences\LicenceRegistry;
use App\Modules\Distribution\Domain\Compensation\Beneficiary;
use App\Modules\Distribution\Domain\Compensation\CommissionLine;
use App\Modules\Distribution\Domain\Compensation\CompensationCalculator;
use App\Modules\Distribution\Domain\Compensation\ComplianceIssue;
use App\Modules\Distribution\Domain\Compensation\RuleTerms;
use App\Modules\Distribution\Domain\Compensation\SchemeTerms;
use App\Modules\Distribution\Domain\Compensation\Trigger;
use App\Modules\Distribution\Domain\ComplianceProfile;
use App\Modules\Platform\Tax\TaxRates;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The compensation engine (Distribution design note §2, slice D5; replaces the Phase 1A calculator). Loads what the calculation needs on the
 * trigger's day — the scheme and rules in force (or Phase 1 flat plan terms), the hierarchy snapshot, each producer's status and licence for the
 * product class, the withholding rate — runs `CompensationCalculator`, and records compliance exceptions (once per trigger, producer and reason).
 * It never refuses the policy or the allocation: ineligibility means no commission.
 */
final class CompensationEngine
{
    public function __construct(
        private readonly HierarchyQuery $hierarchy,
        private readonly LicenceRegistry $licences,
        private readonly TaxRates $taxRates,
        private readonly CompensationCalculator $calculator,
    ) {}

    public function calculate(CompensationRequest $request): CompensationOutcome
    {
        $terms = $this->terms($request);
        if ($terms === null) {
            return CompensationOutcome::none();
        }
        [$scheme, $rules] = $terms;
        $nodes = $this->hierarchy->hierarchyAt($request->sellerProducerId, $request->on);
        $producers = DB::table('producers')->whereIn('id', array_map(fn (HierarchyNode $n): string => $n->producerId, $nodes))->get(['id', 'type', 'status'])->keyBy('id');
        /** @var list<string> $licensedTypes */
        $licensedTypes = config('erp.distribution.licence_required_types', ['agent', 'agency_org', 'bdo', 'broker', 'partner']);
        $chain = array_map(function (HierarchyNode $node) use ($producers, $licensedTypes, $request): Beneficiary {
            $producer = $producers->get($node->producerId);
            $type = (string) $producer?->type;

            return new Beneficiary($node->producerId, $node->code, $type, (string) $producer?->status, $node->levelCode, $node->depth,
                ! in_array($type, $licensedTypes, true) || $this->licences->validLicenceId($node->producerId, $request->productClass, $request->on) !== null);
        }, $nodes);

        $calculation = $this->calculator->calculate($scheme, $rules, $chain,
            new Trigger($request->basis, $request->baseMinor, $request->policyYear, $request->productId, $request->productClass));
        foreach ($calculation->issues as $issue) {
            $this->record($issue, $request);
        }

        return new CompensationOutcome(
            array_map(fn (CommissionLine $l): CommissionAward => new CommissionAward($l->beneficiary->producerId, $l->role, $l->levelCode, $l->ruleId, $l->rateBp,
                $l->amountMinor, $l->withholdingMinor, $l->conditional), $calculation->lines),
            $request->schemeId, $request->flatTerms?->planId, $scheme->withholdingBp, array_map(fn (HierarchyNode $n): array => $n->toArray(), $nodes),
        );
    }

    /** @return array{0: SchemeTerms, 1: list<RuleTerms>}|null */
    private function terms(CompensationRequest $request): ?array
    {
        if ($request->schemeId !== null) {
            $day = $request->on->toDateString();
            $scheme = DB::table('compensation_schemes')->where('id', $request->schemeId)->where('effective_from', '<=', $day)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))->first();
            if ($scheme === null) {
                return null;
            }
            $withholding = $scheme->withholding_tax_type === null ? 0
                : $this->taxRates->withholdingRateOn((string) $scheme->withholding_jurisdiction, (string) $scheme->withholding_tax_type, $request->on);
            $rules = array_values(DB::table('compensation_rules')->where('scheme_id', $request->schemeId)->where('effective_from', '<=', $day)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))->orderBy('created_at')->get()
                ->map(fn (\stdClass $r): RuleTerms => new RuleTerms((string) $r->id, $r->product_id === null ? null : (string) $r->product_id,
                    $r->producer_type === null ? null : (string) $r->producer_type, $r->level_code === null ? null : (string) $r->level_code, (string) $r->basis,
                    (int) $r->policy_year_from, (int) $r->policy_year_to, (int) $r->rate_bp, (int) $r->override_rate_bp, $r->cap_bp === null ? null : (int) $r->cap_bp,
                    $r->min_persistency_bp === null ? null : (int) $r->min_persistency_bp, (bool) $r->renewal_requires_valid_licence, (bool) $r->pays_after_termination))
                ->all());

            return [new SchemeTerms((string) $scheme->mode, ComplianceProfile::fromArray((array) json_decode((string) $scheme->compliance_profile, true)), $withholding), $rules];
        }
        if ($request->flatTerms !== null) {
            return [
                new SchemeTerms('commission', ComplianceProfile::fromArray(['non_life_commission_allowed' => true]), $request->flatTerms->withholdingBp),
                [new RuleTerms(null, null, null, null, 'premium_received', 1, 99, $request->flatTerms->rateBp, 0, null, null, true, false)],
            ];
        }

        return null;
    }

    private function record(ComplianceIssue $issue, CompensationRequest $request): void
    {
        DB::table('compliance_exceptions')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'producer_id' => $issue->producerId,
            'policy_id' => $request->policyId, 'source_type' => $request->sourceType, 'source_id' => $request->sourceId, 'reason_code' => $issue->reasonCode,
            'message' => $issue->message, 'occurred_on' => $request->on->toDateString()]);
    }
}
