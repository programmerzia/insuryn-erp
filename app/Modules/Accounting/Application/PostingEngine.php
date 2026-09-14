<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Application\Posting\AccountOverrides;
use App\Modules\Accounting\Application\Posting\DatabaseRuleViolation;
use App\Modules\Accounting\Application\Posting\EventPayloadValidator;
use App\Modules\Accounting\Application\Posting\JournalDraftBuilder;
use App\Modules\Accounting\Application\Posting\TenantDimensionRequirements;
use App\Modules\Accounting\Application\Posting\JournalWriter;
use App\Modules\Accounting\Application\Posting\PostingContextLoader;
use App\Modules\Accounting\Application\Posting\TransientFailureDetector;
use App\Modules\Accounting\Domain\Enums\EventStatus;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Accounting\Domain\Models\Book;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalBatch;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Exceptions\UnexpectedPostingException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Design §3.3. Orchestrates one accounting event into one posted journal per book: resolve the rule,
 * load the posting context, build a draft (pure), write it. Runs in a worker; idempotent via status CAS.
 */
final class PostingEngine
{
    public function __construct(
        private readonly PostingRuleRepository $rules,
        private readonly PostingContextLoader $contexts,
        private readonly JournalDraftBuilder $drafts,
        private readonly JournalWriter $writer,
        private readonly TransientFailureDetector $transientFailures,
        private readonly AccountOverrides $overrides,
        private readonly TenantDimensionRequirements $tenantDimensions,
    ) {}

    /**
     * Claim and post in ONE transaction, so any exception rolls the event back to `queued` and a
     * crashed worker never leaves it stuck in `posting`. Outcomes (design §8.4): business failure →
     * `failed` with reason, no exception (a kernel trigger rejection counts, D-10); transient DB error →
     * rethrown for the queue to retry; anything else → `failed` with an UNEXPECTED reason and
     * UnexpectedPostingException.
     *
     * @return list<Journal> journals created (one per book); empty when nothing was posted
     */
    public function post(string $eventId, bool $actorMayPostSoftLocked = false): array
    {
        try {
            return DB::transaction(fn (): array => $this->claimAndPost($eventId, $actorMayPostSoftLocked));
        } catch (AccountingException $e) {
            $this->markFailed($eventId, $e->reasonCode.': '.$e->getMessage());

            return [];
        } catch (Throwable $e) {
            if ($this->transientFailures->isTransient($e)) {
                throw $e;
            }
            $ruleViolation = DatabaseRuleViolation::reasonCode($e);
            if ($ruleViolation !== null) {
                $this->markFailed($eventId, $ruleViolation.': '.$e->getMessage());

                return [];
            }
            $this->markFailed($eventId, 'UNEXPECTED: '.$e::class.': '.$e->getMessage());

            throw UnexpectedPostingException::forEvent($eventId, $e);
        }
    }

    /** @return list<Journal> */
    private function claimAndPost(string $eventId, bool $actorMayPostSoftLocked): array
    {
        if (! $this->claim($eventId)) {
            return [];
        }
        $event = AccountingEvent::query()->findOrFail($eventId);
        EventPayloadValidator::assertValid($event->event_type, $event->payload);
        $rule = $this->rules->resolve($event->event_type, $event->effective_date, $event->payload, $event->dimensions);
        $this->tenantDimensions->assertPresent($event->event_type, $event->dimensions); // gap audit GA-47 (D-74)

        $batch = JournalBatch::query()->create(['entity_id' => $event->entity_id, 'accounting_event_id' => $event->id, 'created_at' => now()]);
        $journals = [];
        foreach ($rule->books as $bookCode) {
            $journals[] = $this->postToBook($event, $rule, $batch, $bookCode, $actorMayPostSoftLocked);
        }
        $this->complete($event, $batch);

        return $journals;
    }

    /** CAS on the status: a retried or duplicate worker finds nothing to claim and exits (§8.3a). */
    private function claim(string $eventId): bool
    {
        return DB::table('accounting_events')->where('id', $eventId)->where('status', EventStatus::Queued->value)
            ->update(['status' => EventStatus::Posting->value]) === 1;
    }

    private function postToBook(AccountingEvent $event, PostingRule $rule, JournalBatch $batch, string $bookCode, bool $actorMayPostSoftLocked): Journal
    {
        $book = Book::query()->where('code', $bookCode)->firstOrFail();
        $context = $this->contexts->load($event->entity_id, $book->id, $event->transaction_date, $actorMayPostSoftLocked);
        $accounts = $this->overrides->apply($event->entity_id, $context->accountsByRole, $event->payload);
        $draft = $this->drafts->build($rule, $event->payload, $event->dimensions, $event->currency, $accounts);

        return $this->writer->post([
            'entity_id' => $event->entity_id, 'book_id' => $book->id, 'batch_id' => $batch->id, 'period_id' => $context->period->id,
            'transaction_date' => $event->transaction_date, 'posting_date' => $event->transaction_date, 'effective_date' => $event->effective_date,
            'kind' => JournalKind::System->value, 'source_type' => $event->source_type, 'source_id' => $event->source_id,
            'posting_rule_code' => $rule->code, 'posting_rule_version' => $rule->version,
            'currency' => $event->currency, 'description' => $event->event_type,
        ], $draft);
    }

    /** §3.3 step 4: the event is posted and JournalPosted is in the outbox, in the same transaction as the journals. */
    private function complete(AccountingEvent $event, JournalBatch $batch): void
    {
        $event->forceFill(['status' => EventStatus::Posted->value, 'journal_batch_id' => $batch->id])->save();
        DB::table('outbox')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $event->tenant_id, 'message_type' => 'JournalPosted',
            'payload' => json_encode(['batch_id' => $batch->id, 'event_id' => $event->id], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    /** Runs after the rollback, guarded by the status the rollback restored, so a concurrent change is never overwritten. */
    private function markFailed(string $eventId, string $reason): void
    {
        DB::table('accounting_events')->where('id', $eventId)->where('status', EventStatus::Queued->value)
            ->update(['status' => EventStatus::Failed->value, 'failure_reason' => $reason]);
    }
}
