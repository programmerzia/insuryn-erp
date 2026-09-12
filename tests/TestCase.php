<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Collection;
use LogicException;
use RuntimeException;

/**
 * Kernel invariants are properties of committed Postgres behaviour (constraint triggers, aborted
 * transactions, row-level security), so tests run for real and the database is truncated between
 * tests instead of being wrapped in a rolled-back transaction.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTruncation;

    /** A role that bypasses RLS would make every tenant-isolation assertion pass vacuously. */
    protected function beforeTruncatingDatabase(): void
    {
        $bypassesRowLevelSecurity = $this->app->make('db')->connection()
            ->scalar('select rolsuper or rolbypassrls from pg_roles where rolname = current_user');

        if ($bypassesRowLevelSecurity !== false) {
            throw new RuntimeException('Tests must connect as a NOSUPERUSER NOBYPASSRLS role, otherwise row-level security is not under test.');
        }
    }

    /**
     * One TRUNCATE for every table. The framework's per-table `exists()` probe is itself filtered by
     * row-level security, so tenant tables would look empty and never be cleared; TRUNCATE is not.
     */
    protected function truncateTablesForConnection(ConnectionInterface $connection, ?string $name): void
    {
        if (! $connection instanceof Connection) {
            throw new LogicException('Truncation needs a query-grammar connection, got '.$connection::class.'.');
        }
        $exceptTables = $this->exceptTables($connection, $name);
        $grammar = $connection->getQueryGrammar();

        $tables = (new Collection($this->getAllTablesForConnection($connection, $name)))
            ->reject(fn (array $table): bool => $this->tableExistsIn($table, $exceptTables))
            ->map(fn (array $table): string => $grammar->wrapTable($table['schema_qualified_name']));

        if ($tables->isNotEmpty()) {
            $connection->statement('truncate table '.$tables->implode(', ').' restart identity cascade');
        }
    }
}
