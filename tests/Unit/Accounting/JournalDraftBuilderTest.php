<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\AmountEvaluator;
use App\Modules\Accounting\Application\Expressions\PostingFunctionProvider;
use App\Modules\Accounting\Application\Posting\DraftLine;
use App\Modules\Accounting\Application\Posting\JournalDraftBuilder;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Exceptions\PostingFailedException;
use App\Modules\Accounting\Exceptions\UnbalancedJournalException;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/** Design §3.3 steps c, d and f as a pure function of rule, event data and the role→account map. */
function draftBuilder(): JournalDraftBuilder
{
    return new JournalDraftBuilder(new AmountEvaluator(new ExpressionLanguage(null, [new PostingFunctionProvider()])));
}

/**
 * @param list<array{role: string, side: string, amount: string, dims?: array<string,string>, memo?: string}> $lines
 * @param list<string> $requiredDimensions
 */
function ruleWithLines(array $lines, array $requiredDimensions = []): PostingRule
{
    return PostingRule::fromArray([
        'code' => 'TEST.default', 'version' => 1, 'event_type' => 'TEST', 'effective_from' => '2026-01-01',
        'lines' => $lines, 'dimensions' => ['required' => $requiredDimensions],
    ]);
}

const ACCOUNTS_BY_ROLE = ['bank_main' => 'acc-bank', 'premium_receivable' => 'acc-receivable', 'claims_expense' => 'acc-claims', 'claims_outstanding' => 'acc-reserve'];

/** @return list<array{int, string, string, int}> */
function summarise(array $lines): array
{
    return array_map(fn (DraftLine $l): array => [$l->lineNo, $l->roleCode ?? '', $l->side->value, $l->amountMinor], $lines);
}

it('resolves accounts, sides and amounts into consecutively numbered lines', function (): void {
    $rule = ruleWithLines([
        ['role' => 'bank_main', 'side' => 'debit', 'amount' => 'payload.amount', 'memo' => 'cash in'],
        ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 'payload.amount'],
    ]);

    $draft = draftBuilder()->build($rule, ['amount' => 1_000], [], 'BDT', ACCOUNTS_BY_ROLE);

    expect(summarise($draft->lines))->toBe([[1, 'bank_main', 'debit', 1_000], [2, 'premium_receivable', 'credit', 1_000]])
        ->and($draft->lines[0]->accountId)->toBe('acc-bank')
        ->and($draft->lines[0]->currency)->toBe('BDT')
        ->and($draft->lines[0]->baseAmountMinor)->toBe(1_000)
        ->and($draft->lines[0]->memo)->toBe('cash in')
        ->and($draft->lines[1]->accountId)->toBe('acc-receivable')
        ->and($draft->lines[1]->memo)->toBeNull();
});

it('flips the side of a negative amount, so a reserve decrease is a mirrored reserve increase', function (): void {
    $rule = ruleWithLines([
        ['role' => 'claims_expense', 'side' => 'debit', 'amount' => 'payload.delta'],
        ['role' => 'claims_outstanding', 'side' => 'credit', 'amount' => 'payload.delta'],
    ]);

    $draft = draftBuilder()->build($rule, ['delta' => -500], [], 'BDT', ACCOUNTS_BY_ROLE);

    expect(summarise($draft->lines))->toBe([[1, 'claims_expense', 'credit', 500], [2, 'claims_outstanding', 'debit', 500]]);
});

it('drops zero-amount lines and keeps line numbers consecutive', function (): void {
    $rule = ruleWithLines([
        ['role' => 'bank_main', 'side' => 'debit', 'amount' => 'payload.amount'],
        ['role' => 'claims_expense', 'side' => 'debit', 'amount' => 'payload.nothing'],
        ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 'payload.amount'],
    ]);

    $draft = draftBuilder()->build($rule, ['amount' => 700, 'nothing' => 0], [], 'BDT', ACCOUNTS_BY_ROLE);

    expect(summarise($draft->lines))->toBe([[1, 'bank_main', 'debit', 700], [2, 'premium_receivable', 'credit', 700]]);
});

it('refuses an unbalanced draft with UNBALANCED', function (): void {
    $rule = ruleWithLines([
        ['role' => 'bank_main', 'side' => 'debit', 'amount' => 'payload.amount'],
        ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 'sub(payload.amount, 1)'],
    ]);

    expect(fn () => draftBuilder()->build($rule, ['amount' => 1_000], [], 'BDT', ACCOUNTS_BY_ROLE))
        ->toThrow(fn (UnbalancedJournalException $e) => expect($e->reasonCode)->toBe('UNBALANCED'));
});

it('refuses a draft without lines with EMPTY_JOURNAL', function (): void {
    $rule = ruleWithLines([
        ['role' => 'bank_main', 'side' => 'debit', 'amount' => 'payload.amount'],
        ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 'payload.amount'],
    ]);

    expect(fn () => draftBuilder()->build($rule, ['amount' => 0], [], 'BDT', ACCOUNTS_BY_ROLE))
        ->toThrow(fn (PostingFailedException $e) => expect($e->reasonCode)->toBe('EMPTY_JOURNAL'));
});

it('fails with UNMAPPED_ROLE when a line role has no account', function (): void {
    $rule = ruleWithLines([
        ['role' => 'bank_main', 'side' => 'debit', 'amount' => 'payload.amount'],
        ['role' => 'unearned_premium', 'side' => 'credit', 'amount' => 'payload.amount'],
    ]);

    expect(fn () => draftBuilder()->build($rule, ['amount' => 10], [], 'BDT', ACCOUNTS_BY_ROLE))
        ->toThrow(fn (PostingFailedException $e) => expect($e->reasonCode)->toBe('UNMAPPED_ROLE')->and($e->getMessage())->toContain('unearned_premium'));
});

it('fails with DIMENSION_MISSING when a required dimension is absent or blank', function (array $dimensions): void {
    $rule = ruleWithLines([
        ['role' => 'bank_main', 'side' => 'debit', 'amount' => 'payload.amount'],
        ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 'payload.amount'],
    ], ['branch', 'policy']);

    expect(fn () => draftBuilder()->build($rule, ['amount' => 10], $dimensions, 'BDT', ACCOUNTS_BY_ROLE))
        ->toThrow(fn (PostingFailedException $e) => expect($e->reasonCode)->toBe('DIMENSION_MISSING')->and($e->getMessage())->toContain('policy'));
})->with([
    'absent' => [['branch' => 'b1']],
    'blank' => [['branch' => 'b1', 'policy' => '']],
]);

it('inherits event dimensions, applies line dimensions, drops product_code and splits columns from dims_ext', function (): void {
    $rule = ruleWithLines([
        ['role' => 'bank_main', 'side' => 'debit', 'amount' => 'payload.amount'],
        ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 'payload.amount', 'dims' => ['customer' => 'payload.customer_id', 'channel' => 'direct']],
    ]);
    $eventDimensions = ['branch' => 'b1', 'policy' => 'p1', 'product_code' => 'MOTOR', 'channel' => 'agent', 'receipt' => 'r1'];

    $draft = draftBuilder()->build($rule, ['amount' => 10, 'customer_id' => 'c1'], $eventDimensions, 'BDT', ACCOUNTS_BY_ROLE);

    expect($draft->lines[0]->dimensions->values)->toBe(['branch' => 'b1', 'policy' => 'p1', 'channel' => 'agent', 'receipt' => 'r1'])
        ->and($draft->lines[1]->dimensions->values)->toBe(['branch' => 'b1', 'policy' => 'p1', 'channel' => 'direct', 'receipt' => 'r1', 'customer' => 'c1'])
        ->and($draft->lines[1]->dimensions->toColumns())->toBe([
            'dim_branch' => 'b1', 'dim_product' => null, 'dim_lob' => null, 'dim_channel' => 'direct', 'dim_agent' => null, 'dim_policy' => 'p1',
            'dim_claim' => null, 'dim_cost_centre' => null, 'dim_employee' => null, 'dim_customer' => 'c1', 'dim_reinsurer' => null,
            'dims_ext' => ['receipt' => 'r1'],
        ]);
});

it('stores no dims_ext when every dimension has a column', function (): void {
    $rule = ruleWithLines([
        ['role' => 'bank_main', 'side' => 'debit', 'amount' => 'payload.amount'],
        ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 'payload.amount'],
    ]);

    $draft = draftBuilder()->build($rule, ['amount' => 10], ['branch' => 'b1'], 'BDT', ACCOUNTS_BY_ROLE);

    expect($draft->lines[0]->dimensions->toColumns()['dims_ext'])->toBeNull();
});

it('mirrors a line by flipping only its side', function (): void {
    $rule = ruleWithLines([
        ['role' => 'bank_main', 'side' => 'debit', 'amount' => 'payload.amount'],
        ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 'payload.amount'],
    ]);
    $line = draftBuilder()->build($rule, ['amount' => 10], ['branch' => 'b1'], 'BDT', ACCOUNTS_BY_ROLE)->lines[0];

    $mirrored = $line->mirrored();

    expect($mirrored->side)->toBe(Side::Credit)
        ->and([$mirrored->lineNo, $mirrored->accountId, $mirrored->amountMinor, $mirrored->roleCode, $mirrored->dimensions->values])
        ->toBe([$line->lineNo, $line->accountId, $line->amountMinor, $line->roleCode, $line->dimensions->values]);
});
