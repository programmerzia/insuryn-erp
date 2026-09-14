<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Flow fix X12 (Part A step 14): the auditor exports the premium register, outstanding claims and unearned premium from the reports index — CSV or XLSX of the
 * report's own table on its default filter (this month, or as of today) — without opening each report.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-20 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->auditor = ($this->userWith)(['accounting.view_journals', 'audit.view', 'reports.financial', 'reports.regulatory']);
    $this->policyNumber = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return (string) DB::table('policies')->where('id', $policy->id)->value('number');
    });
});

it('lists an export for every report with a table on the index', function (): void {
    actingAs($this->auditor)->get('/reports', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Index')
        ->where('reports.0.key', 'premium-register')->where('reports.0.exports', ['csv' => '/reports/premium-register/export?format=csv', 'xlsx' => '/reports/premium-register/export?format=xlsx'])
        ->where('reports', fn (Collection $reports): bool => $reports->whereNull('key')->every(fn (array $r): bool => ! isset($r['exports']))));
});

it('downloads the premium register for this month as CSV, the table the report page shows', function (): void {
    $response = actingAs($this->auditor)->get('/reports/premium-register/export?format=csv', $this->headers)->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertDownload('premium-register-2026-09-01-to-2026-09-20.csv');
    $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

    expect($lines[0])->toBe('Date,Policy,Transaction,Product,Class,Branch,Gross,Net,Tax')
        ->and($lines)->toHaveCount(2)
        ->and($lines[1])->toContain($this->policyNumber)->toContain('"120,000.00"');
});

it('downloads outstanding claims and unearned premium as of today, as CSV or XLSX', function (): void {
    actingAs($this->auditor)->get('/reports/outstanding-claims/export?format=csv', $this->headers)->assertOk()->assertDownload('outstanding-claims-2026-09-20.csv');
    $xlsx = actingAs($this->auditor)->get('/reports/unearned-premium/export?format=xlsx', $this->headers)->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->assertDownload('unearned-premium-2026-09-20.xlsx');
    expect(substr($xlsx->streamedContent(), 0, 2))->toBe('PK');
    // A filter given explicitly is used, as on the report page.
    actingAs($this->auditor)->get('/reports/premium-register/export?format=csv&from=2026-08-01&to=2026-08-31', $this->headers)->assertDownload('premium-register-2026-08-01-to-2026-08-31.csv');
});

it('exports to reports.financial only, and only known reports and formats', function (): void {
    actingAs(($this->userWith)(['policy.create']))->get('/reports/premium-register/export?format=csv', $this->headers)->assertForbidden();
    actingAs($this->auditor)->get('/reports/nothing/export?format=csv', $this->headers)->assertNotFound();
    actingAs($this->auditor)->get('/reports/premium-register/export?format=pdf', $this->headers)->assertSessionHasErrors('format');
});
