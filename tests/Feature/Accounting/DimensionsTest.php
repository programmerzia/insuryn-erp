<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\ReversalService;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Design §2.2 journal_lines dimensions: event dimensions are inherited by every line (§3.2
 * inherit_from_event); known dimensions land in dim_* columns, anything else in dims_ext, and the
 * routing-only product_code is not stored. Reversal mirrors dimensions exactly (§2.3).
 */
beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant();
    $this->dims = [
        'branch' => $this->ctx['branch_id'], 'product' => (string) Str::uuid7(), 'policy' => (string) Str::uuid7(),
        'customer' => (string) Str::uuid7(), 'agent' => (string) Str::uuid7(), 'claim' => (string) Str::uuid7(),
        'lob' => 'motor', 'channel' => 'direct', 'product_code' => 'MOTOR', 'receipt' => (string) Str::uuid7(),
    ];
});

/** @return array<string, mixed> */
function storedDimensions(JournalLine $line): array
{
    return [
        'branch' => $line->dim_branch, 'product' => $line->dim_product, 'policy' => $line->dim_policy,
        'customer' => $line->dim_customer, 'agent' => $line->dim_agent, 'claim' => $line->dim_claim,
        'lob' => $line->dim_lob, 'channel' => $line->dim_channel, 'cost_centre' => $line->dim_cost_centre,
        'employee' => $line->dim_employee, 'reinsurer' => $line->dim_reinsurer, 'ext' => $line->dims_ext,
    ];
}

it('stores event dimensions on every line of posted and reversed journals', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            $this->ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'dims-1',
            CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 1000], $this->dims));
        [$posted] = app(PostingEngine::class)->post($event->id);
        $reversal = app(ReversalService::class)->reverse(Journal::query()->findOrFail($posted->id), CarbonImmutable::parse('2026-09-20'), 'dimension check', (string) Str::uuid7());

        $expected = [
            'branch' => $this->dims['branch'], 'product' => $this->dims['product'], 'policy' => $this->dims['policy'],
            'customer' => $this->dims['customer'], 'agent' => $this->dims['agent'], 'claim' => $this->dims['claim'],
            'lob' => 'motor', 'channel' => 'direct', 'cost_centre' => null, 'employee' => null, 'reinsurer' => null,
            'ext' => ['receipt' => $this->dims['receipt']],
        ];
        $journals = Journal::query()->with('lines')->whereKey([$posted->id, $reversal->id])->get();

        expect($journals)->toHaveCount(2);
        foreach ($journals as $journal) {
            expect($journal->lines)->toHaveCount(2);
            foreach ($journal->lines as $line) {
                expect(storedDimensions($line))->toBe($expected);
            }
        }
    });
});
