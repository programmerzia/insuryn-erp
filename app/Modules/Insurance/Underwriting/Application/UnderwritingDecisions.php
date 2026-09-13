<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Application;

use App\Modules\Insurance\Quotation\Domain\Models\Quotation;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Rating\Domain\ManualLoading;
use App\Modules\Insurance\Underwriting\Domain\Enums\ProposalStatus;
use App\Modules\Insurance\Underwriting\Domain\Enums\UnderwritingStatus;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Approvals\ApprovalStatus;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Money\MinorUnits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 design §2 step 2 "referral queue for underwriter with approve/decline/counter (re-rate with manual loading, reason mandatory, audit)" and §5 (slice R5).
 *
 * A referral is decided through the approval engine (`proposal_referral`), so maker ≠ checker, step roles and the approvals inbox apply. On top of the engine:
 * the decider holds underwriting.decide on the proposal's branch; segregation of duties on the proposal itself (whoever prepared it with quotation.create does
 * not decide it, SodGuard); and approving needs the decider's own underwriting limit to cover the sum insured (`UNDERWRITING_LIMIT_EXCEEDED`).
 * A counter-offer is an approval with a manual loading: the quotation's rating is re-rated with the original plan version plus the loading (D-32), the premium and
 * rating result on the proposal change, and the loading and its reason are audited — they are the "special terms" for the schedule.
 */
final class UnderwritingDecisions
{
    public const PERMISSION = 'underwriting.decide';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly ApprovalService $approvals,
        private readonly UnderwritingLimits $limits,
        private readonly RatingEngine $engine,
        private readonly Audit $audit,
    ) {}

    /**
     * Decides the pending referral: approve (optionally with a manual loading) or decline (reason required).
     *
     * @throws BusinessRuleViolation PROPOSAL_NOT_REFERRED, UNDERWRITING_LIMIT_EXCEEDED, LOADING_REASON_REQUIRED, MANUAL_LOADING_INVALID
     */
    public function decide(string $proposalId, Decision $decision, ?int $loadingBasisPoints, ?string $reason, string $deciderId): ApprovalStatus
    {
        $proposal = Proposal::query()->findOrFail($proposalId);
        $this->authorize($deciderId, $proposal);
        $approvalId = $this->approvals->pendingFor(ProposalService::REFERRAL, $proposal->id)
            ?? throw new BusinessRuleViolation('PROPOSAL_NOT_REFERRED', "Proposal {$proposal->number} has no referral waiting for a decision.");
        $loading = $decision === Decision::Approved && $loadingBasisPoints !== null ? new ManualLoading($loadingBasisPoints, (string) $reason) : null;

        return DB::transaction(function () use ($proposalId, $decision, $loading, $reason, $deciderId, $approvalId): ApprovalStatus {
            if ($loading !== null) {
                $this->applyLoading($proposalId, $loading, $deciderId);
            }

            return $this->approvals->decide($approvalId, $deciderId, $decision, $reason === null || trim($reason) === '' ? null : trim($reason));
        });
    }

    /** Final approval of a referral (ProposalReferralApprovalHandler). */
    public function completeApproval(string $proposalId, string $deciderId): void
    {
        $proposal = $this->lockReferred($proposalId);
        $this->authorize($deciderId, $proposal);
        $this->sod->assert($deciderId, self::PERMISSION, AuditSubject::of('proposal', $proposal->id));
        $limit = $this->limits->limitFor($deciderId, $proposal->class_code, CarbonImmutable::today());
        if ($limit === null || $limit < $proposal->sum_insured_minor) {
            throw new BusinessRuleViolation('UNDERWRITING_LIMIT_EXCEEDED', 'Sum insured '.MinorUnits::format($proposal->sum_insured_minor, $proposal->currency)
                .' is above your underwriting limit for '.$proposal->class_code.($limit === null ? ' (you have none)' : ' ('.MinorUnits::format($limit, $proposal->currency).')').'.');
        }
        $proposal->forceFill(['status' => ProposalStatus::Approved->value, 'underwriting_status' => UnderwritingStatus::Approved->value, 'decided_by' => $deciderId,
            'decided_at' => CarbonImmutable::now()])->save();
        $this->audit->record('proposal.approved', AuditSubject::of('proposal', $proposal->id), ['status' => 'submitted'], ['status' => 'approved', 'manual_loading_bp' => $proposal->manual_loading_bp,
            'gross_premium_minor' => $proposal->gross_premium_minor], null, self::PERMISSION, Actor::user($deciderId));
    }

    /** A declined referral (ProposalReferralApprovalHandler). */
    public function completeRejection(string $proposalId, string $deciderId, string $reason): void
    {
        $proposal = $this->lockReferred($proposalId);
        $this->authorize($deciderId, $proposal);
        $this->sod->assert($deciderId, self::PERMISSION, AuditSubject::of('proposal', $proposal->id));
        $proposal->forceFill(['status' => ProposalStatus::Declined->value, 'underwriting_status' => UnderwritingStatus::Declined->value, 'decided_by' => $deciderId,
            'decided_at' => CarbonImmutable::now(), 'decision_reason' => $reason])->save();
        $this->audit->record('proposal.declined', AuditSubject::of('proposal', $proposal->id), ['status' => 'submitted'], ['status' => 'declined'], $reason, self::PERMISSION, Actor::user($deciderId));
    }

    private function applyLoading(string $proposalId, ManualLoading $loading, string $deciderId): void
    {
        $proposal = $this->lockReferred($proposalId);
        $base = Quotation::query()->whereKey($proposal->quotation_id)->firstOrFail()->ratingResult()
            ?? throw new BusinessRuleViolation('PROPOSAL_NOT_REFERRED', 'The quotation behind this proposal has no rating.');
        $result = $this->engine->rerate($base, $loading);
        $before = ['manual_loading_bp' => $proposal->manual_loading_bp, 'net_premium_minor' => $proposal->net_premium_minor, 'gross_premium_minor' => $proposal->gross_premium_minor];
        $proposal->forceFill(['rating_result' => $result->toArray(), 'net_premium_minor' => $result->netPremiumMinor, 'duties_minor' => $result->dutiesTotalMinor,
            'gross_premium_minor' => $result->grossPremiumMinor, 'manual_loading_bp' => $loading->basisPoints, 'manual_loading_reason' => trim($loading->reason),
            'manual_loading_by' => $deciderId])->save();
        $this->audit->record('proposal.loading_applied', AuditSubject::of('proposal', $proposal->id), $before,
            ['manual_loading_bp' => $loading->basisPoints, 'net_premium_minor' => $result->netPremiumMinor, 'gross_premium_minor' => $result->grossPremiumMinor],
            trim($loading->reason), self::PERMISSION, Actor::user($deciderId));
    }

    private function authorize(string $deciderId, Proposal $proposal): void
    {
        $this->permissions->authorize($deciderId, self::PERMISSION, AuthorizationScope::branch($proposal->entity_id, $proposal->branch_id));
    }

    private function lockReferred(string $proposalId): Proposal
    {
        $proposal = Proposal::query()->whereKey($proposalId)->lockForUpdate()->firstOrFail();
        if ($proposal->status !== ProposalStatus::Submitted || $proposal->underwriting_status !== UnderwritingStatus::Referred) {
            throw new BusinessRuleViolation('PROPOSAL_NOT_REFERRED', "Proposal {$proposal->number} is not waiting for an underwriting decision.");
        }

        return $proposal;
    }
}
