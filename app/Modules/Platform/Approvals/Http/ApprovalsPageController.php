<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals\Http;

use App\Http\Pages\PageSupport;
use App\Modules\Platform\Approvals\ApprovalInboxQuery;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The approvals inbox: what the signed-in user may decide now, across claims, journals, reversals and period reopening. */
final class ApprovalsPageController
{
    public function index(Request $request, ApprovalInboxQuery $inbox, BusinessClock $clock): Response
    {
        $zone = $clock->timezone();

        return Inertia::render('approvals/Index', [
            'approvals' => array_map(fn (array $a): array => ['id' => $a['id'], 'object_type' => $a['object_type'], 'title' => $a['title'], 'step' => $a['step'],
                'requested_by' => $a['requested_by'], 'requested_at' => $a['requested_at'], 'link' => $a['link'],
                // Gap fix GA-04: the request time on the company clock, whether this decision is the last one, and what approving it posts.
                'requested_at_label' => CarbonImmutable::parse($a['requested_at'])->setTimezone($zone)->format('j M Y, H:i'),
                'steps_total' => $a['steps_total'], 'final_step' => $a['step'] >= $a['steps_total'],
                'preview' => $inbox->preview($a['object_type'], $a['object_id']),
                'amount' => $a['amount_minor'] === null || $a['currency'] === null ? null : PageSupport::money($a['amount_minor'], $a['currency'])], $inbox->decidableBy(PageSupport::actor($request))),
        ]);
    }

    public function decide(Request $request, string $approval, ApprovalService $approvals): RedirectResponse
    {
        /** @var array{decision: string, reason?: string|null, return_to?: string|null} $data */
        $data = $request->validate(['decision' => ['required', Rule::enum(Decision::class)], 'reason' => ['nullable', 'string', 'max:1000'],
            // Gap fixes W7 (GA-04 remainder): decided on the object's own page (a manual journal), the user goes back there.
            'return_to' => ['nullable', 'string', 'regex:#^/accounting/journals/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#']]);
        $status = $approvals->decide($approval, PageSupport::actor($request), Decision::from($data['decision']), $data['reason'] ?? null);

        return redirect($data['return_to'] ?? '/approvals')->with('status', "Decision recorded; the approval is now {$status->value}.");
    }
}
