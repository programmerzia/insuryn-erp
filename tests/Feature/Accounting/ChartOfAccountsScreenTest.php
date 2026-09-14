<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\ChartOfAccounts\ChartOfAccountsQuery;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * UX U2: Accounting → Chart of accounts. The product owner found no screen to add an account (only a CSV import, the setup wizard and X10's journal line).
 * The screen lists the chart as a tree and, for accounting.manage_coa, adds accounts under the import's rules, edits them within A-167 and deactivates
 * them only when nothing is left on them (A-168). Every change is audited.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Queue::fake();
    travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->userWith = fn (array $permissions): User => ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->finance = ($this->userWith)(['accounting.view_journals', 'accounting.create_manual_journal', 'accounting.manage_coa']);
    $this->accountant = ($this->userWith)(['accounting.view_journals', 'accounting.create_manual_journal']);
    $this->outsider = ($this->userWith)(['receipt.create']);
    $this->idOf = fn (string $code): string => (string) ($this->in)(fn (): mixed => DB::table('accounts')->where('code', $code)->value('id'));
    $this->row = fn (string $code): ?object => ($this->in)(fn (): ?object => DB::table('accounts')->where('code', $code)->first());
    $this->audits = fn (string $action): Collection => ($this->in)(fn (): Collection => DB::table('audit_events')->where('action', $action)->get(['object_id as subject_id', 'before', 'after', 'permission', 'actor_user_id as actor_id']));
    $this->new = ['code' => '6000', 'name' => 'Office expenses', 'type' => 'expense', 'normal_side' => 'debit', 'parent_id' => null, 'is_postable' => false];
    $this->postPremium = fn (): mixed => ($this->in)(function (): void {
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)($this->ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'coa-screen-'.Str::random(6),
            CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-14'), 'BDT', ['amount' => 150000],
            ['branch' => $this->ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()]));
        app(PostingEngine::class)->post($event->id);
    });
});

it('opens for journal readers and chart managers only, and says who may change it', function (): void {
    actingAs($this->outsider)->get('/accounting/chart-of-accounts', $this->headers)->assertForbidden();
    actingAs($this->accountant)->get('/accounting/chart-of-accounts', $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/ChartOfAccounts')->where('canManage', false)->has('accounts', 31)); // GA-14: the demo chart gains 1025 Cheques in Clearing and 5600 Bank Charges; W7: 5450 Premium Written Off (was 28)
    actingAs($this->finance)->get('/accounting/chart-of-accounts', $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', true)->where('types', ['asset', 'liability', 'equity', 'income', 'expense']));
});

it('lists the chart as a tree by code with roles, posting and the balance on the normal side', function (): void {
    ($this->postPremium)();
    actingAs($this->finance)->post('/accounting/chart-of-accounts', $this->new, $this->headers)->assertSessionHasNoErrors();
    actingAs($this->finance)->post('/accounting/chart-of-accounts', ['code' => '6100', 'name' => 'Cleaning', 'type' => 'expense', 'normal_side' => 'debit', 'parent_id' => ($this->idOf)('6000')], $this->headers)
        ->assertSessionHasNoErrors();

    $tree = ($this->in)(fn (): array => app(ChartOfAccountsQuery::class)->tree($this->ctx['entity_id'], $this->ctx['book_id'], CarbonImmutable::today()));
    actingAs($this->accountant)->get('/accounting/chart-of-accounts', $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts', fn (Collection $rows): bool => $rows->pluck('code')->all() === array_column($tree, 'code')));
    $codes = array_column($tree, 'code');
    expect($codes)->toContain('6000', '6100')
        ->and(array_search('6100', $codes, true))->toBe(array_search('6000', $codes, true) + 1)
        ->and(chartOfAccountsRow($tree, '6100'))->toMatchArray(['depth' => 1, 'parent_code' => '6000', 'is_postable' => true, 'has_lines' => false, 'balance_minor' => 0])
        ->and(chartOfAccountsRow($tree, '6000'))->toMatchArray(['depth' => 0, 'is_postable' => false])
        ->and(chartOfAccountsRow($tree, '1010'))->toMatchArray(['has_lines' => true, 'balance_minor' => 150000, 'roles' => [['code' => 'bank_main', 'description' => 'Main bank account']]])
        ->and(chartOfAccountsRow($tree, '1100'))->toMatchArray(['balance_minor' => -150000, 'is_control' => true, 'control_subledger' => 'premium']);
});

/**
 * @param array<int, array<string, mixed>> $tree
 * @return array<string, mixed>
 */
function chartOfAccountsRow(array $tree, string $code): array
{
    foreach ($tree as $row) {
        if (($row['code'] ?? null) === $code) {
            return $row;
        }
    }

    throw new PHPUnit\Framework\AssertionFailedError("Account {$code} is not in the chart.");
}

it('adds an account through the import rules, audited, ready for journals', function (): void {
    actingAs($this->finance)->post('/accounting/chart-of-accounts', $this->new, $this->headers)->assertSessionHasNoErrors()->assertRedirect('/accounting/chart-of-accounts');
    actingAs($this->finance)->post('/accounting/chart-of-accounts', ['code' => '6150', 'name' => 'Office cleaning', 'type' => 'expense', 'normal_side' => 'debit',
        'parent_id' => ($this->idOf)('6000'), 'is_postable' => true, 'currency' => 'bdt'], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->finance)->post('/accounting/chart-of-accounts', ['code' => '1170', 'name' => 'Reinsurer receivable', 'type' => 'asset', 'normal_side' => 'debit',
        'is_control' => true, 'control_subledger' => 'ar'], $this->headers)->assertSessionHasNoErrors();

    $cleaning = ($this->row)('6150');
    expect($cleaning)->toMatchObject(['entity_id' => $this->ctx['entity_id'], 'parent_id' => ($this->idOf)('6000'), 'type' => 'expense', 'normal_side' => 'debit',
        'is_postable' => true, 'is_control' => false, 'currency' => 'BDT', 'status' => 'active'])
        ->and(($this->row)('1170'))->toMatchObject(['is_control' => true, 'control_subledger' => 'ar']);
    $audit = ($this->audits)('account.created')->firstWhere('subject_id', $cleaning->id);
    expect($audit->permission)->toBe('accounting.manage_coa')->and($audit->actor_id)->toBe($this->finance->id)
        ->and(json_decode((string) $audit->after, true))->toMatchArray(['code' => '6150', 'name' => 'Office cleaning', 'parent_id' => ($this->idOf)('6000')]);
    actingAs($this->accountant)->get('/accounting/journals/create', $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts', fn (Collection $accounts): bool => $accounts->contains('code', '6150') && ! $accounts->contains('code', '6000')));
});

it('refuses an account the import would refuse, with each problem on its field, and writes nothing', function (): void {
    $before = ($this->in)(fn (): int => DB::table('accounts')->count());
    actingAs($this->finance)->postJson('/accounting/chart-of-accounts', ['code' => '1010', 'name' => 'Bank again', 'type' => 'cost', 'normal_side' => 'left'], $this->headers)
        ->assertUnprocessable()->assertJsonPath('errors.code.0', 'Account 1010 already exists.')
        ->assertJsonPath('errors.type.0', 'Type must be one of: asset, liability, equity, income, expense.')->assertJsonPath('errors.normal_side.0', 'Normal side must be debit or credit.');
    actingAs($this->finance)->postJson('/accounting/chart-of-accounts', [...$this->new, 'is_control' => true, 'currency' => 'TK'], $this->headers)
        ->assertUnprocessable()->assertJsonPath('errors.control_subledger.0', 'A control account needs a subledger: premium, claims, commission, customer, agent, bank, ap, ar, suspense.')
        ->assertJsonPath('errors.currency.0', 'Currency must be a three-letter ISO code.');
    actingAs($this->finance)->postJson('/accounting/chart-of-accounts', [...$this->new, 'name' => '', 'parent_id' => (string) Str::uuid7()], $this->headers)
        ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Account name is required.');
    actingAs($this->finance)->postJson('/accounting/chart-of-accounts', [...$this->new, 'parent_id' => (string) Str::uuid7()], $this->headers)
        ->assertUnprocessable()->assertJsonPath('errors.parent_id.0', 'Choose a parent account from this chart.');
    actingAs($this->accountant)->postJson('/accounting/chart-of-accounts', $this->new, $this->headers)->assertForbidden();

    expect(($this->in)(fn (): int => DB::table('accounts')->count()))->toBe($before)->and(($this->audits)('account.created'))->toBeEmpty();
});

it('renames and moves an account, audits only what changed, and never under itself or its own child', function (): void {
    actingAs($this->finance)->post('/accounting/chart-of-accounts', $this->new, $this->headers);
    actingAs($this->finance)->post('/accounting/chart-of-accounts', ['code' => '6100', 'name' => 'Cleaning', 'type' => 'expense', 'normal_side' => 'debit', 'parent_id' => ($this->idOf)('6000')], $this->headers);
    $parent = ($this->idOf)('6000');
    $child = ($this->idOf)('6100');

    actingAs($this->finance)->put("/accounting/chart-of-accounts/{$child}", ['name' => 'Cleaning and waste', 'type' => 'expense', 'normal_side' => 'debit', 'parent_id' => null, 'is_postable' => true], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect('/accounting/chart-of-accounts');
    expect(($this->row)('6100'))->toMatchObject(['name' => 'Cleaning and waste', 'parent_id' => null]);
    $audit = ($this->audits)('account.updated')->sole();
    expect(json_decode((string) $audit->before, true))->toBe(['name' => 'Cleaning', 'parent_id' => $parent])
        ->and(json_decode((string) $audit->after, true))->toBe(['name' => 'Cleaning and waste', 'parent_id' => null])->and($audit->permission)->toBe('accounting.manage_coa');

    actingAs($this->finance)->put("/accounting/chart-of-accounts/{$child}", ['name' => 'Cleaning', 'type' => 'expense', 'normal_side' => 'debit', 'parent_id' => $parent, 'is_postable' => true], $this->headers);
    actingAs($this->finance)->putJson("/accounting/chart-of-accounts/{$parent}", [...$this->new, 'parent_id' => $child], $this->headers)
        ->assertUnprocessable()->assertJsonPath('errors.parent_id.0', 'That account sits under 6000; an account cannot sit under its own child.');
    actingAs($this->finance)->putJson("/accounting/chart-of-accounts/{$parent}", [...$this->new, 'parent_id' => $parent], $this->headers)
        ->assertUnprocessable()->assertJsonPath('errors.parent_id.0', 'An account cannot sit under itself.');
    // An account without journal lines may still change type and side.
    actingAs($this->finance)->put("/accounting/chart-of-accounts/{$child}", ['name' => 'Cleaning', 'type' => 'income', 'normal_side' => 'credit', 'parent_id' => $parent, 'is_postable' => true], $this->headers)
        ->assertSessionHasNoErrors();
    expect(($this->row)('6100'))->toMatchObject(['type' => 'income', 'normal_side' => 'credit']);
    actingAs($this->accountant)->putJson("/accounting/chart-of-accounts/{$child}", ['name' => 'Mine', 'type' => 'income', 'normal_side' => 'credit'], $this->headers)->assertForbidden();
});

it('keeps the type, side and postability of an account journal lines name (A-167), and a mapped role account postable', function (): void {
    ($this->postPremium)();
    $bank = ($this->idOf)('1010');
    actingAs($this->finance)->putJson("/accounting/chart-of-accounts/{$bank}", ['name' => 'Bank - Main', 'type' => 'expense', 'normal_side' => 'debit', 'is_postable' => true], $this->headers)
        ->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_HAS_POSTINGS');
    actingAs($this->finance)->putJson("/accounting/chart-of-accounts/{$bank}", ['name' => 'Bank - Main', 'type' => 'asset', 'normal_side' => 'credit', 'is_postable' => true], $this->headers)
        ->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_HAS_POSTINGS');
    actingAs($this->finance)->putJson("/accounting/chart-of-accounts/{$bank}", ['name' => 'Bank - Main', 'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => false], $this->headers)
        ->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_HAS_POSTINGS')->assertJsonPath('message', 'Account 1010 already has journal lines, so it stays postable.');
    // Browser form: the refusal comes back as the form error.
    actingAs($this->finance)->from('/accounting/chart-of-accounts')->put("/accounting/chart-of-accounts/{$bank}", ['name' => 'Bank - Main', 'type' => 'liability', 'normal_side' => 'credit', 'is_postable' => true], $this->headers)
        ->assertRedirect('/accounting/chart-of-accounts')->assertSessionHasErrors(['form' => 'Account 1010 already has journal lines, so its type and normal side stay as they are. Open a new account and move the balance with a journal.']);
    // No lines, but the stamp duty role posts there: it cannot become a heading.
    actingAs($this->finance)->putJson('/accounting/chart-of-accounts/'.($this->idOf)('2155'), ['name' => 'Stamp Duty Payable', 'type' => 'liability', 'normal_side' => 'credit', 'is_postable' => false], $this->headers)
        ->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_ROLE_MAPPED');

    expect(($this->row)('1010'))->toMatchObject(['type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true])
        ->and(($this->row)('2155'))->toMatchObject(['is_postable' => true])->and(($this->audits)('account.updated'))->toBeEmpty();
    // Renaming stays allowed.
    actingAs($this->finance)->put("/accounting/chart-of-accounts/{$bank}", ['name' => 'Sonali Bank - Main', 'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true], $this->headers)->assertSessionHasNoErrors();
    expect(($this->row)('1010')->name)->toBe('Sonali Bank - Main');
});

it('deactivates only an account with nothing left on it (A-168), audited, and reactivates it', function (): void {
    ($this->postPremium)();
    $deactivate = fn (string $code) => actingAs($this->finance)->postJson('/accounting/chart-of-accounts/'.($this->idOf)($code).'/deactivate', [], $this->headers);

    $deactivate('1010')->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_HAS_BALANCE')
        ->assertJsonPath('message', 'Account 1010 has a balance of 1,500.00 BDT debit. Move it to another account with a journal, then deactivate.');
    $deactivate('2155')->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_ROLE_MAPPED');

    actingAs($this->finance)->post('/accounting/chart-of-accounts', $this->new, $this->headers);
    actingAs($this->finance)->post('/accounting/chart-of-accounts', ['code' => '6100', 'name' => 'Cleaning', 'type' => 'expense', 'normal_side' => 'debit', 'parent_id' => ($this->idOf)('6000')], $this->headers);
    actingAs($this->finance)->post('/accounting/chart-of-accounts', ['code' => '1030', 'name' => 'Bank - Dutch-Bangla', 'type' => 'asset', 'normal_side' => 'debit'], $this->headers);
    $deactivate('6000')->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_HAS_ACTIVE_CHILDREN')->assertJsonPath('message', 'Account 6100 sits under 6000 and is active. Deactivate or move it first.');

    ($this->in)(fn (): bool => DB::table('bank_accounts')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
        'gl_account_id' => ($this->idOf)('1030'), 'bank_name' => 'Dutch-Bangla Bank', 'account_no_masked' => '****1234', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
    $deactivate('1030')->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_IN_USE')->assertJsonPath('message', 'The Dutch-Bangla Bank bank account posts to account 1030. Close that bank account first.');

    // A journal waiting for approval still names 6100.
    actingAs($this->finance)->post('/accounting/journals', ['transaction_date' => '2026-09-14', 'description' => 'Cleaning', 'kind' => 'manual', 'reason' => 'September',
        'lines' => [['account_id' => ($this->idOf)('6100'), 'side' => 'debit', 'amount' => '100.00'], ['account_id' => ($this->idOf)('5900'), 'side' => 'credit', 'amount' => '100.00']]], $this->headers)
        ->assertSessionHasNoErrors();
    $deactivate('6100')->assertUnprocessable()->assertJsonPath('reason', 'ACCOUNT_IN_OPEN_JOURNAL');
    expect(($this->audits)('account.deactivated'))->toBeEmpty();

    actingAs($this->accountant)->postJson('/accounting/chart-of-accounts/'.($this->idOf)('6000').'/deactivate', [], $this->headers)->assertForbidden();
    actingAs($this->finance)->put('/accounting/chart-of-accounts/'.($this->idOf)('6100'), ['name' => 'Cleaning', 'type' => 'expense', 'normal_side' => 'debit', 'parent_id' => null, 'is_postable' => true], $this->headers);
    actingAs($this->finance)->post('/accounting/chart-of-accounts/'.($this->idOf)('6000').'/deactivate', [], $this->headers)->assertSessionHasNoErrors()->assertRedirect('/accounting/chart-of-accounts');
    expect(($this->row)('6000')->status)->toBe('inactive')
        ->and(($this->audits)('account.deactivated')->sole())->toMatchObject(['subject_id' => ($this->idOf)('6000'), 'permission' => 'accounting.manage_coa', 'actor_id' => $this->finance->id]);

    actingAs($this->finance)->post('/accounting/chart-of-accounts/'.($this->idOf)('6000').'/reactivate', [], $this->headers)->assertSessionHasNoErrors();
    expect(($this->row)('6000')->status)->toBe('active')->and(($this->audits)('account.reactivated'))->toHaveCount(1);
});
