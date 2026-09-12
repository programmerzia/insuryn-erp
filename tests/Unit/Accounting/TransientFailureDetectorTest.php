<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Posting\TransientFailureDetector;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;

/** Design §8.4: only deadlocks, serialization failures, lock timeouts and lost connections are retried. */
function detector(): TransientFailureDetector
{
    return new TransientFailureDetector(new LostConnectionDetector());
}

function queryExceptionWithSqlState(string $sqlState, string $message = 'database error'): QueryException
{
    $pdo = new PDOException("SQLSTATE[{$sqlState}]: {$message}");
    $pdo->errorInfo = [$sqlState, 7, $message];

    return new QueryException('pgsql', 'update accounting_events set status = ?', ['posting'], $pdo);
}

it('treats retryable Postgres SQLSTATEs as transient', function (string $sqlState): void {
    expect(detector()->isTransient(queryExceptionWithSqlState($sqlState)))->toBeTrue();
})->with([
    'serialization_failure' => '40001',
    'deadlock_detected' => '40P01',
    'lock_not_available' => '55P03',
]);

it('treats a lost connection as transient', function (): void {
    $lost = queryExceptionWithSqlState('08006', 'server closed the connection unexpectedly');

    expect(detector()->isTransient($lost))->toBeTrue();
});

it('finds a transient cause anywhere in the previous chain', function (): void {
    $wrapped = new RuntimeException('posting aborted', 0, queryExceptionWithSqlState('40P01', 'deadlock detected'));

    expect(detector()->isTransient($wrapped))->toBeTrue();
});

it('does not treat constraint violations or programming errors as transient', function (Throwable $e): void {
    expect(detector()->isTransient($e))->toBeFalse();
})->with([
    'check_violation' => fn () => queryExceptionWithSqlState('23514', 'UNBALANCED_JOURNAL'),
    'unique_violation' => fn () => queryExceptionWithSqlState('23505', 'duplicate key'),
    'type error' => fn () => new TypeError('pct(): Argument #2 must be of type int, string given'),
    'runtime error' => fn () => new RuntimeException('No tenant context.'),
]);
