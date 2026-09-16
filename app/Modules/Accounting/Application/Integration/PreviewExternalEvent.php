<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Application\Posting\AccountOverrides;
use App\Modules\Accounting\Application\Posting\JournalDraftBuilder;
use App\Modules\Accounting\Application\Posting\PostingContextLoader;
use App\Modules\Accounting\Application\PostingRuleRepository;
use App\Modules\Accounting\Domain\Models\Book;
use App\Modules\Accounting\Exceptions\AccountingException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** POST /api/v1/events/preview — draft journal lines without writes (same rules as PostingEngine). */
final class PreviewExternalEvent
{
    public function __construct(
        private readonly PostingRuleRepository $rules,
        private readonly PostingContextLoader $contexts,
        private readonly AccountOverrides $overrides,
        private readonly JournalDraftBuilder $drafts,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $dimensions
     * @return array{posts: bool, balanced: bool, reason: string|null, lines: list<array{role_code: string|null, account_code: string, account_name: string, side: string, amount_minor: int, currency: string}>}
     */
    public function preview(
        string $entityId,
        string $eventType,
        CarbonImmutable $effectiveDate,
        string $currency,
        array $payload,
        array $dimensions,
    ): array {
        try {
            $rule = $this->rules->resolve($eventType, $effectiveDate, $payload, $dimensions);
            $book = Book::query()->where('code', $rule->books[0] ?? '')->first();
            if ($book === null) {
                return self::empty('No book configured for this event type.');
            }
            $accounts = $this->overrides->apply($entityId, $this->contexts->accountsOn($entityId, (string) $book->id, $effectiveDate), $payload);
            $draft = $this->drafts->build($rule, $payload, $dimensions, $currency, $accounts);
        } catch (AccountingException $e) {
            return self::empty($e->reasonCode.': '.$e->getMessage());
        }

        $names = DB::table('accounts')->whereIn('id', array_map(fn ($line): string => $line->accountId, $draft->lines))->get(['id', 'code', 'name'])->keyBy('id');
        $lines = [];
        $debit = $credit = 0;
        foreach ($draft->lines as $line) {
            $account = $names[$line->accountId] ?? null;
            $side = $line->side->value;
            if ($side === 'debit') {
                $debit += $line->amountMinor;
            } else {
                $credit += $line->amountMinor;
            }
            $lines[] = [
                'role_code' => $line->roleCode,
                'account_code' => (string) ($account->code ?? ''),
                'account_name' => (string) ($account->name ?? ''),
                'side' => $side,
                'amount_minor' => $line->amountMinor,
                'currency' => $currency,
            ];
        }

        return ['posts' => true, 'balanced' => $debit === $credit, 'reason' => null, 'lines' => $lines];
    }

    /** @return array{posts: bool, balanced: bool, reason: string|null, lines: list<array{role_code: string|null, account_code: string, account_name: string, side: string, amount_minor: int, currency: string}>} */
    private static function empty(string $reason): array
    {
        return ['posts' => false, 'balanced' => false, 'reason' => $reason, 'lines' => []];
    }
}
