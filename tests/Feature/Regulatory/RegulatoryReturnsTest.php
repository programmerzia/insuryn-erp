<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Market gap G5: Regulatory → Returns. The Q3 2026 set is generated from the registers (premium income by class and branch from an issued and a cancelled policy),
 * previewed, downloaded as a workbook with a sheet per form and as a PDF, reviewed and filed with its date and reference (audited); a filed form is not regenerated.
 * Filing needs regulatory.file.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-10-10 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    asTenant($this->ctx['tenant_id'], function (): void {
        $lifecycle = app(PolicyLifecycle::class);
        foreach (['2026-07-01', '2026-08-01'] as $inception) {
            $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'], null,
                CarbonImmutable::parse($inception), 11_500_00, 'BDT', 1), $this->world['admin']);
            $lifecycle->issue($policy->id, CarbonImmutable::parse($inception), $this->world['admin']);
        }
        $lifecycle->cancel($policy->id, CarbonImmutable::parse('2026-09-01'), 'Customer sold the car', $this->world['admin']);
    });
});

it('generates, previews, exports, reviews and files the quarter\'s returns', function (): void {
    $pages = fakePdfRenderer();
    $preparer = ($this->userWith)(['reports.regulatory']);
    $filer = ($this->userWith)(['reports.regulatory', 'regulatory.file']);

    actingAs($preparer)->post('/regulatory/returns/generate', ['period' => '2026-Q3'], $this->headers)->assertSessionHasNoErrors();
    [$net, $cancelled] = asTenant($this->ctx['tenant_id'], fn (): array => [(int) DB::table('policy_transactions')->where('type', 'new')->sum('net_delta_minor'),
        (int) DB::table('policy_transactions')->where('type', 'cancellation')->selectRaw("coalesce(sum((amounts->>'unearned_remaining')::bigint), 0) as c")->value('c')]);

    actingAs($preparer)->get('/regulatory/returns?period=2026-Q3&form=premium_income', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('regulatory/Returns')
        ->has('forms', 5)->where('forms.0.status', 'draft')->where('forms.4.code', 'reinsurance_ceded')
        ->where('preview.title', 'Premium income by class of business')
        ->where('preview.sections.0.rows.0.group', 'Motor')->where('preview.sections.0.rows.0.policies', '2')
        ->where('preview.sections.0.rows.0.gross_premium', number_format($net / 100, 2))
        ->where('preview.sections.0.rows.0.cancellations', number_format($cancelled / 100, 2))
        ->where('preview.sections.0.rows.0.net_premium', number_format(($net - $cancelled) / 100, 2))
        ->where('preview.sections.1.rows.0.group', 'HO')
        ->where('can.file', false));

    $xlsx = actingAs($preparer)->get('/regulatory/returns/export?period=2026-Q3&format=xlsx', $this->headers)->assertOk()->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'rr');
    file_put_contents((string) $path, $xlsx);
    $zip = new ZipArchive();
    $zip->open((string) $path);
    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    $expenses = (string) $zip->getFromName('xl/worksheets/sheet3.xml');
    $zip->close();
    @unlink((string) $path);
    expect(substr_count($workbook, '<sheet '))->toBe(5)
        ->and($workbook)->toContain('name="Premium income"')->toContain('name="Agent register"')->toContain('name="Reinsurance ceded"')
        ->and($expenses)->toContain('Commission and management expenses by class of business against the expense limit')->toContain('Expense limit rate')->toContain('35.00%');

    actingAs($preparer)->get('/regulatory/returns/export?period=2026-Q3&format=pdf&form=reinsurance_ceded', $this->headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pages[0])->toContain('Reinsurance ceded summary')->toContain('Reinsurance is not set up in this system yet');

    $premium = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('regulatory_returns')->where('form_code', 'premium_income')->value('id'));
    actingAs($preparer)->post("/regulatory/returns/{$premium}/review", [], $this->headers)->assertSessionHasNoErrors();
    actingAs($preparer)->post("/regulatory/returns/{$premium}/file", ['filed_on' => '2026-10-09', 'reference' => 'IDRA/NL/2026/Q3/0147'], $this->headers)->assertSessionHasErrors();
    actingAs($filer)->post("/regulatory/returns/{$premium}/file", ['filed_on' => '2026-10-11', 'reference' => 'IDRA/NL/2026/Q3/0147'], $this->headers)->assertSessionHasErrors(); // after today
    actingAs($filer)->post("/regulatory/returns/{$premium}/file", ['filed_on' => '2026-10-09', 'reference' => 'IDRA/NL/2026/Q3/0147'], $this->headers)->assertSessionHasNoErrors();
    actingAs($preparer)->post('/regulatory/returns/generate', ['period' => '2026-Q3'], $this->headers)->assertSessionHasNoErrors();

    asTenant($this->ctx['tenant_id'], function () use ($premium): void {
        $row = DB::table('regulatory_returns')->where('id', $premium)->sole();
        expect($row->status)->toBe('filed')->and($row->filed_on)->toBe('2026-10-09')->and($row->filing_reference)->toBe('IDRA/NL/2026/Q3/0147')
            ->and(DB::table('audit_events')->where('object_id', $premium)->where('action', 'regulatory_return.filed')->where('permission', 'regulatory.file')->exists())->toBeTrue()
            ->and(DB::table('audit_events')->where('object_id', $premium)->where('action', 'regulatory_return.generated')->count())->toBe(1);
    });
    actingAs($filer)->get('/regulatory', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('regulatory/Dashboard')
        ->where('returns.0.status', 'filed')->where('solvency.meets', false));
});
