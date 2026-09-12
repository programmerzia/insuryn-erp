<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Design §9.1 "posting-rule golden tests": every fixture in tests/Fixtures/golden must produce exactly
 * the expected (role, side, amount) lines. Change a rule → update the fixture, never the other way.
 */
$fixtures = glob(__DIR__.'/../../Fixtures/golden/*.json') ?: [];

it('posts golden fixture :dataset', function (string $file): void {
    Queue::fake();
    $ctx = seedDemoTenant();
    /** @var array{event_type:string, payload:array<string,int>, dimensions:array<string,string>, expected:list<array{0:string,1:string,2:int}>} $fx */
    $fx = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    asTenant($ctx['tenant_id'], function () use ($ctx, $fx, $file): void {
        // dims in fixtures are labels; map the ones that must be UUIDs
        $dims = $fx['dimensions'];
        $dims['branch'] = $ctx['branch_id'];
        foreach (['product', 'policy', 'customer', 'agent', 'claim'] as $d) {
            $dims[$d] = (string) \Illuminate\Support\Str::uuid7();
        }
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            $ctx['entity_id'], $fx['event_type'], 'fixture', (string) \Illuminate\Support\Str::uuid7(),
            $fx['event_type'].':'.basename($file), CarbonImmutable::create(2026, 9, 15), CarbonImmutable::create(2026, 9, 15),
            'BDT', $fx['payload'], $dims));

        $journals = app(PostingEngine::class)->post($event->id);
        expect($journals)->toHaveCount(1);
        $j = Journal::query()->with('lines')->findOrFail($journals[0]->id);
        expect($j->status->value)->toBe('posted')->and($j->number)->toStartWith('JV-2026-');

        $actual = $j->lines->map(fn ($l) => [$l->role_code, $l->side->value, $l->amount_minor])->all();
        expect($actual)->toEqual($fx['expected']);

        $dr = $j->lines->where('side.value', 'debit')->sum('amount_minor');
        $cr = $j->lines->where('side.value', 'credit')->sum('amount_minor');
        expect($dr)->toBe($cr);
    });
})->with(array_combine(array_map('basename', $fixtures), $fixtures));
