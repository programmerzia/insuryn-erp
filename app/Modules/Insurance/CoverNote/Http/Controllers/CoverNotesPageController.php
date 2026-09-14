<?php

declare(strict_types=1);

namespace App\Modules\Insurance\CoverNote\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\CoverNote\Application\CoverNoteService;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 3 design §6 "Cover notes queue (expiring)" (slice R6): cover notes by last day of cover, soonest first, optionally only active notes ending within N days;
 * issued from the proposal page, cancelled here with a reason.
 */
final class CoverNotesPageController
{
    public const AREA = [CoverNoteService::ISSUE, CoverNoteService::CANCEL, QuotationService::PERMISSION];

    public function __construct(
        private readonly CoverNoteService $coverNotes,
        private readonly PermissionChecker $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $held = $this->permissions->permissionsOf($actor);
        if (array_intersect(self::AREA, $held) === []) {
            throw new PermissionDenied($actor, implode('|', self::AREA));
        }
        $this->coverNotes->expireDue(app(BusinessClock::class)->today());
        $within = $request->query('within');
        $days = is_string($within) && preg_match('/^\d{1,3}$/', $within) === 1 ? (int) $within : null;
        $entity = PageSupport::entity();
        $today = app(BusinessClock::class)->today();
        $rows = DB::table('cover_notes as n')->join('proposals as p', 'p.id', '=', 'n.proposal_id')->leftJoin('parties as c', 'c.id', '=', 'p.customer_party_id')
            ->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')->leftJoin('users as u', 'u.id', '=', 'n.issued_by')->where('n.entity_id', $entity['id'])
            ->when($days !== null, fn ($q) => $q->where('n.status', 'active')->where('n.valid_to', '<=', $today->addDays((int) $days)->toDateString()))
            ->orderByRaw("case when n.status = 'active' then 0 else 1 end")->orderBy('n.valid_to')->limit(PageSupport::LIST_PAGE_SIZE)
            ->get(['n.id', 'n.number', 'n.status', 'n.valid_from', 'n.valid_to', 'n.cancel_reason', 'n.issued_at', 'n.branch_id', 'n.entity_id', 'p.id as proposal_id', 'p.number as proposal_number',
                'c.display_name as customer', 'pr.code as product', 'u.name as issued_by']);
        $cancelAnywhere = in_array(CoverNoteService::CANCEL, $held, true);

        return Inertia::render('coverNotes/Index', [
            'today' => $today->toDateString(),
            'within' => $days,
            'expiringDays' => (int) config('erp.cover_notes.expiring_within_days', 7),
            'coverNotes' => $rows->map(fn (object $n): array => [
                'id' => (string) $n->id, 'number' => (string) $n->number, 'status' => (string) $n->status, 'valid_from' => (string) $n->valid_from, 'valid_to' => (string) $n->valid_to,
                'days_left' => $n->status === 'active' ? (int) $today->diffInDays(CarbonImmutable::parse((string) $n->valid_to), false) : null,
                'proposal_id' => (string) $n->proposal_id, 'proposal_number' => (string) $n->proposal_number, 'customer' => (string) $n->customer, 'product' => (string) $n->product,
                'issued_by' => (string) $n->issued_by, 'documents' => app(\App\Http\Documents\GeneratedDocumentsController::class)->forCoverNote((string) $n->id), 'cancel_reason' => $n->cancel_reason === null ? null : (string) $n->cancel_reason,
                'can_cancel' => $n->status === 'active' && $cancelAnywhere
                    && $this->permissions->has($actor, CoverNoteService::CANCEL, \App\Modules\Platform\Authorization\AuthorizationScope::branch((string) $n->entity_id, (string) $n->branch_id)),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, string $proposal): RedirectResponse
    {
        /** @var array{valid_from: string, valid_to: string} $data */
        $data = $request->validate(['valid_from' => ['required', 'date_format:Y-m-d'], 'valid_to' => ['required', 'date_format:Y-m-d']]);
        $note = $this->coverNotes->issue($proposal, CarbonImmutable::parse($data['valid_from']), CarbonImmutable::parse($data['valid_to']), PageSupport::actor($request));

        return redirect("/proposals/{$proposal}")->with('status', "Cover note {$note->number} issued.");
    }

    public function cancel(Request $request, string $coverNote): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $note = $this->coverNotes->cancel($coverNote, $data['reason'], PageSupport::actor($request));

        return redirect('/cover-notes')->with('status', "Cover note {$note->number} cancelled.");
    }
}
