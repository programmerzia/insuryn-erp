<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Underwriting\Application\UnderwritingDecisions;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
use App\Modules\Platform\Approvals\ApprovalInboxQuery;
use App\Modules\Platform\Approvals\ApprovalStatus;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 3 design §6 "Referral queue" (slice R5): proposals referred to underwriting, waiting ones first, with the reasons, the risk and the rating breakdown;
 * the inspector approves, approves with a loading (percentage and reason) or declines (reason). Decided referrals stay listed for reference.
 */
final class ReferralsPageController
{
    public function __construct(
        private readonly UnderwritingDecisions $decisions,
        private readonly PermissionChecker $permissions,
        private readonly ApprovalInboxQuery $inbox,
    ) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $reach = $this->permissions->authorizeArea($actor, [UnderwritingDecisions::PERMISSION]);
        $entity = PageSupport::entity();
        $mine = array_column(array_filter($this->inbox->decidableBy($actor), fn (array $a): bool => $a['object_type'] === 'proposal_referral'), 'object_id');
        $referred = Proposal::query();
        $reach->constrain($referred->getQuery(), 'entity_id', 'branch_id'); // Follow-up H1: a branch-scoped user lists only their branches' referrals
        $proposals = $referred->where('entity_id', $entity['id'])->whereNotNull('approval_id')
            ->orderByRaw("case when status = 'submitted' then 0 else 1 end")->orderBy('submitted_at')->limit(PageSupport::LIST_PAGE_SIZE)->get();

        return Inertia::render('underwriting/Referrals', [
            'currency' => $entity['currency'],
            'referrals' => $proposals->map(fn (Proposal $p): array => [
                ...ProposalPageController::present($p),
                'risk' => ProposalPageController::risk($p),
                'can_decide' => in_array($p->id, $mine, true),
            ])->values()->all(),
        ]);
    }

    public function decide(Request $request, string $proposal): RedirectResponse
    {
        /** @var array{decision: string, loading_percent?: string|null, reason?: string|null} $data */
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'approve_with_loading', 'decline'])],
            'loading_percent' => ['required_if:decision,approve_with_loading', 'nullable', 'string', 'max:6'],
            'reason' => [Rule::requiredIf(in_array($request->input('decision'), ['approve_with_loading', 'decline'], true)), 'nullable', 'string', 'max:1000'],
        ]);
        $loading = $data['decision'] === 'approve_with_loading' ? PageSupport::basisPoints('loading_percent', $data['loading_percent'] ?? '') : null;
        $status = $this->decisions->decide($proposal, $data['decision'] === 'decline' ? Decision::Rejected : Decision::Approved, $loading, $data['reason'] ?? null,
            PageSupport::actor($request));

        return redirect('/underwriting/referrals')->with('status', match (true) {
            $status === ApprovalStatus::Pending => 'Decision recorded; the referral moves to its next approver.',
            $data['decision'] === 'decline' => 'Proposal declined.',
            default => 'Proposal approved.',
        });
    }
}
