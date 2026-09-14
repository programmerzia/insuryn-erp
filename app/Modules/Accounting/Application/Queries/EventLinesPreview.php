<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Queries;

use App\Modules\Accounting\Application\Posting\AccountOverrides;
use App\Modules\Accounting\Application\Posting\JournalDraftBuilder;
use App\Modules\Accounting\Application\Posting\PostingContextLoader;
use App\Modules\Accounting\Application\PostingRuleRepository;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Domain\Models\Book;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Platform\Money\MinorUnits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap fixes W7 (GA-04 remainder, GA-24): the journal lines an accounting event WOULD post, for an approver who decides before anything is submitted —
 * a claim payment approval, a premium write-off. The same rule resolution, role mappings, account overrides and draft builder as the PostingEngine, on
 * the rule's first book, without the period check and without writing anything. Empty when the event cannot be posted as it stands (no rule, a role not
 * mapped): the approve preview then says why.
 */
final class EventLinesPreview
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
     * @return list<array{account: string, name: string, debit: string|null, credit: string|null}>
     */
    public function lines(string $entityId, string $eventType, CarbonImmutable $date, string $currency, array $payload, array $dimensions): array
    {
        try {
            $rule = $this->rules->resolve($eventType, $date, $payload, $dimensions);
            $book = Book::query()->where('code', $rule->books[0] ?? '')->first();
            if ($book === null) {
                return [];
            }
            $accounts = $this->overrides->apply($entityId, $this->contexts->accountsOn($entityId, (string) $book->id, $date), $payload);
            $draft = $this->drafts->build($rule, $payload, $dimensions, $currency, $accounts);
        } catch (AccountingException) {
            return [];
        }
        $names = DB::table('accounts')->whereIn('id', array_map(fn ($line): string => $line->accountId, $draft->lines))->get(['id', 'code', 'name'])->keyBy('id');
        $lines = [];
        foreach ($draft->lines as $line) {
            $account = $names[$line->accountId] ?? null;
            $amount = MinorUnits::format($line->amountMinor, $currency);
            $debit = $line->side === Side::Debit;
            $lines[] = ['account' => (string) ($account->code ?? ''), 'name' => (string) ($account->name ?? ''), 'debit' => $debit ? $amount : null, 'credit' => $debit ? null : $amount];
        }

        return $lines;
    }
}
