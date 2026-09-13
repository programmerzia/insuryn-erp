<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Application;

use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Domain\Models\Quotation;
use App\Modules\Insurance\Underwriting\Domain\Enums\KycStatus;
use App\Modules\Insurance\Underwriting\Domain\Enums\ProposalStatus;
use App\Modules\Insurance\Underwriting\Domain\Enums\UnderwritingStatus;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
use App\Modules\Insurance\Underwriting\Domain\ReferralReason;
use App\Modules\Platform\Approvals\ApprovalFacts;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 3 design §2 step 2 "Proposal: attach KYC/docs → underwriting rules" (slice R5).
 *
 * - `createFromQuotation`: the customer accepts an issued quotation within its validity; the proposal copies its terms and frozen rating, gets its own number
 *   (document type `proposal`, `PRP-<branch>-<fy>-<seq>`) and the quotation becomes converted — one transaction.
 * - `verifyKyc` (quotation.create: identity document type and number) or `waiveKyc` (underwriting.decide, reason) while the proposal is a draft (A-92).
 * - `submit`: UnderwritingRules decide — no reason: approved automatically; otherwise referred with every reason and an approval request `proposal_referral`
 *   (an approval policy for it when one matches the sum insured, else one step for the role with the smallest underwriting limit covering it, D-31).
 * - R7 hooks: `approvedForIssue` (the terms of an approved proposal) and `markIssued` (inside the policy issue transaction).
 * Documents are attached through the Documents tab (DocumentStore, object type `proposal`).
 */
final class ProposalService
{
    public const REFERRAL = 'proposal_referral';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly QuotationService $quotations,
        private readonly UnderwritingRules $rules,
        private readonly UnderwritingLimits $limits,
        private readonly ApprovalService $approvals,
        private readonly DocumentNumberer $numbers,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation QUOTATION_NOT_ISSUED, QUOTATION_EXPIRED */
    public function createFromQuotation(string $quotationId, string $actorUserId): Proposal
    {
        $quotation = Quotation::query()->findOrFail($quotationId);
        $this->authorize($actorUserId, QuotationService::PERMISSION, $quotation->entity_id, $quotation->branch_id);
        $today = CarbonImmutable::today();
        if ($quotation->number === null || $quotation->rating_result === null || $quotation->customer_party_id === null || $quotation->class_code === null) {
            throw new BusinessRuleViolation('QUOTATION_NOT_ISSUED', 'Only an issued quotation can become a proposal.');
        }
        $number = $this->numbers->reserve(new DocumentNumberScope($quotation->entity_id, $quotation->branch_id, 'proposal', 'PRP', $today), $actorUserId);

        return DB::transaction(function () use ($quotationId, $actorUserId, $today, $number): Proposal {
            $quotation = $this->quotations->accept($quotationId, $today, $actorUserId);
            $result = $quotation->ratingResult() ?? throw new BusinessRuleViolation('QUOTATION_NOT_ISSUED', 'Only an issued quotation can become a proposal.');
            $proposal = new Proposal(['id' => (string) Str::uuid7()]);
            $proposal->forceFill([
                'entity_id' => $quotation->entity_id, 'branch_id' => $quotation->branch_id, 'quotation_id' => $quotation->id, 'number' => $number->number,
                'product_id' => $quotation->product_id, 'product_version_id' => $quotation->product_version_id, 'class_code' => $quotation->class_code,
                'customer_party_id' => $quotation->customer_party_id, 'producer_id' => $quotation->producer_id, 'inception' => $quotation->inception->toDateString(),
                'risk_inputs' => $result->riskInputs, 'risk_keys' => $quotation->risk_keys ?? [], 'rating_result' => $result->toArray(), 'rating_plan_code' => $result->plan['code'],
                'rating_plan_version' => $result->plan['version'], 'currency' => $quotation->currency, 'sum_insured_minor' => (int) $quotation->sum_insured_minor,
                'net_premium_minor' => $result->netPremiumMinor, 'duties_minor' => $result->dutiesTotalMinor, 'gross_premium_minor' => $result->grossPremiumMinor,
                'status' => ProposalStatus::Draft->value, 'kyc_status' => KycStatus::Pending->value, 'created_by' => $actorUserId,
            ])->save();
            $this->numbers->markUsed($number->id, 'proposal', $proposal->id);
            $this->audit->record('proposal.created', AuditSubject::of('proposal', $proposal->id), null, ['number' => $proposal->number, 'quotation' => $quotation->number,
                'gross_premium_minor' => $proposal->gross_premium_minor], null, QuotationService::PERMISSION, Actor::user($actorUserId));

            return $proposal;
        });
    }

    /** @throws BusinessRuleViolation KYC_INVALID, PROPOSAL_NOT_DRAFT */
    public function verifyKyc(string $proposalId, string $idType, string $idNumber, string $actorUserId): Proposal
    {
        $proposal = Proposal::query()->findOrFail($proposalId);
        $this->authorize($actorUserId, QuotationService::PERMISSION, $proposal->entity_id, $proposal->branch_id);
        /** @var list<string> $types */
        $types = (array) config('erp.underwriting.kyc_id_types', []);
        if (! in_array($idType, $types, true) || trim($idNumber) === '' || mb_strlen(trim($idNumber)) > 64) {
            throw new BusinessRuleViolation('KYC_INVALID', 'Record the identity document type and its number.');
        }

        return $this->changeKyc($proposalId, $actorUserId, ['kyc_status' => KycStatus::Verified->value, 'kyc_id_type' => $idType, 'kyc_id_number' => trim($idNumber),
            'kyc_waiver_reason' => null], 'proposal.kyc_verified', null, QuotationService::PERMISSION);
    }

    /** @throws BusinessRuleViolation REASON_REQUIRED, PROPOSAL_NOT_DRAFT */
    public function waiveKyc(string $proposalId, string $reason, string $actorUserId): Proposal
    {
        $proposal = Proposal::query()->findOrFail($proposalId);
        $this->authorize($actorUserId, UnderwritingDecisions::PERMISSION, $proposal->entity_id, $proposal->branch_id);
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'Waiving KYC needs a reason.');
        }

        return $this->changeKyc($proposalId, $actorUserId, ['kyc_status' => KycStatus::Waived->value, 'kyc_id_type' => null, 'kyc_id_number' => null,
            'kyc_waiver_reason' => trim($reason)], 'proposal.kyc_waived', trim($reason), UnderwritingDecisions::PERMISSION);
    }

    /**
     * Applies the underwriting rules: approved automatically when no referral reason holds, otherwise referred to an underwriter.
     *
     * @throws BusinessRuleViolation PROPOSAL_NOT_DRAFT
     */
    public function submit(string $proposalId, string $actorUserId): Proposal
    {
        $proposal = Proposal::query()->findOrFail($proposalId);
        $this->authorize($actorUserId, QuotationService::PERMISSION, $proposal->entity_id, $proposal->branch_id);
        $today = CarbonImmutable::today();

        return DB::transaction(function () use ($proposalId, $actorUserId, $today): Proposal {
            $proposal = $this->lock($proposalId, [ProposalStatus::Draft]);
            $reasons = $this->rules->evaluate($proposal, $actorUserId, $today);
            $referred = $reasons !== [];
            $proposal->forceFill([
                'status' => ($referred ? ProposalStatus::Submitted : ProposalStatus::Approved)->value,
                'underwriting_status' => ($referred ? UnderwritingStatus::Referred : UnderwritingStatus::AutoApproved)->value,
                'referral_reasons' => array_map(fn (ReferralReason $r): array => $r->toArray(), $reasons),
                'submitted_by' => $actorUserId, 'submitted_at' => CarbonImmutable::now(),
                'decided_at' => $referred ? null : CarbonImmutable::now(),
            ])->save();
            $this->audit->record('proposal.submitted', AuditSubject::of('proposal', $proposal->id), ['status' => 'draft'],
                ['status' => $proposal->status->value, 'underwriting_status' => $proposal->underwriting_status?->value, 'referral_reasons' => array_map(fn (ReferralReason $r): string => $r->code, $reasons)],
                null, QuotationService::PERMISSION, Actor::user($actorUserId));
            if ($referred) {
                $context = ['number' => $proposal->number, 'class_code' => $proposal->class_code, 'sum_insured_minor' => $proposal->sum_insured_minor];
                $approvalId = $this->approvals->request(self::REFERRAL, $proposal->id, new ApprovalFacts($proposal->sum_insured_minor, ['kind' => $proposal->class_code]), $actorUserId, $today, $context)
                    ?? $this->approvals->requestWithSteps(self::REFERRAL, $proposal->id, [$this->referralStep($proposal, $today)], $actorUserId, $context);
                $proposal->forceFill(['approval_id' => $approvalId])->save();
            }

            return $proposal;
        });
    }

    /**
     * R7 hook: the terms of an approved proposal, for issuing its policy.
     *
     * @throws BusinessRuleViolation PROPOSAL_NOT_APPROVED
     */
    public function approvedForIssue(string $proposalId): ApprovedProposal
    {
        $proposal = Proposal::query()->findOrFail($proposalId);
        if ($proposal->status !== ProposalStatus::Approved) {
            throw new BusinessRuleViolation('PROPOSAL_NOT_APPROVED', "Proposal {$proposal->number} is {$proposal->status->value}; only an approved proposal becomes a policy.");
        }
        $result = $proposal->ratingResult();

        return new ApprovedProposal($proposal->id, $proposal->number, $proposal->quotation_id, $proposal->entity_id, $proposal->branch_id, $proposal->product_id,
            $proposal->product_version_id, $proposal->class_code, $proposal->customer_party_id, $proposal->producer_id, $proposal->inception, $proposal->currency,
            $result->riskInputs, $proposal->sum_insured_minor, $result, $proposal->manual_loading_bp, $proposal->manual_loading_reason,
            // Slice R6: the proposal's active cover note, which R7 supersedes with the policy (CoverNoteService::supersedeForProposal).
            ($note = DB::table('cover_notes')->where('proposal_id', $proposal->id)->where('status', 'active')->value('id')) === null ? null : (string) $note);
    }

    /**
     * R7 hook: the proposal's policy was issued. Must run inside the policy issue transaction.
     *
     * @throws BusinessRuleViolation PROPOSAL_NOT_APPROVED
     */
    public function markIssued(string $proposalId, string $policyId, string $actorUserId): Proposal
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('ProposalService::markIssued must run inside the policy issue transaction.');
        }
        $proposal = $this->lock($proposalId, [ProposalStatus::Approved], 'PROPOSAL_NOT_APPROVED');
        $proposal->forceFill(['status' => ProposalStatus::Issued->value, 'policy_id' => $policyId, 'issued_at' => CarbonImmutable::now()])->save();
        $this->audit->record('proposal.issued', AuditSubject::of('proposal', $proposal->id), ['status' => 'approved'], ['status' => 'issued', 'policy_id' => $policyId],
            null, 'policy.issue', Actor::user($actorUserId));

        return $proposal;
    }

    /**
     * The approval step for a referral: the role deciding referrals with the smallest underwriting limit covering the sum insured; anyone deciding referrals when
     * none does — who then cannot approve it beyond their own limit, only decline it (A-85).
     *
     * @return array{permission: string, role: string|null}
     */
    private function referralStep(Proposal $proposal, CarbonImmutable $on): array
    {
        $roles = $this->limits->rolesCovering($proposal->class_code, $proposal->sum_insured_minor, $on, UnderwritingDecisions::PERMISSION);

        return ['permission' => UnderwritingDecisions::PERMISSION, 'role' => $roles[0] ?? null];
    }

    /** @param array<string, mixed> $changes */
    private function changeKyc(string $proposalId, string $actorUserId, array $changes, string $action, ?string $reason, string $permission): Proposal
    {
        return DB::transaction(function () use ($proposalId, $actorUserId, $changes, $action, $reason, $permission): Proposal {
            $proposal = $this->lock($proposalId, [ProposalStatus::Draft]);
            $before = ['kyc_status' => $proposal->kyc_status->value, 'kyc_id_type' => $proposal->kyc_id_type];
            $proposal->forceFill([...$changes, 'kyc_verified_by' => $actorUserId, 'kyc_verified_at' => CarbonImmutable::now()])->save();
            $this->audit->record($action, AuditSubject::of('proposal', $proposal->id), $before, ['kyc_status' => $proposal->kyc_status->value, 'kyc_id_type' => $proposal->kyc_id_type],
                $reason, $permission, Actor::user($actorUserId));

            return $proposal;
        });
    }

    private function authorize(string $actorUserId, string $permission, string $entityId, string $branchId): void
    {
        $this->permissions->authorize($actorUserId, $permission, AuthorizationScope::branch($entityId, $branchId));
    }

    /** @param list<ProposalStatus> $allowed */
    private function lock(string $proposalId, array $allowed, string $reason = 'PROPOSAL_NOT_DRAFT'): Proposal
    {
        $proposal = Proposal::query()->whereKey($proposalId)->lockForUpdate()->firstOrFail();
        if (! in_array($proposal->status, $allowed, true)) {
            throw new BusinessRuleViolation($reason, "Proposal {$proposal->number} is {$proposal->status->value}.");
        }

        return $proposal;
    }
}
