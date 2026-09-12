<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\AmountEvaluator;
use App\Modules\Accounting\Application\Expressions\ExpressionScope;
use App\Modules\Accounting\Application\Expressions\PostingFunctionProvider;
use App\Modules\Accounting\Exceptions\PostingFailedException;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

function postingExpressions(): ExpressionLanguage
{
    return new ExpressionLanguage(null, [new PostingFunctionProvider()]);
}

/** Reason code of the posting failure raised by $operation, or null when it does not fail. */
function postingFailureReason(callable $operation): ?string
{
    try {
        $operation();
    } catch (PostingFailedException $failure) {
        return $failure->reasonCode;
    }

    return null;
}

it('evaluates rule amount expressions over the payload in minor units', function (): void {
    $amounts = new AmountEvaluator(postingExpressions());
    $payload = ['base' => 5_000_000, 'rate_bp' => 1_000, 'withholding_bp' => 500];

    expect($amounts->evaluate('payload.base', $payload, []))->toBe(5_000_000)
        ->and($amounts->evaluate('sub(pct(payload.base, payload.rate_bp), pct(pct(payload.base, payload.rate_bp), payload.withholding_bp))', $payload, []))->toBe(475_000)
        ->and($amounts->evaluate('add(payload.base, max(1, 2))', $payload, []))->toBe(5_000_002);
});

it('fails the posting with PAYLOAD_FIELD_MISSING when an expression reads an absent payload field', function (): void {
    $amounts = new AmountEvaluator(postingExpressions());

    expect(postingFailureReason(fn () => $amounts->evaluate('payload.amount', ['gross' => 1], [])))->toBe('PAYLOAD_FIELD_MISSING');
});

it('fails the posting with DIMENSION_MISSING when an expression reads an absent dimension', function (): void {
    $scope = ExpressionScope::forEvent([], ['branch' => 'b1']);

    expect(postingFailureReason(fn () => postingExpressions()->evaluate('dims.policy', $scope)))->toBe('DIMENSION_MISSING');
});

it('lets the null-coalescing operator treat absent fields as null', function (): void {
    $scope = ExpressionScope::forEvent(['amount' => 10], []);

    expect(postingExpressions()->evaluate('payload.discount ?? 0', $scope))->toBe(0);
});

it('rejects non-integer amounts because amounts are minor units', function (): void {
    $amounts = new AmountEvaluator(postingExpressions());

    expect(postingFailureReason(fn () => $amounts->evaluate('payload.amount', ['amount' => '1000'], [])))->toBe('AMOUNT_NOT_INTEGER');
});

it('exposes nested payload objects as scopes', function (): void {
    $scope = ExpressionScope::forEvent(['tax' => ['vat' => 1_565]], []);

    expect(postingExpressions()->evaluate('payload.tax.vat', $scope))->toBe(1_565);
});

it('supports posting functions in rule conditions without an AmountEvaluator being built first', function (): void {
    $scope = ExpressionScope::forEvent(['base' => 10_000, 'rate_bp' => 1_000], []);

    expect(postingExpressions()->evaluate('pct(payload.base, payload.rate_bp) > 500', $scope))->toBeTrue();
});
