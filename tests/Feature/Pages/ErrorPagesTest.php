<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

/**
 * Gap fixes GA-07 and GA-23. A refused page was bare text with no shell, title, language or way back, and links on several screens led to it; missing
 * pages and failures showed Laravel's own pages. Browser requests now get an in-app error page (403 with what the page needs, the roles that give it and
 * the administrators who can grant it; 404, 419, 500), JSON keeps its body, and a refusal is logged as a notice, not an error with a stack trace.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->asRole = fn (string $role, string $name = 'Someone'): User => ($this->in)(function () use ($role, $name): User {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'email' => "{$role}-{$id}@example.test", 'name' => $name, 'status' => 'active']);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $role)->value('id'),
            'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);

        return User::query()->findOrFail($id);
    });
});

it('shows a refused page inside the application with what it needs, the roles that give it and who grants access; JSON keeps its body', function (): void {
    $admin = ($this->asRole)('tenant_admin', 'Nasrin Admin');
    $officer = ($this->asRole)('branch_officer');

    actingAs($officer)->get('/bank', $this->headers)->assertForbidden()->assertInertia(fn (AssertableInertia $page) => $page->component('errors/Error')
        ->where('status', 403)->where('title', 'You do not have access to this page')
        ->where('permissions', fn ($permissions): bool => array_column(json_decode((string) json_encode($permissions), true), 'code') === ['bank.import', 'bank.match', 'bank.manage_accounts', 'reports.financial'])
        ->where('access.admins', fn ($admins): bool => in_array(['name' => 'Nasrin Admin', 'email' => $admin->email], json_decode((string) json_encode($admins), true), true))
        ->where('access.roles', fn ($roles): bool => in_array('Accountant', (array) json_decode((string) json_encode($roles), true), true))
        ->where('access.subject', 'Access request: /bank')
        ->has('auth.user')->has('shell'));

    actingAs($officer)->get('/admin/roles', [...$this->headers, 'referer' => 'http://localhost/home'])->assertForbidden()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('permissions.0', ['code' => 'platform.manage_roles', 'label' => 'Manage roles', 'help' => 'Create roles and choose their permissions.'])
        ->where('access.subject', 'Access request: Manage roles')->where('back', 'http://localhost/home'));

    actingAs($officer)->getJson('/bank', $this->headers)->assertForbidden()->assertJsonPath('reason', 'PERMISSION_DENIED');
    // A refused form still goes back to the form with the reason.
    actingAs(($this->asRole)('claims_officer'))->from('/receipts/create')->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'cash', 'amount' => '1.00', 'value_date' => '2026-09-15'], $this->headers)
        ->assertRedirect('/receipts/create')->assertSessionHasErrors('form');
    // The page keeps the document language.
    actingAs($officer)->get('/bank', $this->headers)->assertSee('<html lang="en"', false);
});

it('shows missing records and pages, expired forms and failures as the in-app error page for browsers only', function (): void {
    $admin = ($this->in)(fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    actingAs($admin)->get('/claims/'.Str::uuid7(), $this->headers)->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('errors/Error')
        ->where('status', 404)->where('title', 'Page not found')->where('access', null));
    actingAs($admin)->getJson('/claims/'.Str::uuid7(), $this->headers)->assertNotFound()->assertJsonMissingPath('component');
    get('/no-such-page', $this->headers)->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('errors/Error')->where('status', 404));

    Route::middleware('web')->get('/_test/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
    Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('boom'));
    actingAs($admin)->get('/_test/expired', $this->headers)->assertStatus(419)->assertInertia(fn (AssertableInertia $page) => $page->component('errors/Error')->where('title', 'This page expired'));

    config(['app.debug' => false]);
    actingAs($admin)->get('/_test/boom', $this->headers)->assertStatus(500)->assertInertia(fn (AssertableInertia $page) => $page->component('errors/Error')->where('title', 'Something went wrong'));
    actingAs($admin)->getJson('/_test/boom', $this->headers)->assertStatus(500)->assertJsonMissingPath('component');
    config(['app.debug' => true]);
    expect(actingAs($admin)->get('/_test/boom', $this->headers)->assertStatus(500)->headers->has('X-Inertia'))->toBeFalse(); // the debug page stays for developers
});

it('logs a refused permission as a notice without a stack trace', function (): void {
    $log = Log::spy();
    $officer = ($this->asRole)('branch_officer');
    actingAs($officer)->get('/bank', $this->headers)->assertForbidden();

    $log->shouldHaveReceived('notice')->with('Permission denied', ['user_id' => $officer->id, 'permission' => 'bank.import|bank.match|bank.manage_accounts|reports.financial'])->once();
    $log->shouldNotHaveReceived('error');
});

it('sends branch roles from "Receipts to record" to the receipt form filled in from the bank line, and bank users to the bank', function (): void {
    [$bankId, $lineId] = ($this->in)(function (): array {
        DB::table('bank_accounts')->insert(['id' => $bankId = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'gl_account_id' => $this->ctx['accounts']['bank_main'], 'bank_name' => 'City Bank', 'account_no_masked' => '****1', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bank_statement_lines')->insert(['id' => $lineId = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'bank_account_id' => $bankId, 'posted_on' => '2026-09-05',
            'amount_minor' => 1_800_000, 'reference' => 'TT 1', 'description' => 'Deposit', 'raw' => '{}', 'line_hash' => 'h1', 'source_file' => 'f.csv', 'match_status' => 'unmatched',
            'imported_by' => $this->world['admin'], 'imported_at' => now()]);

        return [$bankId, $lineId];
    });
    $officer = ($this->asRole)('branch_officer');
    actingAs($officer)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.3.key', 'receipts_to_record')->where('queues.3.href', '/receipts/create')->where('queues.3.rows.0.href', "/receipts/create?statement_line={$lineId}"));
    actingAs($officer)->get("/receipts/create?statement_line={$lineId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('statementLine', ['amount' => '18,000.00', 'value_date' => '2026-09-05', 'reference' => 'TT 1', 'bank_account_id' => $bankId]));
    actingAs($officer)->get('/receipts/create?statement_line='.Str::uuid7(), $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('statementLine', null));

    $accountant = ($this->asRole)('accountant');
    actingAs($accountant)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.1.key', 'unmatched_bank_lines')->where('queues.1.rows.0.href', "/bank/{$bankId}"));
});

it('links a journal to its source record only for readers who may open that record', function (): void {
    $claimJournal = ($this->in)(function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        $claims = app(ClaimService::class);
        $claim = $claims->register($policy->id, CarbonImmutable::parse('2026-09-05'), 'Collision', $this->world['admin'], CarbonImmutable::parse('2026-09-06'));
        $claims->reserve($claim->id, 1_000_000, 'Initial', $this->world['admin'], CarbonImmutable::parse('2026-09-07'));

        return (string) DB::table('journals')->where('source_type', 'claim_reserve')->value('id');
    });
    $journalsOnly = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['accounting.view_journals'])));
    actingAs($journalsOnly)->get("/accounting/journals/{$claimJournal}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('sourceLink', null));
    $admin = ($this->in)(fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    actingAs($admin)->get("/accounting/journals/{$claimJournal}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('sourceLink', fn (string $link): bool => str_starts_with($link, '/claims/')));
});
