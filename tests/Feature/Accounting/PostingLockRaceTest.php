<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Review finding (medium): a period lock re-checks reconciliation under the period's row lock (§5.7), but postings did not take that lock, so a
 * posting could commit into the period between the lock's check and its commit. Posting now holds the period row FOR SHARE for its transaction:
 * while a lock transaction holds the row FOR UPDATE, posting waits (and, past lock_timeout, fails transiently and is retried), and vice versa.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant();
    config(['database.connections.pgsql_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function (): void {
    DB::connection('pgsql_race')->rollBack();
    DB::purge('pgsql_race');
    DB::statement('RESET lock_timeout');
});

it('makes a posting wait for a transaction holding the period row, then post once it is released', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)($this->ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'race-lock',
            CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 5_000],
            ['branch' => $this->ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()]));
        $september = (string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id');

        $locker = DB::connection('pgsql_race'); // a period lock in progress on another connection
        $locker->beginTransaction();
        $locker->select('select id from fiscal_periods where id = ? for update', [$september]);
        DB::statement("SET lock_timeout = '300ms'");

        $failure = thrownBy(fn () => app(PostingEngine::class)->post($event->id), QueryException::class);
        expect((string) ($failure->errorInfo[0] ?? ''))->toBe('55P03')
            ->and(DB::table('accounting_events')->where('id', $event->id)->value('status'))->toBe('queued')
            ->and(DB::table('journals')->count())->toBe(0);

        $locker->rollBack();
        expect(app(PostingEngine::class)->post($event->id))->toHaveCount(1);
    });
});
