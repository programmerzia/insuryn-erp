<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Posting\DatabaseRuleViolation;
use Illuminate\Database\QueryException;

function checkViolation(string $message, string $sqlState = '23514'): QueryException
{
    $pdo = new PDOException("SQLSTATE[{$sqlState}]: Check violation: 7 ERROR:  {$message}");
    $pdo->errorInfo = [$sqlState, 7, $message];

    return new QueryException('pgsql', 'update "journals" set "status" = ?', ['posted'], $pdo);
}

it('maps database rule violations raised by the kernel triggers to reason codes', function (string $message, string $reasonCode): void {
    expect(DatabaseRuleViolation::reasonCode(checkViolation($message)))->toBe($reasonCode);
})->with([
    ['PERIOD_CLOSED: period 01a0 is locked', 'PERIOD_CLOSED'],
    ['PERIOD_MISSING: journal 01a0 has no period', 'PERIOD_MISSING'],
    ['UNBALANCED_JOURNAL: journal 01a0 has debits <> credits', 'UNBALANCED_JOURNAL'],
    ['EMPTY_JOURNAL: journal 01a0 has no lines', 'EMPTY_JOURNAL'],
    ['IMMUTABLE_JOURNAL: posted journal 01a0 cannot be modified', 'IMMUTABLE_JOURNAL'],
]);

it('does not classify other database errors as rule violations', function (QueryException|RuntimeException $error): void {
    expect(DatabaseRuleViolation::reasonCode($error))->toBeNull();
})->with([
    'other check violation' => fn () => checkViolation('new row violates check constraint "journal_lines_amount_positive"'),
    'code in a non-check error' => fn () => checkViolation('PERIOD_CLOSED: looks like a code', '42P01'),
    'not a query exception' => fn () => new RuntimeException('PERIOD_CLOSED: not from the database'),
]);
