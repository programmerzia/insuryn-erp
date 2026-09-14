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
        // Fix F3 inserted the optional approval limits step before Done (was: steps.5.id done); gap fix GA-18 inserted bank accounts after the chart and
        // underwriting limits after the product (was: steps.5.id approvals, steps.6.id done).
        ->where('steps.3.id', 'bank_accounts')->where('steps.4.id', 'product')->where('steps.5.id', 'underwriting_limits')->where('steps.6.id', 'users')
        ->where('steps.7.id', 'approvals')->where('steps.8.id', 'done')
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
    actingAs($this->admin)->post('/setup/chart-of-accounts', ['rows' => $edited], $this->headers)->assertRedirect('/setup?step=bank_accounts'); // GA-18: bank accounts follow the chart (was: step=product)

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

it('ends the chart of accounts step by checking every role the posting rules use has an account, and writes nothing when one has not', function (): void {
    ($this->company)();
    ($this->fiscalYear)();
    $rows = ($this->templateRows)();

    // The shipped template maps every role the posting rules in force use.
    $used = array_keys(($this->in)(fn (): array => app(App\Modules\Accounting\Application\AccountRoles\AccountRoleMappingService::class)
        ->rolesUsedByRules((string) DB::table('books')->where('is_primary', true)->value('id'), CarbonImmutable::today())));
    expect($used)->toContain('premium_receivable', 'rounding_difference')
        ->and(array_values(array_diff($used, array_filter(array_column($rows, 'role')))))->toBe([]);

    // A posting rule in force that needs an account the chart does not give: refused, nothing imported.
    $dir = sys_get_temp_dir().'/posting-rules-'.Str::random(8);
    mkdir($dir);
    foreach (glob(resource_path('posting-rules/*.json')) ?: [] as $file) {
        copy($file, $dir.'/'.basename($file));
    }
    file_put_contents($dir.'/DAC_DEFERRED.default.json', json_encode(['code' => 'DAC_DEFERRED.default', 'version' => 1, 'event_type' => 'DAC_DEFERRED', 'effective_from' => '2026-01-01',
        'books' => ['LOCAL'], 'lines' => [['role' => 'dac_asset', 'side' => 'debit', 'amount' => 'payload.amount'], ['role' => 'commission_expense', 'side' => 'credit', 'amount' => 'payload.amount']]], JSON_THROW_ON_ERROR));
    app()->instance(App\Modules\Accounting\Application\PostingRuleRepository::class,
        new App\Modules\Accounting\Application\PostingRuleRepository($dir, app(Symfony\Component\ExpressionLanguage\ExpressionLanguage::class)));
    try {
        actingAs($this->admin)->post('/setup/chart-of-accounts', ['rows' => $rows], $this->headers)
            ->assertSessionHasErrors(['rows' => 'The posting rules need an account for: Deferred acquisition cost. Add an account for each, with that purpose, before creating the chart.']);
        expect(($this->in)(fn (): array => [DB::table('accounts')->count(), DB::table('account_role_mappings')->count(), DB::table('subledger_controls')->count(),
            DB::table('setup_progress')->where('step', 'chart_of_accounts')->count()]))->toBe([0, 0, 0, 0]);

        // With an account for it, the same chart goes through.
        $withDac = [...$rows, ['code' => '1400', 'name' => 'Deferred acquisition cost', 'type' => 'asset', 'normal_side' => 'debit', 'is_control' => false, 'control_subledger' => null, 'role' => 'dac_asset']];
        actingAs($this->admin)->post('/setup/chart-of-accounts', ['rows' => $withDac], $this->headers)->assertSessionHasNoErrors()->assertRedirect('/setup?step=bank_accounts');
        expect(($this->in)(fn (): int => DB::table('accounts')->count()))->toBe(count($withDac));
    } finally {
        array_map('unlink', glob($dir.'/*.json') ?: []);
        rmdir($dir);
    }
});

it('creates the first product with its term and VAT through the product catalogue', function (): void {
    ($this->company)();
    ($this->fiscalYear)();
    actingAs($this->admin)->post('/setup/chart-of-accounts', ['rows' => ($this->templateRows)()], $this->headers);

    actingAs($this->admin)->post('/setup/product', ['code' => 'MOTOR', 'name' => 'Motor Comprehensive', 'lob' => 'motor', 'insurance_class' => 'non_life',
        'term_months' => 12, 'effective_from' => '2026-07-01', 'vat_rate_percent' => '15', 'vat_inclusive' => true], $this->headers)->assertRedirect('/setup?step=underwriting_limits'); // GA-18 (was: step=users)

    ($this->in)(function (): void {
        $product = DB::table('products')->sole(['id', 'code', 'insurance_class']);
        $version = DB::table('product_versions')->where('product_id', $product->id)->sole(['term_months', 'tax_profile', 'effective_from', 'earning_method']);
        expect($product->code)->toBe('MOTOR')->and($product->insurance_class)->toBe('non_life')
            ->and((int) $version->term_months)->toBe(12)
            ->and($version->earning_method)->toBe('daily_365') // gap audit GA-44
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
    ]], $this->headers)->assertRedirect('/setup?step=approvals'); // fix F3: the approval limits step now follows (was: step=done)

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
        ->where('steps.0.allowed', true)->where('steps.1.allowed', false)->where('steps.2.allowed', false)->where('steps.3.allowed', false)->where('steps.4.allowed', false)->where('steps.5.allowed', true)->where('steps.6.allowed', true) // GA-18: bank accounts, product, underwriting limits, users
        ->where('steps.1.owner', 'Finance Manager'));
    actingAs($tenantAdmin)->post('/setup/company', ['code' => 'ACME', 'name' => 'Acme', 'branches' => [['code' => 'HO', 'name' => 'Head Office']]], $this->headers)->assertSessionHasNoErrors();
    actingAs($tenantAdmin)->post('/setup/fiscal-year', ['first_month' => '2026-07', 'base_currency' => 'BDT'], $this->headers)->assertSessionHasErrors('form');
    actingAs($tenantAdmin)->post('/setup/product', ['code' => 'M', 'name' => 'M', 'lob' => 'motor', 'insurance_class' => 'non_life', 'term_months' => 12, 'effective_from' => '2026-07-01'], $this->headers)
        ->assertSessionHasErrors('form');
    expect(($this->in)(fn () => [DB::table('fiscal_periods')->count(), DB::table('products')->count()]))->toBe([0, 0]);

    actingAs(($this->person)(['branch_officer']))->get('/setup', $this->headers)->assertForbidden();
});

it('offers the default approval limits to the tenant admin, who accepts them once, and nobody else', function (): void {
    ($this->company)();
    actingAs($this->admin)->get('/setup?step=approvals', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('current', 'approvals')->where('steps.7.label', 'Approval limits')->where('steps.7.allowed', true)->where('steps.7.owner', 'Tenant Admin') // GA-18: was steps.5
        ->where('approvals.existing', 0)
        ->where('approvals.defaults.0', ['label' => 'Claim payment approval', 'amount' => '500,000.00 and above', 'approvers' => 'Finance Manager → CFO'])
        ->where('approvals.defaults', fn ($defaults): bool => count($defaults) === 4));

    actingAs(($this->person)(['finance_manager']))->get('/setup', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('steps.7.allowed', false));
    actingAs(($this->person)(['finance_manager']))->post('/setup/approvals', [], $this->headers)->assertSessionHasErrors('form');
    expect(($this->in)(fn () => DB::table('approval_policies')->count()))->toBe(0);

    actingAs($this->admin)->post('/setup/approvals', [], $this->headers)->assertRedirect('/setup?step=done')
        ->assertSessionHas('status', '4 approval limits set. Change them any time in Admin → Approval limits.');
    actingAs($this->admin)->post('/setup/approvals', [], $this->headers)->assertRedirect('/setup?step=done');
    ($this->in)(function (): void {
        expect(DB::table('approval_policies')->count())->toBe(4)
            ->and(DB::table('setup_progress')->where('step', 'approvals')->exists())->toBeTrue()
            ->and(DB::table('audit_events')->where('action', 'approval_policy.created')->count())->toBe(4);
    });
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
