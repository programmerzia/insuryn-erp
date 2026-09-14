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
    /** ASSUMPTION A-241 (addendum v2 B.2.1 PD-3 OPEN): the largest list one line group repeats over in one event. */
    public const MAX_LINE_GROUP_ITEMS = 5000;

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
            if (isset($ruleLine['for_each'])) {
                // D-100 (addendum v2 B.2.1 PD-3): the group's lines once per item of the payload list, in item order.
                foreach (self::items($ruleLine['for_each'], $payload) as $item) {
                    foreach ($ruleLine['lines'] as $groupLine) {
                        $this->addLine($lines, $groupLine, $payload, $dimensions, $currency, $accountsByRole, $item);
                    }
                }
                continue;
            }
            $this->addLine($lines, $ruleLine, $payload, $dimensions, $currency, $accountsByRole, null);
        }

        return JournalDraft::balanced("Rule {$rule->code}", $lines);
    }

    /**
     * @param list<DraftLine> $lines
     * @param array{role: string, side: string, amount: string, dims?: array<string,string>, memo?: string, account?: string} $ruleLine
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $dimensions
     * @param array<string, string> $accountsByRole
     * @param array<array-key, mixed>|null $item
     */
    private function addLine(array &$lines, array $ruleLine, array $payload, array $dimensions, string $currency, array $accountsByRole, ?array $item): void
    {
        $amount = $this->amounts->evaluate($ruleLine['amount'], $payload, $dimensions, $item);
        if ($amount === 0) {
            return; // zero lines are dropped (3.3c)
        }
        $lines[] = new DraftLine(
            lineNo: count($lines) + 1,
            accountId: self::itemAccount($ruleLine, $item) ?? $accountsByRole[$ruleLine['role']] ?? throw new PostingFailedException('UNMAPPED_ROLE', "Account role '{$ruleLine['role']}' not mapped"),
            side: $this->sideFor($ruleLine['side'], $amount),
            amountMinor: $amount < 0 ? -$amount : $amount,
            currency: $currency,
            baseAmountMinor: $amount < 0 ? -$amount : $amount,
            roleCode: $ruleLine['role'],
            memo: $ruleLine['memo'] ?? null,
            dimensions: LineDimensions::forRuleLine($dimensions, $ruleLine['dims'] ?? [], $payload, $item),
        );
    }

    /**
     * The payload list a `for_each` group repeats over. PD-3 OPEN (engineering) answered by ASSUMPTION A-241: at most MAX_LINE_GROUP_ITEMS items; larger runs are split.
     *
     * @param array<string, mixed> $payload
     * @return list<array<array-key, mixed>>
     *
     * @throws PostingFailedException PAYLOAD_FIELD_MISSING | LINE_GROUP_TOO_LARGE
     */
    public static function items(string $forEach, array $payload): array
    {
        $field = str_starts_with($forEach, 'payload.') ? substr($forEach, 8) : $forEach;
        $items = $payload[$field] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new PostingFailedException('PAYLOAD_FIELD_MISSING', "Line group repeats over '{$forEach}', which the event does not provide as a list");
        }
        $max = self::MAX_LINE_GROUP_ITEMS;
        if (count($items) > $max) {
            throw new PostingFailedException('LINE_GROUP_TOO_LARGE', "Line group '{$forEach}' has ".count($items)." items; at most {$max} post in one event");
        }

        return array_map(fn (mixed $item): array => is_array($item) ? $item : ['value' => $item], $items);
    }

    /**
     * PD-4: a group line naming `account: "item.<field>"` posts to the item's account when the item gives one (AccountOverrides::assertItemAccounts has
     * checked it before building); otherwise to the role's mapped account.
     *
     * @param array{account?: string} $ruleLine
     * @param array<array-key, mixed>|null $item
     */
    private static function itemAccount(array $ruleLine, ?array $item): ?string
    {
        if ($item === null || ! isset($ruleLine['account']) || ! str_starts_with($ruleLine['account'], 'item.')) {
            return null;
        }
        $account = $item[substr($ruleLine['account'], 5)] ?? null;

        return is_string($account) && $account !== '' ? $account : null;
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
