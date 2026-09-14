<?php

declare(strict_types=1);

use App\Http\Home\WorkQueues;
use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap audit GA-26: Home queues show real work and open where the work is done. "Receipts to record" leaves out bank credits that already have a receipt;
 * overdue premium has its own block; the approvals block is "Waiting for my approval"; queue links carry the list filter (`f.status=`) so the list shows it;
 * claim payments have a queue of their own; and referrals, renewals due, cover notes ending, licences expiring, refunds to release, commission to pay and
 * agent cash not deposited reach the roles that act on them (their branch filtering is in BranchScopedQueuesTest).
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-13 10:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->asRole = fn (string ...$codes): User => asTenant($this->ctx['tenant_id'], function () use ($codes): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => $id.'@demo.test', 'name' => implode(' + ', $codes),
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($codes as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
        }

        return User::query()->findOrFail($id);
    });
    $this->queue = function (User $user, string $key): array {
        $blocks = (array) json_decode((string) json_encode(asTenant($this->ctx['tenant_id'], fn (): array => app(WorkQueues::class)->blocks($user->id))), true);
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['key'] ?? null) === $key) {
                return $block;
            }
        }

        return [];
    };

    asTenant($this->ctx['tenant_id'], function (): void {
        $admin = $this->world['admin'];
        $lifecycle = app(PolicyLifecycle::class);
        $overdue = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'], null,
            CarbonImmutable::parse('2026-08-01'), 12_000_000, 'BDT', 12), $admin);
        $lifecycle->issue($overdue->id, CarbonImmutable::parse('2026-08-01'), $admin);  // 1 Aug and 1 Sep unpaid on 13 Sep
        $this->policy = $overdue;

        $receipts = app(ReceiptService::class);
        // DEP 7781: a receipt taken on 1 Sep that the bank credits on 10 Sep (outside the matching window, so only its reference ties them).
        $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 330_000, 'BDT', CarbonImmutable::parse('2026-09-01'),
            null, 'DEP 7781', []), $admin);
        // A receipt posted on 2 Sep that the bank matching screen suggests for the credit of 3 Sep (same amount, within the window).
        $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 250_000, 'BDT', CarbonImmutable::parse('2026-09-02'),
            null, 'counter', []), $admin);

        DB::table('bank_accounts')->insert(['id' => $bankId = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'gl_account_id' => $this->ctx['accounts']['bank_main'], 'bank_name' => 'City Bank', 'account_no_masked' => '****1', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $line = fn (string $on, int $amount, string $reference, string $description) => DB::table('bank_statement_lines')->insert(['id' => (string) Str::uuid7(),
            'tenant_id' => $this->ctx['tenant_id'], 'bank_account_id' => $bankId, 'posted_on' => $on, 'amount_minor' => $amount, 'reference' => $reference, 'description' => $description,
            'raw' => '{}', 'line_hash' => Str::random(12), 'source_file' => 'f.csv', 'match_status' => 'unmatched', 'imported_by' => $admin, 'imported_at' => now()]);
        $line('2026-09-05', 1_800_000, 'TT 1', 'Deposit');                 // nothing recorded for it: still to record
        $line('2026-09-10', 330_000, 'NPSB', 'DEP 7781 KARIM MOTOR');      // receipted with that reference
        $line('2026-09-03', 250_000, 'CASH', 'Counter deposit');           // a posted receipt is suggested for it

        $claims = app(ClaimService::class);
        $claims->register($overdue->id, CarbonImmutable::parse('2026-09-01'), 'Awaiting reserve', $admin, CarbonImmutable::parse('2026-09-02'));
        $reserved = $claims->register($overdue->id, CarbonImmutable::parse('2026-08-10'), 'Collision', $admin, CarbonImmutable::parse('2026-08-11'));
        $claims->reserve($reserved->id, 5_000_000, 'Initial', userWithPermissions($this->ctx['tenant_id'], ['claim.reserve']), CarbonImmutable::parse('2026-08-12'));
        $payment = app(ClaimPaymentService::class)->approve($reserved->id, 2_000_000, $this->world['policyholder_id'], userWithPermissions($this->ctx['tenant_id'], ['claim.approve']), CarbonImmutable::parse('2026-08-20'));
        app(ClaimPaymentService::class)->requestRelease($payment->id, userWithPermissions($this->ctx['tenant_id'], ['claim.pay_request']), null);
        $this->payment = $payment->id;

        // An approved commission statement not paid yet, and a licence of the world's agent ending in 20 days.
        DB::table('commission_statements')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'agent_id' => $this->world['agent_id'], 'number' => 'CST-2026-000001', 'up_to' => '2026-08-31', 'period_end' => '2026-08-31', 'gross_minor' => 150_000, 'withholding_minor' => 15_000,
            'net_minor' => 135_000, 'earned_minor' => 150_000, 'currency' => 'BDT', 'status' => 'approved', 'approved_by' => $admin, 'approved_on' => '2026-09-02', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('producer_licences')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'producer_id' => $this->world['agent_id'], 'authority' => 'IDRA',
            'licence_no' => 'IDRA-ENDING-1', 'class' => 'non_life', 'issued_on' => '2025-10-03', 'expires_on' => '2026-10-03', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    });
});

it('leaves bank credits that already have a receipt out of Receipts to record', function (): void {
    $officer = ($this->asRole)('branch_officer');
    $queue = ($this->queue)($officer, 'receipts_to_record');

    expect($queue['count'])->toBe(1)->and($queue['rows'][0]['cells']['reference'])->toBe('TT 1');
    actingAs($officer)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('shell.badges.bank', 1));
    // The accountant still matches all three lines on the bank screen.
    expect(($this->queue)(($this->asRole)('accountant'), 'unmatched_bank_lines')['count'])->toBe(3);
});

it('shows overdue premium in its own block, after the installments due this week', function (): void {
    $officer = ($this->asRole)('branch_officer');
    actingAs($officer)->get('/home', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.1.key', 'overdue_premium')->where('queues.1.title', 'Overdue premium')->where('queues.1.count', 2)
        ->where('queues.1.rows.0.cells.due', '2026-08-01')->where('queues.1.rows.0.href', "/policies/{$this->policy->id}")->where('queues.1.rows.1.cells.due', '2026-09-01'));
});

it('calls the approvals block Waiting for my approval and opens lists with the filter they show', function (): void {
    $finance = ($this->asRole)('finance_manager');
    expect(($this->queue)($finance, 'approvals_over_threshold')['title'])->toBe('Waiting for my approval')
        ->and(($this->queue)($finance, 'payments_to_release')['href'])->toBe('/claims/payments?f.status=release_requested');

    $claims = ($this->asRole)('claims_manager');
    expect(($this->queue)($claims, 'claims_awaiting_reserve')['href'])->toBe('/claims?f.status=registered')
        ->and(($this->queue)($claims, 'claims_to_settle')['href'])->toBe('/claims?f.status=reserved')
        ->and(($this->queue)($claims, 'payments_to_release')['href'])->toBe('/claims/payments')
        ->and(($this->queue)(($this->asRole)('finance_manager'), 'refunds_to_release')['href'])->toBe('/refunds?f.status=requested')
        ->and(($this->queue)(($this->asRole)('accountant'), 'commission_to_pay')['href'])->toBe('/distribution/statements?f.status=approved');
});

it('lists claim payments in their own queue, open ones first', function (): void {
    actingAs(($this->asRole)('finance_manager'))->get('/claims/payments', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('claims/Payments')->where('payments.total', 1)->where('payments.data.0.id', $this->payment)->where('payments.data.0.status', 'release_requested')
        ->where('payments.data.0.amount', '20,000.00')->where('payments.data.0.payee', 'Rahima Akter'));
    actingAs(($this->asRole)('branch_officer'))->get('/claims/payments', $this->headers)->assertForbidden();
});

it('gives commission to pay to the accountant and licences expiring to the branch manager', function (): void {
    $accountant = ($this->asRole)('accountant');
    $pay = ($this->queue)($accountant, 'commission_to_pay');
    expect($pay['count'])->toBe(1)->and($pay['rows'][0]['cells'])->toMatchArray(['statement' => 'CST-2026-000001', 'producer' => 'AG-001 Jamal Agent', 'amount' => '1,350.00'])
        ->and($pay['rows'][0]['href'])->toBe('/distribution/statements?period_end=2026-08-31&f.status=approved');
    // The approver never pays their own statement (commission.approve ✕ commission.pay).
    $approver = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->whereKey((string) $this->world['admin'])->firstOrFail());
    asTenant($this->ctx['tenant_id'], fn () => DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $approver->id,
        'role_id' => DB::table('roles')->where('code', 'accountant')->value('id'), 'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]));
    expect(($this->queue)($approver, 'commission_to_pay')['count'])->toBe(0);

    $licences = ($this->queue)(($this->asRole)('branch_manager'), 'licences_expiring');
    expect($licences['count'])->toBe(1)->and($licences['rows'][0]['cells'])->toMatchArray(['producer' => 'AG-001', 'licence' => 'IDRA-ENDING-1', 'expires' => '2026-10-03'])
        ->and($licences['rows'][0]['href'])->toBe("/distribution/producers/{$this->world['agent_id']}");
});

it('gives each queue to the roles that act on it', function (): void {
    $keys = fn (string $role): array => asTenant($this->ctx['tenant_id'], fn (): array => app(WorkQueues::class)->keysFor(($this->asRole)($role)->id));

    $none = fn (array $keys, array $absent): array => array_values(array_intersect($keys, $absent));

    expect($keys('branch_officer'))->toContain('overdue_premium', 'renewals_due', 'cover_notes_expiring', 'agent_cash_undeposited')
        ->and($none($keys('branch_officer'), ['referrals', 'licences_expiring', 'refunds_to_release', 'commission_to_pay']))->toBe([])
        ->and($keys('branch_manager'))->toContain('overdue_premium', 'referrals', 'renewals_due', 'cover_notes_expiring', 'agent_cash_undeposited', 'licences_expiring')
        ->and($keys('accountant'))->toContain('commission_to_pay')->and($none($keys('accountant'), ['refunds_to_release', 'referrals']))->toBe([])
        ->and($keys('finance_manager'))->toContain('referrals', 'refunds_to_release')->and($none($keys('finance_manager'), ['commission_to_pay', 'overdue_premium']))->toBe([])
        ->and($keys('cfo'))->toContain('referrals', 'refunds_to_release')
        ->and($none($keys('claims_manager'), ['overdue_premium', 'referrals', 'refunds_to_release']))->toBe([]);
});
