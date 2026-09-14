<?php

declare(strict_types=1);

use App\Http\Pages\JournalSources;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningRun;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gap audit GA-45: PREMIUM_EARNED journals carried `source_type = premium_earning_ledger` with the policy id as the source id, so the journal
 * viewer and account activity found no ledger row and linked nowhere. The event now names the ledger row; journals posted before the fix
 * (source id = policy id) still resolve to their policy.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'daily_365');
});

it('points each premium earning journal at its ledger row, which links to the policy', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            null, CarbonImmutable::parse('2026-07-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-07-01'), $this->world['admin']);
        foreach (DB::table('fiscal_periods')->where('ends', '<=', '2026-08-31')->orderBy('starts')->pluck('id') as $periodId) {
            app(PremiumEarningRun::class)->run((string) $periodId);
        }
        $number = (string) DB::table('policies')->where('id', $policy->id)->value('number');

        $journals = DB::table('journals')->where('source_type', 'premium_earning_ledger')->get(['id', 'source_id']);
        $ledger = DB::table('premium_earning_ledger')->where('policy_id', $policy->id)->pluck('id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
        expect($journals)->toHaveCount(2)
            ->and($journals->pluck('source_id')->map(fn ($id): string => (string) $id)->sort()->values()->all())->toBe($ledger);

        $sources = JournalSources::forJournals(array_values($journals->pluck('id')->map(fn ($id): string => (string) $id)->all()));
        expect($sources)->toHaveCount(2);
        foreach ($sources as $source) {
            expect($source)->toBe(['url' => "/policies/{$policy->id}", 'label' => $number]);
        }

        // A journal posted before the fix names the policy itself; it still reaches the policy.
        expect(JournalSources::link('premium_earning_ledger', $policy->id))->toBe("/policies/{$policy->id}")
            ->and(JournalSources::link('premium_earning_ledger', (string) Str::uuid7()))->toBeNull();
    });
});
