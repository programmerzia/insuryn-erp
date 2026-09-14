<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Finance\FixedAssets\Application\DepreciationRun;
use App\Modules\Finance\FixedAssets\Application\FixedAssetReconciliation;
use App\Modules\Finance\FixedAssets\Application\FixedAssetService;
use App\Modules\Finance\FixedAssets\Domain\DepreciationCalculator;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Design addendum v2 §B.7 worked example: a laptop for 120,000 on credit, IT equipment straight line over 36 months — FA_ACQUIRED, 3,333.33 a month
 * (DEPRECIATION_POSTED once per month however often the batch runs), then sold for 110,000 into the bank with the loss posted (FA_DISPOSED); the
 * register reconciles to the ledger and the close lists the depreciation tasks.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-10-05 10:00'));
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->accountant = userWithPermissions($this->ctx['tenant_id'], ['fa.manage', 'periods.soft_lock']);
    $this->finance = userWithPermissions($this->ctx['tenant_id'], ['fa.post_depreciation', 'fa.manage']);
    $this->lines = fn (string $event): array => DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.description', $event)
        ->orderBy('j.posting_date')->orderBy('l.line_no')->get(['l.role_code', 'l.side', 'l.amount_minor', 'l.account_id'])->map(fn (object $l): array => [$l->role_code, $l->side, (int) $l->amount_minor, $l->account_id])->all();
});

it('capitalises, depreciates once per month, disposes with the loss posted and reconciles to the ledger', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $a = $this->ctx['accounts'];
        DB::table('accounts')->insert(['id' => $bankGl = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => '1011',
            'name' => 'Bank - City Bank', 'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true, 'is_control' => false, 'status' => 'active']);
        DB::table('bank_accounts')->insert(['id' => $bank = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'gl_account_id' => $bankGl, 'bank_name' => 'City Bank', 'account_no_masked' => '****4471', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $assets = app(FixedAssetService::class);
        $class = $assets->saveClass($this->ctx['entity_id'], ['code' => 'IT', 'name' => 'Computers and IT equipment', 'method' => 'straight_line', 'useful_life_months' => 36, 'rate_bp' => null,
            'residual_bp' => 0, 'capitalisation_threshold_minor' => 1_000_000, 'cost_account_id' => $a['fixed_asset_cost'], 'accumulated_account_id' => $a['accumulated_depreciation'],
            'expense_account_id' => $a['depreciation_expense'], 'disposal_account_id' => null], $this->accountant);

        expect(thrownBy(fn () => $assets->acquire($this->ctx['entity_id'], ['class_id' => $class, 'branch_id' => $this->ctx['branch_id'], 'description' => 'Mouse', 'acquired_on' => '2026-09-01',
            'cost_minor' => 150_000, 'paid_via' => 'payable'], $this->accountant), BusinessRuleViolation::class)->reasonCode)->toBe('ASSET_BELOW_THRESHOLD');

        $laptop = $assets->acquire($this->ctx['entity_id'], ['class_id' => $class, 'branch_id' => $this->ctx['branch_id'], 'description' => 'Dell Latitude 5440', 'custodian' => 'Rafiq Islam',
            'supplier' => 'Ryans Computers, Dhaka', 'invoice_ref' => 'RC-88121', 'acquired_on' => '2026-09-01', 'cost_minor' => 12_000_000, 'paid_via' => 'payable'], $this->accountant);
        expect(DB::table('fixed_assets')->where('id', $laptop)->value('number'))->toBe('FA-2026-000001')
            ->and(($this->lines)('FA_ACQUIRED'))->toBe([['fixed_asset_cost', 'debit', 12_000_000, $a['fixed_asset_cost']], ['accounts_payable', 'credit', 12_000_000, $a['accounts_payable']]]);

        $september = (string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id');
        $run = app(DepreciationRun::class);
        expect(thrownBy(fn () => $run->post($september, $this->accountant), App\Modules\Platform\Authorization\PermissionDenied::class))->toBeInstanceOf(App\Modules\Platform\Authorization\PermissionDenied::class)
            ->and($run->post($september, $this->finance))->toBe(1)
            ->and($run->post($september, $this->finance))->toBe(0) // INVARIANT: one row per asset and month
            ->and(($this->lines)('DEPRECIATION_POSTED'))->toBe([['depreciation_expense', 'debit', 333_333, $a['depreciation_expense']], ['accumulated_depreciation', 'credit', 333_333, $a['accumulated_depreciation']]])
            ->and(DB::table('journal_lines')->whereRaw("dims_ext->>'asset' = ?", [$laptop])->count())->toBe(4);

        $lines = app(FixedAssetReconciliation::class)->lines($this->ctx['entity_id'], $this->ctx['book_id'], CarbonImmutable::parse('2026-09-30'));
        expect(array_column($lines, 'variance_minor'))->toBe([0, 0])->and(array_column($lines, 'register_minor'))->toBe([12_000_000, 333_333]);

        // Sold in October for 110,000: NBV 116,666.67 → loss 6,666.67; October is not depreciated.
        $assets->dispose($laptop, 'sale', CarbonImmutable::parse('2026-10-04'), 11_000_000, $bank, 'Replaced by a new model', $this->accountant);
        expect(($this->lines)('FA_DISPOSED'))->toBe([['accumulated_depreciation', 'debit', 333_333, $a['accumulated_depreciation']], ['bank_main', 'debit', 11_000_000, $bankGl],
            ['asset_disposal_gain_loss', 'debit', 666_667, $a['asset_disposal_gain_loss']], ['fixed_asset_cost', 'credit', 12_000_000, $a['fixed_asset_cost']]])
            ->and(app(DepreciationRun::class)->preview(app(App\Modules\Accounting\Application\Queries\FiscalPeriodQuery::class)->find((string) DB::table('fiscal_periods')->where('starts', '2026-10-01')->value('id')) ?? throw new LogicException()))->toBe([])
            ->and(array_column(app(FixedAssetReconciliation::class)->lines($this->ctx['entity_id'], $this->ctx['book_id'], CarbonImmutable::parse('2026-10-31')), 'variance_minor'))->toBe([0, 0]);

        // D-115: an entity with a register gets the depreciation and reconciliation close tasks.
        $runId = app(PeriodCloseService::class)->start($september, $this->accountant);
        expect(DB::table('period_close_tasks')->where('close_run_id', $runId)->whereIn('code', ['depreciation', 'fixed_asset_reconciliation'])->count())->toBe(2);
    });
});

it('rounds straight line so the last month absorbs the residual, and reduces the balance for vehicles', function (): void {
    $acquired = CarbonImmutable::parse('2026-09-01');
    $accumulated = 0;
    for ($month = 0; $month < 36; $month++) {
        $accumulated += DepreciationCalculator::monthly('straight_line', 12_000_000, 0, 36, null, $accumulated, $acquired, $acquired->addMonths($month)->endOfMonth());
    }
    expect($accumulated)->toBe(12_000_000)
        ->and(DepreciationCalculator::monthly('straight_line', 12_000_000, 0, 36, null, 333_333 * 35, $acquired, $acquired->addMonths(35)->endOfMonth()))->toBe(333_345)
        ->and(DepreciationCalculator::monthly('reducing_balance', 450_000_000, 0, null, 2000, 0, $acquired, $acquired->endOfMonth()))->toBe(7_500_000);
});

it('shows the register, the depreciation preview and the classes screens', function (): void {
    $user = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->whereKey((string) $this->finance)->firstOrFail());
    actingAs($user)->get('/fixed-assets', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('fixedAssets/Index', false));
    actingAs($user)->get('/fixed-assets/classes', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('fixedAssets/Classes', false));
    actingAs($user)->get('/fixed-assets/depreciation', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('fixedAssets/Depreciation', false));
    actingAs($user)->get('/fixed-assets/register', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Show', false)->where('title', 'Fixed asset register'));
    actingAs($user)->get('/fixed-assets/register/export?format=csv', $this->headers)->assertOk();
});
