<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Flow fix X10 (Part A step 10): the accountant writing a manual journal no longer leaves for Accounting → Imports when an account is missing. A holder of
 * accounting.manage_coa creates it from the line (a one-row chart-of-accounts import: its rules, errors and audit); others are told who adds accounts.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->accountant = ($this->userWith)(['accounting.view_journals', 'accounting.create_manual_journal']);
    $this->financeManager = ($this->userWith)(['accounting.view_journals', 'accounting.create_manual_journal', 'accounting.manage_coa']);
    $this->account = ['code' => '6150', 'name' => 'Office cleaning', 'type' => 'expense', 'normal_side' => 'debit', 'is_postable' => true];
});

it('tells the journal form who may add an account', function (): void {
    actingAs($this->accountant)->get('/accounting/journals/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/journals/Create')->where('canCreateAccount', false));
    actingAs($this->financeManager)->get('/accounting/journals/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('canCreateAccount', true));
});

it('creates a postable account through the chart-of-accounts import, audited, ready for the line and the journal', function (): void {
    $response = actingAs($this->financeManager)->postJson('/accounting/accounts', $this->account, $this->headers)->assertCreated()
        ->assertJsonPath('account.code', '6150')->assertJsonPath('account.name', 'Office cleaning')->assertJsonPath('account.is_postable', true)->assertJsonPath('account.is_control', false);
    $id = (string) $response->json('account.id');

    asTenant($this->ctx['tenant_id'], function () use ($id): void {
        expect(DB::table('accounts')->where('id', $id)->first(['entity_id', 'type', 'normal_side', 'status']))
            ->toEqual((object) ['entity_id' => $this->ctx['entity_id'], 'type' => 'expense', 'normal_side' => 'debit', 'status' => 'active'])
            ->and(DB::table('audit_events')->where('action', 'chart_of_accounts.imported')->value('after'))->toContain('6150')
            ->and(DB::table('audit_events')->where('action', 'chart_of_accounts.imported')->value('permission'))->toBe('accounting.manage_coa');
    });
    actingAs($this->accountant)->get('/accounting/journals/create', $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts', fn (Collection $accounts): bool => $accounts->contains('id', $id)));
    actingAs($this->financeManager)->post('/accounting/journals', ['transaction_date' => '2026-09-25', 'description' => 'Cleaning', 'kind' => 'manual', 'reason' => 'September',
        'lines' => [['account_id' => $id, 'side' => 'debit', 'amount' => '1,000.00'], ['account_id' => $this->ctx['accounts']['bank_main'], 'side' => 'credit', 'amount' => '1,000.00']]], $this->headers)
        ->assertSessionHasNoErrors();
});

it('refuses without accounting.manage_coa and shows the import errors on the drawer fields', function (): void {
    actingAs($this->accountant)->postJson('/accounting/accounts', $this->account, $this->headers)->assertForbidden();

    actingAs($this->financeManager)->postJson('/accounting/accounts', [...$this->account, 'code' => '1010', 'name' => '', 'type' => 'cost', 'normal_side' => 'left'], $this->headers)
        ->assertUnprocessable()->assertJsonPath('errors.code.0', 'Account 1010 already exists; imports never update accounts.')
        ->assertJsonPath('errors.name.0', 'Account name is required.')->assertJsonPath('errors.type.0', 'Type must be one of: asset, liability, equity, income, expense.')
        ->assertJsonPath('errors.normal_side.0', 'Normal side must be debit or credit.');
    // A name with a comma or quotes survives the one-row file.
    actingAs($this->financeManager)->postJson('/accounting/accounts', [...$this->account, 'name' => 'Cleaning, "deep" and windows'], $this->headers)->assertCreated()
        ->assertJsonPath('account.name', 'Cleaning, "deep" and windows');
    expect(asTenant($this->ctx['tenant_id'], fn (): int => DB::table('accounts')->where('code', '6150')->count()))->toBe(1);
});
