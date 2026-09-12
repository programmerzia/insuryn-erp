<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Exceptions\PostingFailedException;
use App\Modules\Accounting\Exceptions\UnbalancedJournalException;

/**
 * The lines of a journal that is ready to be written. Cannot be constructed unbalanced or empty
 * (CONTEXT.md non-negotiable #1, design §3.3 step f); the DB trigger re-checks on posting.
 */
final readonly class JournalDraft
{
    /** @param list<DraftLine> $lines */
    private function __construct(public array $lines) {}

    /**
     * @param string $origin names the source in failure reasons, e.g. "Rule PREMIUM_RECEIVED.default"
     * @param list<DraftLine> $lines
     *
     * @throws UnbalancedJournalException when debits and credits differ in any currency
     * @throws PostingFailedException EMPTY_JOURNAL when there are no lines
     */
    public static function balanced(string $origin, array $lines): self
    {
        foreach (self::totalsByCurrency($lines) as $currency => [$debit, $credit]) {
            if ($debit !== $credit) {
                throw new UnbalancedJournalException('UNBALANCED', "{$origin} produced DR {$debit} / CR {$credit} in {$currency}");
            }
        }
        if ($lines === []) {
            throw new PostingFailedException('EMPTY_JOURNAL', "{$origin} produced no lines");
        }

        return new self($lines);
    }

    /**
     * @param list<DraftLine> $lines
     * @return array<string, array{int, int}> currency → [debit, credit]
     */
    private static function totalsByCurrency(array $lines): array
    {
        $totals = [];
        foreach ($lines as $line) {
            $totals[$line->currency] ??= [0, 0];
            $totals[$line->currency][$line->side === Side::Debit ? 0 : 1] += $line->amountMinor;
        }

        return $totals;
    }
}
