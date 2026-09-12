<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\JournalStatus;
use App\Modules\Accounting\Domain\Enums\PeriodStatus;
use App\Modules\Accounting\Domain\Models\FiscalPeriod;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Accounting\Exceptions\PostingFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Design §2.3: reversal = mirrored lines, new journal, links both ways; original never edited. */
final class ReversalService
{
    public function __construct(private readonly JournalNumberer $numberer) {}

    public function reverse(Journal $original, CarbonImmutable $on, string $reason, string $actorUserId): Journal
    {
        if ($original->status !== JournalStatus::Posted) {
            throw new PostingFailedException('NOT_POSTED', 'Only posted journals can be reversed');
        }
        if ($reason === '') {
            throw new PostingFailedException('REASON_REQUIRED', 'Reversal requires a reason');
        }
        return DB::transaction(function () use ($original, $on, $reason, $actorUserId): Journal {
            $period = FiscalPeriod::query()->where('entity_id', $original->entity_id)->where('book_id', $original->book_id)
                ->where('starts', '<=', $on->toDateString())->where('ends', '>=', $on->toDateString())->firstOrFail();
            if ($period->status === PeriodStatus::Locked) {
                throw new PostingFailedException('PERIOD_CLOSED', 'Cannot reverse into a locked period');
            }
            $rev = Journal::query()->create([
                'entity_id' => $original->entity_id, 'book_id' => $original->book_id, 'batch_id' => $original->batch_id, 'period_id' => $period->id,
                'transaction_date' => $on, 'posting_date' => $on, 'effective_date' => $on,
                'status' => JournalStatus::Draft->value, 'kind' => JournalKind::Reversal->value,
                'reverses_journal_id' => $original->id, 'original_transaction_id' => $original->source_id,
                'reason' => $reason, 'source_type' => $original->source_type, 'source_id' => $original->source_id,
                'currency' => $original->currency, 'created_by' => $actorUserId, 'description' => 'Reversal of '.$original->number,
            ]);
            foreach ($original->lines as $l) {
                JournalLine::query()->create(array_merge($l->only(['account_id','amount_minor','currency','base_amount_minor','role_code','memo',
                    'dim_branch','dim_product','dim_lob','dim_channel','dim_agent','dim_policy','dim_claim','dim_cost_centre','dim_employee','dim_customer','dim_reinsurer','dims_ext']),
                    ['journal_id' => $rev->id, 'line_no' => $l->line_no, 'side' => $l->side->value === 'debit' ? 'credit' : 'debit']));
            }
            $rev->forceFill(['number' => $this->numberer->next($original->entity_id, $original->book_id, $on), 'status' => JournalStatus::Posted->value, 'posted_at' => now()])->save();
            // Only these two columns may change on a posted journal (DB trigger enforces).
            $original->forceFill(['status' => JournalStatus::Reversed->value, 'reversed_by_journal_id' => $rev->id])->save();
            return $rev;
        });
    }
}
