<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\AccountRoles\AccountRoleMappingService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Fix F4 (design §2.2 account_role_mappings, §3.4): Accounting → Account roles shows, per entity and book, the account each role posts to with its
 * effective dates and history; remapping is effective-dated (the current mapping ends the day the new one starts, never overlapping, never before a
 * day already posted with the role); roles the posting rules in force use without an account are listed in a warning banner.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-13 10:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->person = fn (string $name, array $roleCodes): User => ($this->in)(function () use ($name, $roleCodes): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => Str::slug($name).'@example.test', 'name' => $name,
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roleCodes as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
        }

        return User::query()->findOrFail($id);
    });
    $this->finance = ($this->person)('Farzana Finance', ['finance_manager']);
    $this->account = fn (string $code, string $name, bool $control = false, ?string $subledger = null, string $status = 'active', bool $postable = true): string => ($this->in)(function () use ($code, $name, $control, $subledger, $status, $postable): string {
        DB::table('accounts')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => $code, 'name' => $name,
            'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => $postable, 'is_control' => $control, 'control_subledger' => $subledger, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    $this->service = fn (): AccountRoleMappingService => app(AccountRoleMappingService::class);
    $this->map = fn (string $role, string $accountId, string $from, ?string $actor = null): string => ($this->in)(fn (): string => ($this->service)()->map($this->ctx['entity_id'], $this->ctx['book_id'],
        $role, $accountId, CarbonImmutable::parse($from), $actor ?? $this->finance->id));
    $this->mappingsOf = fn (string $role): array => ($this->in)(fn (): array => DB::table('account_role_mappings')->where('role_code', $role)->orderBy('effective_from')
        ->get(['account_id', 'effective_from', 'effective_to'])->map(fn (object $m): array => ['account_id' => (string) $m->account_id, 'from' => (string) $m->effective_from,
            'to' => $m->effective_to === null ? null : (string) $m->effective_to])->all());
});

/**
 * The row with $code from screen props or a service listing.
 *
 * @return array<array-key, mixed>
 */
function accountRoleRow(mixed $rows, string $code): array
{
    $list = json_decode((string) json_encode($rows), true);
    foreach (is_array($list) ? $list : [] as $row) {
        if (is_array($row) && ($row['code'] ?? null) === $code) {
            return $row;
        }
    }

    return [];
}

it('remaps a role from a date: the current mapping ends that day, the new one takes over, and it is audited', function (): void {
    $newBank = ($this->account)('1011', 'Bank - Sonali current account');
    ($this->map)('bank_main', $newBank, '2026-10-01');

    expect(($this->mappingsOf)('bank_main'))->toBe([
        ['account_id' => $this->ctx['accounts']['bank_main'], 'from' => '2026-01-01', 'to' => '2026-10-01'],
        ['account_id' => $newBank, 'from' => '2026-10-01', 'to' => null],
    ]);
    ($this->in)(function () use ($newBank): void {
        $audit = DB::table('audit_events')->where('action', 'account_role.mapped')->sole(['after', 'before', 'permission']);
        expect(json_decode((string) $audit->after, true))->toMatchArray(['role' => 'bank_main', 'account_id' => $newBank, 'effective_from' => '2026-10-01'])
            ->and(json_decode((string) $audit->before, true))->toMatchArray(['account_id' => $this->ctx['accounts']['bank_main'], 'effective_to' => '2026-10-01'])
            ->and($audit->permission)->toBe('accounting.manage_coa');

        // Listing on a day before and after the change.
        $before = accountRoleRow(($this->service)()->roles($this->ctx['entity_id'], $this->ctx['book_id'], CarbonImmutable::parse('2026-09-30')), 'bank_main');
        $after = accountRoleRow(($this->service)()->roles($this->ctx['entity_id'], $this->ctx['book_id'], CarbonImmutable::parse('2026-10-01')), 'bank_main');
        expect([$before['account_code'], $before['effective_to'], $after['account_code'], $after['effective_to']])->toBe(['1010', '2026-10-01', '1011', null])
            ->and($after['history'])->toHaveCount(2)->and($after['used_by_rules'])->toContain('PREMIUM_RECEIVED', 'CLAIM_PAID');
    });

    // A second remap on or before the later mapping overlaps it.
    expect(thrownBy(fn () => ($this->map)('bank_main', $this->ctx['accounts']['bank_main'], '2026-10-01'), BusinessRuleViolation::class)->reasonCode)->toBe('ROLE_MAPPING_OVERLAP')
        ->and(thrownBy(fn () => ($this->map)('bank_main', $this->ctx['accounts']['bank_main'], '2026-09-20'), BusinessRuleViolation::class)->reasonCode)->toBe('ROLE_MAPPING_OVERLAP')
        ->and(thrownBy(fn () => ($this->map)('bank_main', $newBank, '2026-11-01'), BusinessRuleViolation::class)->reasonCode)->toBe('ROLE_MAPPING_INVALID');
});

it('refuses accounts that cannot take the role, and people without accounting.manage_coa', function (): void {
    $control = ($this->account)('1101', 'Premium Receivable - Retail', true, 'premium');
    $plain = ($this->account)('1102', 'Premium Receivable - plain');
    $inactive = ($this->account)('1103', 'Old bank', status: 'inactive');
    $header = ($this->account)('1104', 'Bank accounts (heading)', postable: false);

    foreach ([[$inactive, 'bank_main'], [$header, 'bank_main'], [$plain, 'premium_receivable'], [$control, 'bank_main'], [(string) Str::uuid7(), 'bank_main']] as [$account, $role]) {
        expect(thrownBy(fn () => ($this->map)($role, $account, '2026-10-01'), BusinessRuleViolation::class)->reasonCode)->toBe('ROLE_MAPPING_INVALID');
    }
    expect(fn () => ($this->map)('bank_main', $plain, '2026-10-01', ($this->person)('Karim Accountant', ['accountant'])->id))->toThrow(PermissionDenied::class);

    // A control role takes a control account of its subledger.
    ($this->map)('premium_receivable', $control, '2026-10-01');
    expect(($this->mappingsOf)('premium_receivable'))->toHaveCount(2);
});

it('never remaps from a day already posted with the role', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'monthly');
    ($this->in)(function () use ($world): void {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $world['product_id'], $world['policyholder_id'], $world['agent_id'],
            CarbonImmutable::parse('2026-08-01'), 12_000_000, 'BDT', 1), $world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-08-01'), $world['admin']);
    });
    expect(($this->in)(fn (): int => DB::table('journal_lines')->where('role_code', 'unearned_premium')->count()))->toBeGreaterThan(0);
    $newReserve = ($this->account)('2101', 'Unearned Premium Reserve - motor');

    expect(thrownBy(fn () => ($this->map)('unearned_premium', $newReserve, '2026-07-01'), BusinessRuleViolation::class)->reasonCode)->toBe('ROLE_ALREADY_POSTED');
    ($this->map)('unearned_premium', $newReserve, '2026-09-01');
    expect(($this->mappingsOf)('unearned_premium')[1])->toMatchArray(['account_id' => $newReserve, 'from' => '2026-09-01']);
});

it('finds the roles the posting rules use that have no account in force', function (): void {
    ($this->in)(function (): void {
        expect(($this->service)()->unmappedRoles($this->ctx['entity_id'], $this->ctx['book_id'], CarbonImmutable::today()))->toBe([]);
        DB::table('account_role_mappings')->where('role_code', 'producer_advances')->delete();
        DB::table('account_role_mappings')->where('role_code', 'accounts_payable')->update(['effective_to' => '2026-09-01']);

        expect(($this->service)()->unmappedRoles($this->ctx['entity_id'], $this->ctx['book_id'], CarbonImmutable::today()))->toBe([
            ['code' => 'accounts_payable', 'description' => 'Amounts owed to suppliers and producers paid through payables', 'used_by_rules' => ['COMMISSION_PAYOUT_TO_AP']],
            ['code' => 'producer_advances', 'description' => 'Advances paid to producers, recovered from their commission', 'used_by_rules' => ['PRODUCER_ADVANCE_ISSUED', 'PRODUCER_ADVANCE_RECOVERED']],
        ])
            // recovery_receivable and dac_asset are account roles no rule in force uses: never reported.
            ->and(array_keys(($this->service)()->rolesUsedByRules($this->ctx['book_id'], CarbonImmutable::today())))->not->toContain('recovery_receivable', 'dac_asset');
    });
});

it('shows the screen with the entity and book, the roles and the unmapped banner, for people who maintain the chart of accounts', function (): void {
    ($this->in)(fn () => DB::table('account_role_mappings')->where('role_code', 'producer_advances')->delete());

    actingAs(($this->person)('Karim Accountant', ['accountant']))->get('/accounting/account-roles', $this->headers)->assertForbidden();
    actingAs($this->finance)->get('/accounting/account-roles', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/AccountRoles')
        ->where('entityId', $this->ctx['entity_id'])->where('bookId', $this->ctx['book_id'])->where('today', '2026-09-13')
        ->where('entities.0.id', $this->ctx['entity_id'])->where('books.0.code', 'LOCAL')
        ->where('unmapped', [['code' => 'producer_advances', 'description' => 'Advances paid to producers, recovered from their commission', 'used_by_rules' => ['PRODUCER_ADVANCE_ISSUED', 'PRODUCER_ADVANCE_RECOVERED']]])
        ->where('roles', fn ($roles): bool => (accountRoleRow($roles, 'bank_main')['account_code'] ?? null) === '1010'
            && array_key_exists('account_id', accountRoleRow($roles, 'producer_advances')) && accountRoleRow($roles, 'producer_advances')['account_id'] === null)
        ->where('accounts', fn ($accounts): bool => accountRoleRow($accounts, '1010') !== []));

    $newAdvances = ($this->account)('1161', 'Producer Advances - field force');
    actingAs($this->finance)->post('/accounting/account-roles', ['entity_id' => $this->ctx['entity_id'], 'book_id' => $this->ctx['book_id'], 'role' => 'producer_advances',
        'account_id' => $newAdvances, 'effective_from' => '2026-09-13'], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect('/accounting/account-roles?entity='.$this->ctx['entity_id'].'&book='.$this->ctx['book_id']);
    actingAs($this->finance)->get('/accounting/account-roles', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('unmapped', []));

    actingAs($this->finance)->post('/accounting/account-roles', ['entity_id' => $this->ctx['entity_id'], 'book_id' => $this->ctx['book_id'], 'role' => 'bank_main',
        'account_id' => $newAdvances, 'effective_from' => '2026-01-01'], $this->headers)
        ->assertSessionHasErrors(['form' => 'This role already has a mapping from 1 Jan 2026. Choose a date after it.']);
});
