<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BlankTenantSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Onboarding (market cross-check G9, session S1): the first sign-in to a tenant with no products opens a setup wizard — company and branches →
 * fiscal year and base currency → chart of accounts (the insurance template, reviewed and edited) → first product → first users and roles → done.
 * Each step saves on its own through the existing services (COA import, product catalogue, user administration) and the wizard can be reopened.
 * Steps are gated by the permission that owns the data, so segregation of duties holds during setup too.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    travelTo(CarbonImmutable::parse('2026-09-13 10:00'));
    $this->tenant = (new BlankTenantSeeder())->run('acme', 'Acme General Insurance');
    $this->headers = ['X-Tenant' => $this->tenant];
    $this->in = fn (callable $fn): mixed => asTenant($this->tenant, $fn);
    $this->person = fn (array $roleCodes): User => ($this->in)(function () use ($roleCodes): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->tenant, 'email' => $id.'@acme.test', 'name' => implode(' + ', $roleCodes),
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roleCodes as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $this->tenant, 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                'scope_type' => 'tenant', 'scope_id' => $this->tenant]);
        }

        return User::query()->findOrFail($id);
    });
    // The local first-run admin (AdminUserSeeder): Tenant Admin plus Finance Manager, so one person can walk the whole wizard.
    $this->admin = ($this->person)(['tenant_admin', 'finance_manager']);
    $this->company = fn () => actingAs($this->admin)->post('/setup/company', ['code' => 'ACME', 'name' => 'Acme General Insurance PLC',
        'branches' => [['code' => 'HO', 'name' => 'Head Office'], ['code' => 'CTG', 'name' => 'Chattogram']]], $this->headers);
    $this->fiscalYear = fn () => actingAs($this->admin)->post('/setup/fiscal-year', ['first_month' => '2026-07', 'base_currency' => 'BDT'], $this->headers);
    $this->templateRows = fn (): array => setupTemplateRows($this->admin, $this->headers);
});

/**
 * The chart-of-accounts template rows the wizard offers for review.
 *
 * @param array<string, string> $headers
 * @return list<array{code: string, name: string, type: string, normal_side: string, is_control: bool, control_subledger: string|null, role: string|null}>
 */
function setupTemplateRows(User $admin, array $headers): array
{
    return actingAs($admin)->get('/setup?step=chart_of_accounts', $headers)->assertOk()->viewData('page')['props']['chartOfAccounts']['rows'];
}

it('sends the first sign-in of a tenant without products to the wizard, and stops once a product exists', function (): void {
    actingAs($this->admin)->get('/home', $this->headers)->assertRedirect('/setup');
    actingAs(($this->person)(['branch_officer']))->get('/home', $this->headers)->assertOk();

    ($this->company)()->assertRedirect('/setup?step=fiscal_year');
    actingAs($this->admin)->get('/setup', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('setup/Index')
        ->where('current', 'fiscal_year')
        ->where('steps.0.id', 'company')->where('steps.0.done', true)
        ->where('steps.1.id', 'fiscal_year')->where('steps.1.done', false)
        ->where('steps.5.id', 'done')
        ->where('company.branches', fn ($branches): bool => count($branches) === 2));
});

it('saves company and branches, and saving again renames without duplicating', function (): void {
    ($this->company)()->assertSessionHasNoErrors();
    actingAs($this->admin)->post('/setup/company', ['code' => 'ACME', 'name' => 'Acme Insurance PLC',
        'branches' => [['code' => 'HO', 'name' => 'Head Office (Motijheel)'], ['code' => 'CTG', 'name' => 'Chattogram'], ['code' => 'SYL', 'name' => 'Sylhet']]], $this->headers)->assertSessionHasNoErrors();

    ($this->in)(function (): void {
        expect(DB::table('legal_entities')->count())->toBe(1)
            ->and(DB::table('legal_entities')->value('name'))->toBe('Acme Insurance PLC')
            ->and(DB::table('branches')->orderBy('code')->pluck('name', 'code')->all())->toBe(['CTG' => 'Chattogram', 'HO' => 'Head Office (Motijheel)', 'SYL' => 'Sylhet'])
            ->and(DB::table('audit_events')->where('action', 'setup.company_saved')->count())->toBe(2);
    });
    actingAs($this->admin)->post('/setup/company', ['code' => '', 'name' => '', 'branches' => []], $this->headers)->assertSessionHasErrors(['code', 'name', 'branches']);
});

it('opens a fiscal year of twelve monthly periods in the base currency, once', function (): void {
    actingAs($this->admin)->post('/setup/fiscal-year', ['first_month' => '2026-07', 'base_currency' => 'BDT'], $this->headers)->assertSessionHasErrors('form');
    ($this->company)();
    ($this->fiscalYear)()->assertRedirect('/setup?step=chart_of_accounts');

    ($this->in)(function (): void {
        $starts = DB::table('fiscal_periods')->orderBy('period')->pluck('starts')->map(fn (mixed $d): string => (string) $d)->all();
        $ends = DB::table('fiscal_periods')->orderBy('period')->pluck('ends')->map(fn (mixed $d): string => (string) $d)->all();
        expect($starts)->toHaveCount(12)
            ->and([$starts[0], $ends[0], $starts[11], $ends[11]])->toBe(['2026-07-01', '2026-07-31', '2027-06-01', '2027-06-30'])
            ->and(DB::table('fiscal_periods')->distinct()->pluck('status')->all())->toBe(['open'])
            ->and(DB::table('books')->where('is_primary', true)->count())->toBe(1)
            ->and(DB::table('legal_entities')->value('base_currency'))->toBe('BDT')
            ->and((int) DB::table('tenants')->where('id', $this->tenant)->value('fiscal_year_start_month'))->toBe(7);
    });
    ($this->fiscalYear)()->assertSessionHasNoErrors();
    expect(($this->in)(fn () => DB::table('fiscal_periods')->count()))->toBe(12);
});

it('offers the insurance chart of accounts for review, imports the edited chart and wires its control accounts', function (): void {
    ($this->company)();
    ($this->fiscalYear)();
    $rows = setupTemplateRows($this->admin, $this->headers);
    expect(collect($rows)->pluck('role')->filter()->all())->toContain('premium_receivable', 'unearned_premium', 'premium_tax_payable', 'claims_outstanding', 'suspense_receipts');

    // Review and edit: rename an account, drop one without a role, add an office expense account.
    $edited = collect($rows)->map(fn (array $r): array => $r['role'] === 'bank_main' ? [...$r, 'name' => 'Bank - City Bank current account'] : $r)
        ->reject(fn (array $r): bool => $r['role'] === null && $r['code'] === '5500')->values()->all();
    $edited[] = ['code' => '6100', 'name' => 'Office rent', 'type' => 'expense', 'normal_side' => 'debit', 'is_control' => false, 'control_subledger' => null, 'role' => null];
    actingAs($this->admin)->post('/setup/chart-of-accounts', ['rows' => $edited], $this->headers)->assertRedirect('/setup?step=product');

    ($this->in)(function () use ($edited): void {
        expect(DB::table('accounts')->count())->toBe(count($edited))
            ->and(DB::table('accounts')->where('code', '1010')->value('name'))->toBe('Bank - City Bank current account')
            ->and(DB::table('account_role_mappings')->count())->toBe(collect($edited)->whereNotNull('role')->count())
            ->and(DB::table('subledger_controls')->orderBy('control_account_role')->pluck('subledger', 'control_account_role')->all())->toMatchArray([
                'premium_receivable' => 'premium', 'claims_outstanding' => 'claims', 'suspense_receipts' => 'suspense'])
            ->and(DB::table('audit_events')->where('action', 'chart_of_accounts.imported')->count())->toBe(1);
    });
    // Imported once: the step now shows the chart in place instead of the template.
    actingAs($this->admin)->get('/setup?step=chart_of_accounts', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('chartOfAccounts.imported', count($edited))->where('steps.2.done', true));
});

it('refuses a chart that drops an account the accounting needs, or has an invalid row, without writing', function (): void {
    ($this->company)();
    ($this->fiscalYear)();
    $rows = setupTemplateRows($this->admin, $this->headers);
    $withoutReceivable = collect($rows)->reject(fn (array $r): bool => $r['role'] === 'premium_receivable')->values()->all();
    actingAs($this->admin)->post('/setup/chart-of-accounts', ['rows' => $withoutReceivable], $this->headers)->assertSessionHasErrors('rows');

    $badType = collect($rows)->map(fn (array $r): array => $r['code'] === '1010' ? [...$r, 'type' => 'liabilities'] : $r)->all();
    actingAs($this->admin)->post('/setup/chart-of-accounts', ['rows' => $badType], $this->headers)->assertSessionHasErrors('rows.0.type');
    expect(($this->in)(fn () => DB::table('accounts')->count()))->toBe(0);
});

it('creates the first product with its term and VAT through the product catalogue', function (): void {
    ($this->company)();
    ($this->fiscalYear)();
    actingAs($this->admin)->post('/setup/chart-of-accounts', ['rows' => ($this->templateRows)()], $this->headers);

    actingAs($this->admin)->post('/setup/product', ['code' => 'MOTOR', 'name' => 'Motor Comprehensive', 'lob' => 'motor', 'insurance_class' => 'non_life',
        'term_months' => 12, 'effective_from' => '2026-07-01', 'vat_rate_percent' => '15', 'vat_inclusive' => true], $this->headers)->assertRedirect('/setup?step=users');

    ($this->in)(function (): void {
        $product = DB::table('products')->sole(['id', 'code', 'insurance_class']);
        $version = DB::table('product_versions')->where('product_id', $product->id)->sole(['term_months', 'tax_profile', 'effective_from', 'earning_method']);
        expect($product->code)->toBe('MOTOR')->and($product->insurance_class)->toBe('non_life')
            ->and((int) $version->term_months)->toBe(12)
            ->and(json_decode((string) $version->tax_profile, true))->toMatchArray(['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true])
            ->and(DB::table('tax_rates')->where('tax_type', 'VAT')->where('withholding', false)->value('rate_bp'))->toBe(1500)
            ->and(DB::table('audit_events')->where('action', 'product.created')->count())->toBe(1);
    });
    // No longer a tenant without products: home is home again.
    actingAs($this->admin)->get('/home', $this->headers)->assertOk();
});

it('invites the first users with their roles, and the segregation of duties still applies', function (): void {
    ($this->company)();
    actingAs($this->admin)->post('/setup/users', ['users' => [
        ['name' => 'Rafiq Branch', 'email' => 'rafiq@acme.test', 'role' => 'branch_officer'],
        ['name' => 'Sadia Accounts', 'email' => 'sadia@acme.test', 'role' => 'accountant'],
    ]], $this->headers)->assertRedirect('/setup?step=done');

    ($this->in)(function (): void {
        expect(DB::table('users')->whereIn('email', ['rafiq@acme.test', 'sadia@acme.test'])->count())->toBe(2)
            ->and(DB::table('user_roles as ur')->join('users as u', 'u.id', '=', 'ur.user_id')->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('u.email', 'sadia@acme.test')->pluck('r.code')->all())->toBe(['accountant']);
    });
    actingAs($this->admin)->post('/setup/users', ['users' => [['name' => 'Rafiq Branch', 'email' => 'rafiq@acme.test', 'role' => 'branch_officer']]], $this->headers)
        ->assertSessionHasErrors('users.0.email');
});

it('gates each step by the permission that owns the data', function (): void {
    $tenantAdmin = ($this->person)(['tenant_admin']);
    actingAs($tenantAdmin)->get('/setup', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('steps.0.allowed', true)->where('steps.1.allowed', false)->where('steps.2.allowed', false)->where('steps.3.allowed', false)->where('steps.4.allowed', true)
        ->where('steps.1.owner', 'Finance Manager'));
    actingAs($tenantAdmin)->post('/setup/company', ['code' => 'ACME', 'name' => 'Acme', 'branches' => [['code' => 'HO', 'name' => 'Head Office']]], $this->headers)->assertSessionHasNoErrors();
    actingAs($tenantAdmin)->post('/setup/fiscal-year', ['first_month' => '2026-07', 'base_currency' => 'BDT'], $this->headers)->assertSessionHasErrors('form');
    actingAs($tenantAdmin)->post('/setup/product', ['code' => 'M', 'name' => 'M', 'lob' => 'motor', 'insurance_class' => 'non_life', 'term_months' => 12, 'effective_from' => '2026-07-01'], $this->headers)
        ->assertSessionHasErrors('form');
    expect(($this->in)(fn () => [DB::table('fiscal_periods')->count(), DB::table('products')->count()]))->toBe([0, 0]);

    actingAs(($this->person)(['branch_officer']))->get('/setup', $this->headers)->assertForbidden();
});

it('finishes, and can be reopened from Admin later', function (): void {
    ($this->company)();
    actingAs($this->admin)->post('/setup/finish', [], $this->headers)->assertRedirect('/home');
    expect(($this->in)(fn () => DB::table('setup_progress')->where('step', 'done')->exists()))->toBeTrue();

    // Finished (even with steps left for others): no more redirect, but the wizard still opens and shows what is left.
    actingAs($this->admin)->get('/home', $this->headers)->assertOk();
    actingAs($this->admin)->get('/setup', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('finished', true)->where('steps.0.done', true)->where('steps.1.done', false));
});
