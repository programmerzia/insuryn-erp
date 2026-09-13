<?php

declare(strict_types=1);

use App\Http\Home\WorkQueues;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\travelTo;

/**
 * Phase 3 (after R4): the branch's "Quotes to follow up" home queue lists issued quotations from the quote workbench that are still valid, next to
 * Phase 1 policy quotes, each opening its own page; expired, declined and converted quotations are not follow-ups.
 */
it('lists issued, still valid quotations from the workbench as quotes to follow up', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $ctx = seedDemoTenant();
    seedRoleTemplates($ctx['tenant_id']);
    $world = ratedProductsWorld($ctx);

    [$officer, $issued, $declined] = asTenant($ctx['tenant_id'], function () use ($ctx, $world): array {
        DB::table('users')->insert(['id' => $officer = (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'email' => 'officer@demo.test', 'name' => 'Officer', 'password' => 'x', 'status' => 'active']);
        DB::table('user_roles')->insert(['tenant_id' => $ctx['tenant_id'], 'user_id' => $officer, 'role_id' => DB::table('roles')->where('code', 'branch_officer')->value('id'), 'scope_type' => 'tenant', 'scope_id' => $ctx['tenant_id']]);
        $quotes = app(QuotationService::class);
        $terms = new QuotationTerms($ctx['branch_id'], $world['motor_product_id'], $world['policyholder_id'], null, CarbonImmutable::parse('2026-09-20'), $world['motor_inputs'], ['passenger_liability']);
        $issued = $quotes->issue($quotes->saveDraft($terms, null, $officer)->id, CarbonImmutable::parse('2026-09-15'), $officer);
        $declined = $quotes->issue($quotes->saveDraft($terms, null, $officer)->id, CarbonImmutable::parse('2026-09-15'), $officer);
        $quotes->decline($declined->id, 'Customer went elsewhere', $officer);
        $quotes->saveDraft($terms, null, $officer); // a draft is not a quote given to a customer yet

        return [$officer, $issued, $declined];
    });

    $block = asTenant($ctx['tenant_id'], fn (): array => quotesBlock($officer));
    expect($block['count'])->toBe(1)
        ->and($block['href'])->toBe('/quotations')
        ->and($block['rows'][0]['href'])->toBe("/quotations/{$issued->id}")
        ->and($block['rows'][0]['cells']['number'])->toBe($issued->number)
        ->and($block['emptyAction'])->toBe(['label' => 'New quote', 'href' => '/quotations/create']);
    expect($declined->id)->not->toBe($issued->id);

    travelTo(CarbonImmutable::parse('2026-10-15 09:00')); // past the 15-day validity
    expect(asTenant($ctx['tenant_id'], fn (): mixed => quotesBlock($officer)['count']))->toBe(0);
});

/** @return array<string, mixed> the officer's "Quotes to follow up" block */
function quotesBlock(string $userId): array
{
    foreach (app(WorkQueues::class)->blocks($userId) as $block) {
        if ($block['key'] === 'quotes') {
            return $block;
        }
    }

    throw new RuntimeException('The quotes queue is missing.');
}
