<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use App\Modules\Accounting\Application\AmountEvaluator;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Exceptions\PostingFailedException;

/**
 * Design §3.3 steps c, d and f as a pure function: rule + event data + role→account map → draft.
 * No database access, so every failure reason is unit-testable. FX (step e) is LATER: base amounts
 * equal transaction amounts.
 */
final class JournalDraftBuilder
{
    public function __construct(private readonly AmountEvaluator $amounts) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $dimensions
     * @param array<string, string> $accountsByRole role code → account id effective on the posting date
     *
     * @throws PostingFailedException DIMENSION_MISSING, UNMAPPED_ROLE, EMPTY_JOURNAL (and what AmountEvaluator raises)
     * @throws \App\Modules\Accounting\Exceptions\UnbalancedJournalException
     */
    public function build(PostingRule $rule, array $payload, array $dimensions, string $currency, array $accountsByRole): JournalDraft
    {
        $this->assertRequiredDimensions($rule, $dimensions);

        $lines = [];
        foreach ($rule->lines as $ruleLine) {
            $amount = $this->amounts->evaluate($ruleLine['amount'], $payload, $dimensions);
            if ($amount === 0) {
                continue; // zero lines are dropped (§3.3c)
            }
            $lines[] = new DraftLine(
                lineNo: count($lines) + 1,
                accountId: $accountsByRole[$ruleLine['role']] ?? throw new PostingFailedException('UNMAPPED_ROLE', "Account role '{$ruleLine['role']}' not mapped"),
                side: $this->sideFor($ruleLine['side'], $amount),
                amountMinor: $amount < 0 ? -$amount : $amount,
                currency: $currency,
                baseAmountMinor: $amount < 0 ? -$amount : $amount,
                roleCode: $ruleLine['role'],
                memo: $ruleLine['memo'] ?? null,
                dimensions: LineDimensions::forRuleLine($dimensions, $ruleLine['dims'] ?? [], $payload),
            );
        }

        return JournalDraft::balanced("Rule {$rule->code}", $lines);
    }

    /** A negative amount flips the side: an adjustment that decreases (design §4.6). */
    private function sideFor(string $ruleSide, int $amount): Side
    {
        $side = Side::from($ruleSide);

        return $amount < 0 ? $side->opposite() : $side;
    }

    /** @param array<string, mixed> $dimensions */
    private function assertRequiredDimensions(PostingRule $rule, array $dimensions): void
    {
        foreach ($rule->requiredDimensions as $code) {
            if (! isset($dimensions[$code]) || $dimensions[$code] === '') {
                throw new PostingFailedException('DIMENSION_MISSING', "Required dimension '{$code}' missing");
            }
        }
    }
}
