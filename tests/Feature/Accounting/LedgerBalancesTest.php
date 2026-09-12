<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\LedgerQuery;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\ReversalService;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Design §6.1: balances derive from journal lines. §2.3: a reversed journal stays valid history; its
 * reversal offsets it. Both must count, or reversing a posting leaves a phantom balance.
 */
it('nets a reversed journal against its reversal in balances and the trial balance', function (): void {
    Queue::fake();
    $ctx = seedDemoTenant();

    asTenant($ctx['tenant_id'], function () use ($ctx): void {
        $dims = ['branch' => $ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()];
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            $ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'ledger-1',
            CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 1_000], $dims));
        [$posted] = app(PostingEngine::class)->post($event->id);
        app(ReversalService::class)->reverse(Journal::query()->findOrFail($posted->id), CarbonImmutable::parse('2026-09-20'), 'entered in error', (string) Str::uuid7());

        $ledger = app(LedgerQuery::class);
        $asOf = CarbonImmutable::parse('2026-09-30');
        $bankRow = collect($ledger->trialBalance($ctx['entity_id'], $ctx['book_id'], $asOf))->firstWhere('account_id', $ctx['accounts']['bank_main']);

        expect($ledger->balance($ctx['accounts']['bank_main'], $ctx['book_id'], $asOf))->toBe(0)
            ->and($ledger->balance($ctx['accounts']['premium_receivable'], $ctx['book_id'], $asOf))->toBe(0)
            ->and($bankRow)->toMatchArray(['debit' => 1_000, 'credit' => 1_000]);
    });
});

it('shows the original posting in balances dated before its reversal', function (): void {
    Queue::fake();
    $ctx = seedDemoTenant();

    asTenant($ctx['tenant_id'], function () use ($ctx): void {
        $dims = ['branch' => $ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()];
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            $ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'ledger-2',
            CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 1_000], $dims));
        [$posted] = app(PostingEngine::class)->post($event->id);
        app(ReversalService::class)->reverse(Journal::query()->findOrFail($posted->id), CarbonImmutable::parse('2026-10-05'), 'entered in error', (string) Str::uuid7());

        expect(app(LedgerQuery::class)->balance($ctx['accounts']['bank_main'], $ctx['book_id'], CarbonImmutable::parse('2026-09-30')))->toBe(1_000);
    });
});
