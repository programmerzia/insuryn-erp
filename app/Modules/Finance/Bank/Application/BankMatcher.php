<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Application;

use App\Modules\Accounting\Application\Queries\AccountLineQuery;
use App\Modules\Finance\Bank\Domain\Models\BankAccount;
use App\Modules\Finance\Bank\Domain\Models\BankStatementLine;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Matches bank statement lines to the posted journal lines of the bank account's GL account (design §2.4 bank_matches).
 * Auto: same signed amount, posting date within erp.bank.auto_match_date_window_days, and the journal's reference or receipt
 * number appearing in the statement text; only one-to-one unambiguous pairs are matched. Manual: one statement line to one or
 * more journal lines summing to its amount. Lines with no ledger counterpart (bank charges) are explained with a reason.
 */
final class BankMatcher
{
    private const MIN_REFERENCE_LENGTH = 4;

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly AccountLineQuery $ledger,
        private readonly Audit $audit,
    ) {}

    /**
     * Suggested matches for the matching screen (UX brief §6.4), never recorded: an unmatched journal line of the same amount within the auto-match
     * window scores 60; one whose receipt number or reference appears on the statement line scores 100. Best first.
     *
     * @return list<array{statement_line_id: string, journal_line_id: string, confidence: int, why: string}>
     */
    public function suggestions(string $bankAccountId, CarbonImmutable $asOf): array
    {
        $bankAccount = BankAccount::query()->findOrFail($bankAccountId);
        $statementLines = BankStatementLine::query()->where('bank_account_id', $bankAccountId)->where('match_status', 'unmatched')->where('posted_on', '<=', $asOf->toDateString())->orderBy('posted_on')->get();
        if ($statementLines->isEmpty()) {
            return [];
        }
        $window = (int) config('erp.bank.auto_match_date_window_days', 3);
        $journalLines = $this->unmatchedJournalLines($bankAccount->gl_account_id, $statementLines->min('posted_on')?->subDays($window), $asOf);
        $suggestions = [];
        foreach ($statementLines as $statementLine) {
            $text = self::normalise($statementLine->reference.' '.$statementLine->description);
            foreach ($journalLines as $line) {
                $days = abs((int) $statementLine->posted_on->diffInDays(CarbonImmutable::parse($line['posting_date']), false));
                if ($line['amount_minor'] !== $statementLine->amount_minor || $days > $window) {
                    continue;
                }
                $mentioned = self::mentions($text, $line['reference'], $line['receipt_number']);
                $apart = $days === 0 ? 'same day' : ($days === 1 ? '1 day apart' : "{$days} days apart");
                $suggestions[] = ['statement_line_id' => $statementLine->id, 'journal_line_id' => $line['journal_line_id'], 'confidence' => $mentioned ? 100 : 60,
                    'why' => 'Same amount, '.$apart.($mentioned ? ', reference '.($line['reference'] ?? $line['receipt_number']) : '')];
            }
        }
        usort($suggestions, fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return $suggestions;
    }

    /** Returns how many statement lines were matched. Run by the import screen and safe to repeat. */
    public function autoMatch(string $bankAccountId): int
    {
        $bankAccount = BankAccount::query()->findOrFail($bankAccountId);
        $statementLines = BankStatementLine::query()->where('bank_account_id', $bankAccountId)->where('match_status', 'unmatched')->orderBy('posted_on')->get();
        if ($statementLines->isEmpty()) {
            return 0;
        }
        $window = (int) config('erp.bank.auto_match_date_window_days', 3);
        $journalLines = $this->unmatchedJournalLines($bankAccount->gl_account_id,
            $statementLines->min('posted_on')?->subDays($window), $statementLines->max('posted_on')?->addDays($window) ?? CarbonImmutable::today());

        $candidates = [];
        $claims = [];
        foreach ($statementLines as $statementLine) {
            $text = self::normalise($statementLine->reference.' '.$statementLine->description);
            $candidates[$statementLine->id] = array_values(array_filter($journalLines, fn (array $line): bool => $line['amount_minor'] === $statementLine->amount_minor
                && abs((int) $statementLine->posted_on->diffInDays(CarbonImmutable::parse($line['posting_date']), false)) <= $window
                && self::mentions($text, $line['reference'], $line['receipt_number'])));
            foreach ($candidates[$statementLine->id] as $line) {
                $claims[$line['journal_line_id']] = ($claims[$line['journal_line_id']] ?? 0) + 1;
            }
        }

        $matched = 0;
        foreach ($statementLines as $statementLine) {
            $lines = $candidates[$statementLine->id];
            if (count($lines) !== 1 || $claims[$lines[0]['journal_line_id']] !== 1) {
                continue;
            }
            DB::transaction(function () use ($statementLine, $lines): void {
                $this->record($statementLine, [$lines[0]['journal_line_id']], 'auto', 100, null);
            });
            $matched++;
        }

        return $matched;
    }

    /**
     * @param list<string> $journalLineIds
     *
     * @throws BusinessRuleViolation ALREADY_MATCHED | JOURNAL_LINE_NOT_IN_BANK_ACCOUNT | JOURNAL_LINE_ALREADY_MATCHED | MATCH_AMOUNT_MISMATCH
     */
    public function match(string $statementLineId, array $journalLineIds, string $actorUserId): void
    {
        [$bankAccount] = $this->authorize($statementLineId, $actorUserId);
        $journalLineIds = array_values(array_unique($journalLineIds));

        DB::transaction(function () use ($statementLineId, $journalLineIds, $bankAccount, $actorUserId): void {
            $statementLine = $this->lockUnmatched($statementLineId);
            $lines = $this->ledger->postedLinesByIds($bankAccount->gl_account_id, $journalLineIds);
            if ($journalLineIds === [] || count($lines) !== count($journalLineIds)) {
                throw new BusinessRuleViolation('JOURNAL_LINE_NOT_IN_BANK_ACCOUNT', 'Every journal line must be a posted line on the bank account\'s GL account.');
            }
            if (DB::table('bank_matches')->whereIn('journal_line_id', $journalLineIds)->exists()) {
                throw new BusinessRuleViolation('JOURNAL_LINE_ALREADY_MATCHED', 'A journal line is already matched to a statement line.');
            }
            $total = array_sum(array_column($lines, 'amount_minor'));
            if ($total !== $statementLine->amount_minor) {
                throw new BusinessRuleViolation('MATCH_AMOUNT_MISMATCH', "The journal lines total {$total}; the statement line is {$statementLine->amount_minor}.");
            }
            $this->record($statementLine, $journalLineIds, 'manual', null, $actorUserId);
        });
    }

    /** @throws BusinessRuleViolation REASON_REQUIRED | ALREADY_MATCHED */
    public function explain(string $statementLineId, string $reason, string $actorUserId): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'Explaining an unmatched bank line needs a reason.');
        }
        $this->authorize($statementLineId, $actorUserId);

        DB::transaction(function () use ($statementLineId, $reason, $actorUserId): void {
            $statementLine = $this->lockUnmatched($statementLineId);
            $statementLine->forceFill(['match_status' => 'explained', 'explanation' => $reason, 'explained_by' => $actorUserId])->save();
            $this->audit->record('bank_line.explained', AuditSubject::of('bank_statement_line', $statementLine->id), ['match_status' => 'unmatched'],
                ['match_status' => 'explained'], $reason, 'bank.match', Actor::user($actorUserId));
        });
    }

    /**
     * @return list<array{journal_line_id: string, journal_id: string, journal_number: string|null, posting_date: string, amount_minor: int, currency: string,
     *     reference: string|null, receipt_number: string|null, source_type: string|null, source_id: string|null}>
     */
    public function unmatchedJournalLines(string $glAccountId, ?CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lines = $this->ledger->postedLines($glAccountId, $from, $to);
        $matched = array_flip(DB::table('bank_matches')->whereIn('journal_line_id', array_column($lines, 'journal_line_id'))->pluck('journal_line_id')
            ->map(fn ($id): string => (string) $id)->all());

        return array_values(array_filter($lines, fn (array $line): bool => ! isset($matched[$line['journal_line_id']])));
    }

    /** @return array{0: BankAccount} */
    private function authorize(string $statementLineId, string $actorUserId): array
    {
        $statementLine = BankStatementLine::query()->findOrFail($statementLineId);
        $bankAccount = BankAccount::query()->findOrFail($statementLine->bank_account_id);
        $this->permissions->authorize($actorUserId, 'bank.match', AuthorizationScope::entity($bankAccount->entity_id));

        return [$bankAccount];
    }

    private function lockUnmatched(string $statementLineId): BankStatementLine
    {
        $statementLine = BankStatementLine::query()->whereKey($statementLineId)->lockForUpdate()->firstOrFail();
        if ($statementLine->match_status !== 'unmatched') {
            throw new BusinessRuleViolation('ALREADY_MATCHED', "Statement line {$statementLine->id} is already {$statementLine->match_status}.");
        }

        return $statementLine;
    }

    /** @param list<string> $journalLineIds */
    private function record(BankStatementLine $statementLine, array $journalLineIds, string $method, ?int $confidence, ?string $actorUserId): void
    {
        $now = CarbonImmutable::now();
        DB::table('bank_matches')->insert(array_map(fn (string $journalLineId): array => ['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(),
            'statement_line_id' => $statementLine->id, 'journal_line_id' => $journalLineId, 'matched_by' => $actorUserId, 'method' => $method,
            'confidence' => $confidence, 'matched_at' => $now], $journalLineIds));
        $statementLine->forceFill(['match_status' => 'matched'])->save();
        $this->audit->record('bank_line.matched', AuditSubject::of('bank_statement_line', $statementLine->id), ['match_status' => 'unmatched'],
            ['match_status' => 'matched', 'method' => $method, 'journal_line_ids' => $journalLineIds], null, 'bank.match',
            $actorUserId === null ? Actor::system() : Actor::user($actorUserId));
    }

    private static function normalise(string $text): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($text));
    }

    private static function mentions(string $normalisedText, ?string ...$references): bool
    {
        foreach ($references as $reference) {
            $token = self::normalise((string) $reference);
            if (strlen($token) >= self::MIN_REFERENCE_LENGTH && str_contains($normalisedText, $token)) {
                return true;
            }
        }

        return false;
    }
}
