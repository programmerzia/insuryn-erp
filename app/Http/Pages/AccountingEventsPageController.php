<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Modules\Accounting\Application\Events\RecentAccountingEventsQuery;
use App\Modules\Accounting\Application\Events\StuckAccountingEvents;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gap fix GA-08, design §8.4 "Accounting Exceptions screen": accounting events that did not post, each with why, when it was queued, the business
 * record it came from and — for holders of accounting.requeue_event — Requeue once the cause is fixed (a role mapped, a period opened). Behind the
 * journals routes (accounting.view_journals), like the journal viewer.
 */
final class AccountingEventsPageController
{
    public function __construct(private readonly PermissionChecker $permissions, private readonly StuckAccountingEvents $events) {}

    public function index(Request $request, RecentAccountingEventsQuery $recent): Response
    {
        $actor = PageSupport::actor($request);
        $entity = PageSupport::entity();
        $rows = [];
        $tenantZone = DB::table('tenants')->where('id', TenantContext::id())->value('timezone');
        $timezone = is_string($tenantZone) && in_array($tenantZone, timezone_identifiers_list(), true) ? $tenantZone : 'Asia/Dhaka'; // as printed documents show times
        foreach ($this->events->query(CarbonImmutable::now())->where('entity_id', $entity['id'])->limit(500)
            ->get(['id', 'event_type', 'status', 'transaction_date', 'created_at', 'failure_reason', 'source_type', 'source_id', 'currency']) as $event) {
            $source = JournalSources::source((string) $event->source_type, (string) $event->source_id);
            $rows[] = ['id' => (string) $event->id, 'event_type' => (string) $event->event_type, 'status' => (string) $event->status, 'transaction_date' => (string) $event->transaction_date,
                'queued_at' => CarbonImmutable::parse((string) $event->created_at)->setTimezone($timezone)->format('j M Y H:i'), 'failure_reason' => $event->failure_reason === null ? null : (string) $event->failure_reason,
                'source' => $source['url'] === null ? null : $source];
        }

        return Inertia::render('accounting/events/Index', [
            'events' => $rows,
            'recentPosted' => $recent->list($entity['id']),
            'staleAfterMinutes' => StuckAccountingEvents::staleAfterMinutes(),
            'can' => ['requeue' => $this->permissions->has($actor, StuckAccountingEvents::PERMISSION, AuthorizationScope::entity($entity['id']))],
        ]);
    }

    public function requeue(Request $request, string $event): RedirectResponse
    {
        $this->events->requeue($event, PageSupport::actor($request), CarbonImmutable::now());

        return redirect('/accounting/events')->with('status', 'Sent to posting again. It leaves this list once it posts; if it fails again, the new reason shows here.');
    }
}
