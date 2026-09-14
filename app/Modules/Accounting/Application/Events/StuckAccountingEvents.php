<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Events;

use App\Modules\Accounting\Application\Contracts\PostingDispatcher;
use App\Modules\Accounting\Domain\Enums\EventStatus;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Design §5.1 / §8.4 exception queue (gap fix GA-08): accounting events that did not post — `failed` (a business failure such as an unmapped role or a
 * locked period, fixed by a configuration change) and `queued` for longer than `erp.posting.stale_after_minutes` (the worker was down or the dispatch was
 * lost). "Requeue" (`accounting.requeue_event`) puts a failed event back to `queued` and hands it to the posting worker again; a stale queued event is
 * handed over again as it is. The status CAS in PostingEngine keeps a second delivery harmless.
 */
final class StuckAccountingEvents
{
    public const PERMISSION = 'accounting.requeue_event';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PostingDispatcher $dispatcher,
        private readonly Audit $audit,
    ) {}

    /** ASSUMPTION A-173: minutes after which a queued event counts as stuck (default 15; the worker normally posts within seconds). */
    public static function staleAfterMinutes(): int
    {
        return max(1, (int) config('erp.posting.stale_after_minutes', 15));
    }

    /** Failed events and events queued before the stale cut-off, oldest first. */
    public function query(CarbonImmutable $now): Builder
    {
        $cutoff = $now->subMinutes(self::staleAfterMinutes());

        return DB::table('accounting_events')
            ->where(fn (Builder $q) => $q->where('status', EventStatus::Failed->value)
                ->orWhere(fn (Builder $queued) => $queued->where('status', EventStatus::Queued->value)->where('created_at', '<', $cutoff)))
            ->orderBy('created_at')->orderBy('id');
    }

    /** @throws BusinessRuleViolation EVENT_NOT_STUCK (posted meanwhile, or queued only a moment ago) */
    public function requeue(string $eventId, string $actorUserId, CarbonImmutable $now): void
    {
        $event = DB::table('accounting_events')->where('id', $eventId)->first(['id', 'entity_id', 'event_type', 'status', 'failure_reason', 'created_at'])
            ?? throw (new ModelNotFoundException())->setModel(AccountingEvent::class, [$eventId]);
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::entity((string) $event->entity_id));

        DB::transaction(function () use ($event, $actorUserId, $now): void {
            $stale = $event->status === EventStatus::Queued->value && CarbonImmutable::parse((string) $event->created_at)->lessThan($now->subMinutes(self::staleAfterMinutes()));
            $reset = $event->status === EventStatus::Failed->value
                && DB::table('accounting_events')->where('id', $event->id)->where('status', EventStatus::Failed->value)
                    ->update(['status' => EventStatus::Queued->value, 'failure_reason' => null]) === 1;
            if (! $reset && ! $stale) {
                throw new BusinessRuleViolation('EVENT_NOT_STUCK', "Accounting event {$event->id} is {$event->status}; only failed or long-queued events are requeued.");
            }
            $this->audit->record('accounting_event.requeued', AuditSubject::of('accounting_event', (string) $event->id),
                ['status' => (string) $event->status, 'failure_reason' => $event->failure_reason], ['status' => EventStatus::Queued->value],
                null, self::PERMISSION, Actor::user($actorUserId));
            $this->dispatcher->dispatchAfterCommit(TenantContext::id(), (string) $event->id);
        });
    }
}
