<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Enums\EventStatus;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\JournalStatus;
use App\Modules\Accounting\Domain\Enums\PeriodStatus;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Accounting\Domain\Models\AccountRoleMapping;
use App\Modules\Accounting\Domain\Models\Book;
use App\Modules\Accounting\Domain\Models\FiscalPeriod;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalBatch;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Exceptions\PostingFailedException;
use App\Modules\Accounting\Exceptions\UnbalancedJournalException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Design §3.3. The only writer of journals/journal_lines. Runs in a worker; idempotent via status CAS.
 *
 * DIMENSION COLUMNS: the fixed dims map to journal_lines.dim_* columns; anything else goes to dims_ext.
 */
final class PostingEngine
{
    private const DIM_COLUMNS = ['branch','product','lob','channel','agent','policy','claim','cost_centre','employee','customer','reinsurer'];

    public function __construct(
        private readonly PostingRuleRepository $rules,
        private readonly AmountEvaluator $amounts,
        private readonly JournalNumberer $numberer,
    ) {}

    /** @return list<Journal> journals created (one per book) */
    public function post(string $eventId, bool $actorMayPostSoftLocked = false): array
    {
        // Step 1: CAS the status so a retried/duplicate worker exits without side effects (§8.3a).
        $claimed = DB::table('accounting_events')->where('id', $eventId)->where('status', EventStatus::Queued->value)
            ->update(['status' => EventStatus::Posting->value]);
        if ($claimed === 0) {
            return [];
        }
        $event = AccountingEvent::query()->findOrFail($eventId);

        try {
            return DB::transaction(function () use ($event, $actorMayPostSoftLocked): array {
                $rule = $this->rules->resolve($event->event_type, $event->effective_date, $event->payload, $event->dimensions);
                $this->assertDimensions($rule, $event->dimensions);

                $batch = JournalBatch::query()->create(['entity_id' => $event->entity_id, 'accounting_event_id' => $event->id, 'created_at' => now()]);
                $journals = [];
                foreach ($rule->books as $bookCode) {
                    $book = Book::query()->where('code', $bookCode)->firstOrFail();
                    $journals[] = $this->buildAndPost($event, $rule, $book, $batch, $actorMayPostSoftLocked);
                }
                $event->forceFill(['status' => EventStatus::Posted->value, 'journal_batch_id' => $batch->id])->save();
                DB::table('outbox')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $event->tenant_id, 'message_type' => 'JournalPosted',
                    'payload' => json_encode(['batch_id' => $batch->id, 'event_id' => $event->id], JSON_THROW_ON_ERROR), 'created_at' => now()]);
                return $journals;
            });
        } catch (PostingFailedException|UnbalancedJournalException $e) {
            // Business failure: no retry (§8.4). Surface in the exceptions screen.
            $event->forceFill(['status' => EventStatus::Failed->value, 'failure_reason' => $e->reasonCode.': '.$e->getMessage()])->save();
            return [];
        } catch (Throwable $e) {
            // Transient/unknown: put back to queued so the job may retry.
            $event->forceFill(['status' => EventStatus::Queued->value, 'failure_reason' => 'TRANSIENT: '.$e->getMessage()])->save();
            throw $e;
        }
    }

    private function buildAndPost(AccountingEvent $event, PostingRule $rule, Book $book, JournalBatch $batch, bool $mayPostSoftLocked): Journal
    {
        $period = FiscalPeriod::query()->where('entity_id', $event->entity_id)->where('book_id', $book->id)
            ->where('starts', '<=', $event->transaction_date->toDateString())->where('ends', '>=', $event->transaction_date->toDateString())->first();
        if ($period === null) {
            throw new PostingFailedException('PERIOD_MISSING', "No period for {$event->transaction_date->toDateString()} in book {$book->code}");
        }
        if ($period->status === PeriodStatus::Locked) {
            throw new PostingFailedException('PERIOD_CLOSED', "Period {$period->year}-{$period->period} is locked");
        }
        if ($period->status === PeriodStatus::SoftLocked && ! $mayPostSoftLocked) {
            throw new PostingFailedException('PERIOD_SOFT_LOCKED', "Period {$period->year}-{$period->period} is soft-locked");
        }

        $journal = Journal::query()->create([
            'entity_id' => $event->entity_id, 'book_id' => $book->id, 'batch_id' => $batch->id, 'period_id' => $period->id,
            'transaction_date' => $event->transaction_date, 'posting_date' => $event->transaction_date, 'effective_date' => $event->effective_date,
            'status' => JournalStatus::Draft->value, 'kind' => JournalKind::System->value,
            'source_type' => $event->source_type, 'source_id' => $event->source_id,
            'posting_rule_code' => $rule->code, 'posting_rule_version' => $rule->version,
            'currency' => $event->currency, 'description' => $event->event_type,
        ]);

        $lineNo = 0; $dr = 0; $cr = 0;
        foreach ($rule->lines as $line) {
            $amount = $this->amounts->evaluate($line['amount'], $event->payload, $event->dimensions);
            if ($amount === 0) {
                continue;
            }
            $side = $line['side'];
            if ($amount < 0) { // negative amount flips side (adjustment decreases)
                $amount = -$amount;
                $side = $side === 'debit' ? 'credit' : 'debit';
            }
            $accountId = $this->resolveAccount($event->entity_id, $book->id, $line['role'], $event->transaction_date);
            $dims = $this->lineDimensions($event->dimensions, $line['dims'] ?? [], $event->payload);
            JournalLine::query()->create(array_merge([
                'journal_id' => $journal->id, 'line_no' => ++$lineNo, 'account_id' => $accountId, 'side' => $side,
                'amount_minor' => $amount, 'currency' => $event->currency, 'base_amount_minor' => $amount, // TODO FX (LATER)
                'role_code' => $line['role'], 'memo' => $line['memo'] ?? null,
            ], $dims));
            $side === 'debit' ? $dr += $amount : $cr += $amount;
        }

        if ($dr !== $cr) {
            throw new UnbalancedJournalException('UNBALANCED', "Rule {$rule->code} produced DR {$dr} / CR {$cr}");
        }
        if ($lineNo === 0) {
            throw new PostingFailedException('EMPTY_JOURNAL', "Rule {$rule->code} produced no lines");
        }

        $journal->forceFill([
            'number' => $this->numberer->next($event->entity_id, $book->id, $event->transaction_date),
            'status' => JournalStatus::Posted->value, 'posted_at' => now(),
        ])->save(); // DB triggers re-check balance + period here.
        return $journal;
    }

    private function resolveAccount(string $entityId, string $bookId, string $role, \Carbon\CarbonImmutable $on): string
    {
        $m = AccountRoleMapping::query()->where('entity_id', $entityId)->where('book_id', $bookId)->where('role_code', $role)
            ->where('effective_from', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $on->toDateString()))
            ->orderByDesc('effective_from')->first();
        return $m?->account_id ?? throw new PostingFailedException('UNMAPPED_ROLE', "Account role '{$role}' not mapped");
    }

    /** @param array<string,mixed> $dims */
    private function assertDimensions(PostingRule $rule, array $dims): void
    {
        foreach ($rule->requiredDimensions as $d) {
            if (! isset($dims[$d]) || $dims[$d] === '') {
                throw new PostingFailedException('DIMENSION_MISSING', "Required dimension '{$d}' missing");
            }
        }
    }

    /**
     * @param array<string,mixed> $eventDims @param array<string,string> $lineDims @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function lineDimensions(array $eventDims, array $lineDims, array $payload): array
    {
        $merged = $eventDims;
        foreach ($lineDims as $k => $expr) { // "payload.customer_id" style refs or literals
            $merged[$k] = str_starts_with($expr, 'payload.') ? ($payload[substr($expr, 8)] ?? null) : $expr;
        }
        $out = ['dims_ext' => []];
        foreach ($merged as $k => $v) {
            if (in_array($k, self::DIM_COLUMNS, true)) {
                $out['dim_'.$k] = $v;
            } elseif (! in_array($k, ['product_code'], true)) {
                $out['dims_ext'][$k] = $v;
            }
        }
        $out['dims_ext'] = $out['dims_ext'] === [] ? null : $out['dims_ext'];
        return $out;
    }
}
