<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Domain\Enums\ProposalStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\BlankTenantSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fix GA-18: a tenant made by `erp:tenant` walks the setup wizard and can then issue a rated motor policy and take the premium into a bank —
 * company (code suggested) → fiscal year from January → chart of accounts → bank account → motor product from the tariff template (a draft tariff a
 * second person approves and activates) → underwriting limits → finish; then quote, proposal, policy with stamp duty and the receipt into the bank.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $this->tenant = (new BlankTenantSeeder())->run('gapaudit', 'Gap Audit Insurance');
    $this->headers = ['X-Tenant' => $this->tenant];
    $this->in = fn (callable $fn): mixed => asTenant($this->tenant, $fn);
    $this->person = fn (array $roleCodes): User => ($this->in)(function () use ($roleCodes): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->tenant, 'email' => $id.'@gapaudit.test', 'name' => implode(' + ', $roleCodes),
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roleCodes as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $this->tenant, 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                'scope_type' => 'tenant', 'scope_id' => $this->tenant]);
        }

        return User::query()->findOrFail($id);
    });
    // The local first-run admin (AdminUserSeeder): Tenant Admin plus Finance Manager, so one person walks the wizard; the CFO is the second person for the tariff.
    $this->admin = ($this->person)(['tenant_admin', 'finance_manager']);
    $this->cfo = ($this->person)(['cfo']);
});

it('takes a new tenant from the wizard to a rated motor policy paid into its bank', function (): void {
    // Company: the short code is suggested from the tenant's name; the fiscal year starts in January.
    actingAs($this->admin)->get('/setup', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('setup/Index')
        ->where('company.code', 'GAI')->where('company.suggested', true)->where('company.name', 'Gap Audit Insurance')->where('fiscalYear.first_month', '2026-01')
        ->where('steps.3.id', 'bank_accounts')->where('steps.4.id', 'product')->where('steps.5.id', 'underwriting_limits')->where('steps.8.id', 'done'));
    actingAs($this->admin)->post('/setup/company', ['code' => 'GAI', 'name' => 'Gap Audit Insurance PLC', 'branches' => [['code' => 'HO', 'name' => 'Head Office']]], $this->headers)
        ->assertRedirect('/setup?step=fiscal_year');
    actingAs($this->admin)->post('/setup/fiscal-year', ['first_month' => '2026-01', 'base_currency' => 'BDT'], $this->headers)->assertRedirect('/setup?step=chart_of_accounts');
    $rows = actingAs($this->admin)->get('/setup?step=chart_of_accounts', $this->headers)->viewData('page')['props']['chartOfAccounts']['rows'];
    actingAs($this->admin)->post('/setup/chart-of-accounts', ['rows' => $rows], $this->headers)->assertSessionHasNoErrors()->assertRedirect('/setup?step=bank_accounts');

    // Bank account: the step proposes the account the main bank role is mapped to.
    $bankGl = ($this->in)(fn (): string => (string) DB::table('accounts')->where('code', '1010')->value('id'));
    actingAs($this->admin)->get('/setup?step=bank_accounts', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('bankAccounts.defaultGlAccountId', $bankGl)->where('bankAccounts.currency', 'BDT')->where('bankAccounts.existing', []));
    actingAs($this->admin)->post('/setup/bank-accounts', ['bank_name' => 'City Bank', 'account_no_masked' => '****4471', 'gl_account_id' => $bankGl], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect('/setup?step=product');

    // Product from the motor tariff template: product, duties and a DRAFT tariff; the same person cannot approve it.
    actingAs($this->admin)->get('/setup?step=product', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('product.canUseTemplates', true)->where('product.templates.0', ['class_code' => 'motor', 'class_name' => 'Motor', 'code' => 'MOTOR', 'name' => 'Motor Comprehensive',
            'lob' => 'motor', 'plan_code' => 'MOTOR-TARIFF', 'product_exists' => false]));
    actingAs($this->admin)->post('/setup/product', ['template' => 'motor', 'code' => 'MOTOR', 'name' => 'Motor Comprehensive', 'effective_from' => '2026-01-01'], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect('/setup?step=underwriting_limits')
        ->assertSessionHas('status', 'Product Motor Comprehensive created. Its tariff MOTOR-TARIFF is a draft: someone other than you approves and activates it in Tariffs before quotes can be rated.');
    [$planId, $productId] = ($this->in)(fn (): array => [(string) DB::table('rating_plans')->where('code', 'MOTOR-TARIFF')->value('id'), (string) DB::table('products')->where('code', 'MOTOR')->value('id')]);
    ($this->in)(function () use ($productId): void {
        expect(DB::table('rating_plans')->where('code', 'MOTOR-TARIFF')->value('status'))->toBe('draft')
            ->and(DB::table('product_versions')->where('product_id', $productId)->value('class_code'))->toBe('motor')
            ->and(DB::table('duties')->orderBy('code')->pluck('code')->all())->toBe(['stamp', 'vat'])
            ->and(DB::table('coverages')->count())->toBe(3);
    });
    actingAs($this->admin)->get('/setup?step=product', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('product.tariffsToApprove.0.code', 'MOTOR-TARIFF')->where('product.tariffsToApprove.0.status', 'draft')->where('product.templates.0.product_exists', true));
    actingAs($this->admin)->post("/rating/plans/{$planId}/approve", [], $this->headers)->assertSessionHasErrors('form');
    actingAs($this->cfo)->post("/rating/plans/{$planId}/approve", [], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->cfo)->post("/rating/plans/{$planId}/activate", [], $this->headers)->assertSessionHasNoErrors();

    // Underwriting limits: the placeholder defaults, so an ordinary motor proposal is not referred.
    actingAs($this->admin)->post('/setup/underwriting-limits', [], $this->headers)->assertRedirect('/setup?step=users')
        ->assertSessionHas('status', '16 underwriting limits set. Change them any time in Admin → Underwriting limits.');
    actingAs($this->admin)->post('/setup/approvals', [], $this->headers)->assertRedirect('/setup?step=done');
    actingAs($this->admin)->get('/setup?step=done', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('steps.3.done', true)->where('steps.4.done', true)->where('steps.5.done', true)->where('product.tariffsToApprove', []));
    actingAs($this->admin)->post('/setup/finish', [], $this->headers)->assertRedirect('/home');

    // A branch sells the motor policy: quote on the tariff, proposal approved within the officer's limit, policy with stamp duty, premium into City Bank.
    $officer = ($this->person)(['branch_officer']);
    $manager = ($this->person)(['branch_manager']);
    $outcome = ($this->in)(function () use ($officer, $manager, $productId): array {
        $branchId = (string) DB::table('branches')->where('code', 'HO')->value('id');
        $customer = app(PartyService::class)->create(PartyKind::Individual, 'Rahima Akter', null, [PartyRoleType::Customer, PartyRoleType::Policyholder], $officer->id);
        $today = CarbonImmutable::parse('2026-09-14');
        $risk = ['vehicle_type' => 'private', 'registration_no' => 'DHA-GA-11-2201', 'chassis_no' => 'NZE141-0012201', 'engine_cc' => 1500, 'seats' => 5,
            'year_of_manufacture' => 2020, 'driver_age' => 38, 'sum_insured' => 1_000_000_00, 'ncb_years' => 0];
        $quotations = app(QuotationService::class);
        $quotation = $quotations->issue($quotations->saveDraft(new QuotationTerms($branchId, $productId, $customer->id, null, $today, $risk), null, $officer->id)->id, $today, $officer->id);
        $proposals = app(ProposalService::class);
        $proposal = $proposals->createFromQuotation($quotation->id, $officer->id);
        $proposals->verifyKyc($proposal->id, 'nid', '1990000000000', $officer->id);
        expect($proposals->submit($proposal->id, $officer->id)->status)->toBe(ProposalStatus::Approved);
        $policy = app(PolicyLifecycle::class)->issueFromProposal($proposal->id, $today, $officer->id, 1, 'TRF-RAHIMA-1');
        $installment = DB::table('installments')->where('policy_id', $policy->id)->sole(['id', 'amount_minor']);
        $bankAccountId = (string) DB::table('bank_accounts')->value('id');
        app(ReceiptService::class)->record(new RecordReceiptRequest((string) DB::table('legal_entities')->value('id'), $branchId, null, 'bank_transfer', (int) $installment->amount_minor, 'BDT',
            $today, $bankAccountId, 'TRF-RAHIMA-1', [new AllocationLine((string) $installment->id, (int) $installment->amount_minor)]), $manager->id);

        $line = fn (string $code, string $side): int => (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')->where('a.code', $code)->where('l.side', $side)->sum('l.amount_minor');

        return ['number' => (string) $policy->number, 'gross' => (int) $policy->gross_premium_minor, 'stamp' => (int) $policy->stamp_duty_minor,
            'events' => DB::table('accounting_events')->where('status', '!=', 'posted')->count(), 'bank_debit' => $line('1010', 'debit'),
            'receivable' => $line('1100', 'debit') - $line('1100', 'credit'), 'stamp_payable' => $line('2155', 'credit')];
    });

    expect($outcome['number'])->toStartWith('POL-HO-2026-')
        ->and($outcome['stamp'])->toBe(5_000)
        ->and($outcome['events'])->toBe(0)
        ->and($outcome['bank_debit'])->toBe($outcome['gross'])
        ->and($outcome['receivable'])->toBe(0)
        ->and($outcome['stamp_payable'])->toBe(5_000);
});

it('suggests a short code from the company name', function (string $name, string $code): void {
    expect(App\Modules\Platform\Setup\CompanySetup::suggestCode($name))->toBe($code);
})->with([
    ['Gap Audit Insurance', 'GAI'],
    ['Padma General Insurance PLC', 'PGI'],
    ['The Acme Company Ltd.', 'ACME'],
    ['Pragati', 'PRAG'],
    ['Ltd', 'LTD'],
    ['—', ''],
]);

it('leaves the tariff template to people who may draft tariffs, and refuses a code already used', function (): void {
    $tenantAdmin = ($this->person)(['tenant_admin']);
    actingAs($tenantAdmin)->post('/setup/company', ['code' => 'GAI', 'name' => 'Gap Audit Insurance', 'branches' => [['code' => 'HO', 'name' => 'Head Office']]], $this->headers);
    actingAs($this->admin)->post('/setup/fiscal-year', ['first_month' => '2026-01', 'base_currency' => 'BDT'], $this->headers);
    $rows = actingAs($this->admin)->get('/setup?step=chart_of_accounts', $this->headers)->viewData('page')['props']['chartOfAccounts']['rows'];
    actingAs($this->admin)->post('/setup/chart-of-accounts', ['rows' => $rows], $this->headers);

    $productManager = ($this->person)(['auditor']);
    ($this->in)(fn () => DB::table('role_permissions')->insert(['tenant_id' => $this->tenant, 'role_id' => DB::table('roles')->where('code', 'auditor')->value('id'), 'permission_code' => 'product.manage']));
    actingAs($productManager)->get('/setup?step=product', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('product.canUseTemplates', false));
    actingAs($productManager)->post('/setup/product', ['template' => 'motor', 'code' => 'MOTOR', 'name' => 'Motor Comprehensive', 'effective_from' => '2026-01-01'], $this->headers)
        ->assertSessionHasErrors('form');
    expect(($this->in)(fn (): array => [DB::table('products')->count(), DB::table('rating_plans')->count(), DB::table('duties')->count()]))->toBe([0, 0, 0]);

    actingAs($this->admin)->post('/setup/product', ['template' => 'fire', 'code' => 'FIRE', 'name' => 'Fire and Allied Perils', 'effective_from' => '2026-01-01'], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->post('/setup/product', ['template' => 'motor', 'code' => 'fire', 'name' => 'Motor', 'effective_from' => '2026-01-01'], $this->headers)->assertSessionHasErrors('code');
    // A second template in another class records that class's own VAT and stamp duty next to fire's.
    actingAs($this->admin)->post('/setup/product', ['template' => 'motor', 'code' => 'MOTOR', 'name' => 'Motor Comprehensive', 'effective_from' => '2026-01-01'], $this->headers)->assertSessionHasNoErrors();
    expect(($this->in)(fn (): int => DB::table('duties')->count()))->toBe(4);
});

it('asks for the chart before a bank account, and gates the step to bank account managers', function (): void {
    actingAs($this->admin)->post('/setup/bank-accounts', ['bank_name' => 'City Bank', 'account_no_masked' => '****4471', 'gl_account_id' => (string) Str::uuid7()], $this->headers)
        ->assertSessionHasErrors('form');
    actingAs(($this->person)(['tenant_admin']))->get('/setup?step=bank_accounts', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('steps.3.allowed', false)->where('steps.3.owner', 'Finance Manager')->where('steps.5.allowed', true)->where('steps.5.owner', 'Tenant Admin'));
    actingAs($this->admin)->post('/setup/underwriting-limits', [], $this->headers)->assertRedirect('/setup?step=users');
    actingAs(($this->person)(['finance_manager']))->post('/setup/underwriting-limits', [], $this->headers)->assertSessionHasErrors('form');
    expect(($this->in)(fn (): int => DB::table('underwriting_limits')->where('verify', true)->count()))->toBe(16);
});
