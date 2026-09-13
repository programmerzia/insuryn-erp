<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use App\Modules\Accounting\Domain\Enums\PeriodStatus;
use App\Modules\Accounting\Domain\Models\AccountRoleMapping;
use App\Modules\Accounting\Domain\Models\FiscalPeriod;
use App\Modules\Accounting\Exceptions\PostingFailedException;
use Carbon\CarbonImmutable;

/** Design §3.3 steps a and b: one query for the period, one for every role mapping of the book. */
final class PostingContextLoader
{
    /** @throws PostingFailedException PERIOD_MISSING, PERIOD_CLOSED, PERIOD_SOFT_LOCKED */
    public function load(string $entityId, string $bookId, CarbonImmutable $postingDate, bool $actorMayPostSoftLocked): PostingContext
    {
        $period = $this->period($entityId, $bookId, $postingDate, $actorMayPostSoftLocked);

        return new PostingContext($period, $this->accountsByRole($entityId, $bookId, $postingDate));
    }

    /**
     * The period that accepts a posting on the date. CONTEXT.md non-negotiable #3: locked rejects,
     * soft_locked needs accounting.post_in_soft_locked (the caller resolves the permission). Inside a transaction the period row stays
     * share-locked until commit.
     *
     * @throws PostingFailedException PERIOD_MISSING, PERIOD_CLOSED, PERIOD_SOFT_LOCKED
     */
    public function period(string $entityId, string $bookId, CarbonImmutable $on, bool $actorMayPostSoftLocked): FiscalPeriod
    {
        // FOR SHARE for the rest of the posting transaction: a period lock (FOR UPDATE, FiscalPeriodService) waits for postings in flight and
        // postings wait for a lock in progress, so no posting commits into a period between the lock's reconciliation check and its commit.
        $period = FiscalPeriod::query()->where('entity_id', $entityId)->where('book_id', $bookId)
            ->where('starts', '<=', $on->toDateString())->where('ends', '>=', $on->toDateString())->sharedLock()->first();
        if ($period === null) {
            throw new PostingFailedException('PERIOD_MISSING', "No period for {$on->toDateString()} in book {$bookId}");
        }
        if ($period->status === PeriodStatus::Locked) {
            throw new PostingFailedException('PERIOD_CLOSED', "Period {$period->year}-{$period->period} is locked");
        }
        if ($period->status === PeriodStatus::SoftLocked && ! $actorMayPostSoftLocked) {
            throw new PostingFailedException('PERIOD_SOFT_LOCKED', "Period {$period->year}-{$period->period} is soft-locked");
        }

        return $period;
    }

    /**
     * Every mapping effective on the date, in one query; when a role has several the latest
     * effective_from wins (ascending order, later rows overwrite).
     *
     * @return array<string, string> role code → account id
     */
    private function accountsByRole(string $entityId, string $bookId, CarbonImmutable $on): array
    {
        $mappings = AccountRoleMapping::query()->where('entity_id', $entityId)->where('book_id', $bookId)
            ->where('effective_from', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $on->toDateString()))
            ->orderBy('effective_from')->get(['role_code', 'account_id']);

        $accounts = [];
        foreach ($mappings as $mapping) {
            $accounts[(string) $mapping->role_code] = (string) $mapping->account_id;
        }

        return $accounts;
    }
}
