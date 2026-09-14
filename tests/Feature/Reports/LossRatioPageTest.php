<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningRun;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Reports\Http\Controllers\ReportsPageController;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Gap audit GA-06: the loss ratio page printed a negative ratio as "-1230.-77%" and hid recoveries inside incurred claims. Negatives are
 * "(1,230.77)%", and the page shows claims incurred, recoveries and net incurred as columns. A recovery counts in the period it was received.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $officer = userWithPermissions($this->ctx['tenant_id'], ['claim.register', 'claim.reserve']);
    $manager = userWithPermissions($this->ctx['tenant_id'], ['claim.approve', 'claim.pay_request', 'claim.close']);
    $finance = userWithPermissions($this->ctx['tenant_id'], ['claim.pay_release']);
    $this->reader = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['reports.financial'])));
    $d = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);

    asTenant($this->ctx['tenant_id'], function () use ($officer, $manager, $finance, $d): void {
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            null, $d('2026-07-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        $lifecycle->issue($policy->id, $d('2026-07-01'), $this->world['admin']);
        foreach (DB::table('fiscal_periods')->where('ends', '<=', '2026-10-31')->orderBy('starts')->pluck('id') as $periodId) {
            app(PremiumEarningRun::class)->run((string) $periodId);
        }
        $claims = app(ClaimService::class);
        $payments = app(ClaimPaymentService::class);
        $claim = $claims->register($policy->id, $d('2026-09-01'), 'Collision', $officer, $d('2026-09-02'));
        $claims->reserve($claim->id, 3_000_000, 'Initial', $officer, $d('2026-09-02'));
        $payment = $payments->approve($claim->id, 2_000_000, $this->world['policyholder_id'], $manager, $d('2026-09-10'));
        $payments->requestRelease($payment->id, $manager, null);
        $payments->release($payment->id, $finance, $d('2026-09-12'));
        // The salvage is received in October, a month after the loss and the payment: it lowers October's incurred, not September's.
        $claims->recover($claim->id, 'salvage', 1_500_000, null, 'SALV', $manager, $d('2026-10-10'));
        $claims->close($claim->id, 'Settled', $manager, $d('2026-10-15'));
    });
});

it('formats signed basis points with the negative in parentheses', function (): void {
    expect(ReportsPageController::percentText(123_077))->toBe('1,230.77%')
        ->and(ReportsPageController::percentText(-123_077))->toBe('(1,230.77)%')
        ->and(ReportsPageController::percentText(-5))->toBe('(0.05)%')
        ->and(ReportsPageController::percentText(0))->toBe('0.00%')
        ->and(ReportsPageController::percentText(null))->toBe('—');
});

it('shows claims incurred, recoveries in the month received and net incurred, with a negative ratio in parentheses', function (): void {
    $october = asTenant($this->ctx['tenant_id'], fn (): int => (int) DB::table('premium_earning_ledger as l')->join('fiscal_periods as p', 'p.id', '=', 'l.period_id')
        ->where('p.starts', '2026-10-01')->sum('l.earned_minor'));
    $ratio = App\Modules\Insurance\Policy\Domain\PremiumMath::prorate(-2_500_000, 10_000, $october);
    expect($ratio)->toBeLessThan(0);

    actingAs($this->reader)->get('/reports/loss-ratio?from=2026-10-01&to=2026-10-31&by=product', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('reports/Show')
        ->where('columns', fn (Illuminate\Support\Collection $columns): bool => $columns->pluck('label')->all() === ['Product', 'Earned premium', 'Claims incurred', 'Recoveries', 'Net incurred', 'Loss ratio'])
        ->where('rows.0.cells.claims', '-10,000.00')->where('rows.0.cells.recoveries', '15,000.00')->where('rows.0.cells.incurred', '-25,000.00')
        ->where('rows.0.cells.ratio', ReportsPageController::percentText($ratio))
        ->where('rows.0.cells.ratio', fn (string $text): bool => preg_match('/^\([\d,]+\.\d{2}\)%$/', $text) === 1)
        ->where('rows.0.links.recoveries', fn (string $link): bool => str_starts_with($link, '/reports/account-activity?account_id='))
        ->where('totals.recoveries', '15,000.00')->where('totals.incurred', '-25,000.00'));

    // September carries the claim itself (3,000,000 reserved); the 1,000,000 left is released when it closes in October.
    actingAs($this->reader)->get('/reports/loss-ratio?from=2026-09-01&to=2026-09-30&by=product', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('rows.0.cells.claims', '30,000.00')->where('rows.0.cells.recoveries', '0.00')->where('rows.0.cells.incurred', '30,000.00'));
});
