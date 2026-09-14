<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ChequeBounceService;
use App\Modules\Insurance\Collections\Application\ChequeClearingService;
use App\Modules\Insurance\Collections\Application\ChequeDetails;
use App\Modules\Insurance\Collections\Application\ChequeRegisterQuery;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
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
 * Gap fix GA-14: a cheque sits in "cheques in clearing" until the bank credits it; clearing moves it into the bank. A cheque that bounces before that
 * leaves clearing (it never reached the bank), the bank's charge is booked, the policy shows the unpaid premium, the branch's Home lists it and the first
 * payment reminder goes out at once. A company without a cheques-in-clearing account keeps posting cheques straight to the bank.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-16 10:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->manager = userWithPermissions($this->ctx['tenant_id'], ['receipt.create', 'receipt.allocate', 'policy.create']);
    [$this->policyId, $this->installments] = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 3), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    });
    $this->bankAccountId = asTenant($this->ctx['tenant_id'], function (): string {
        DB::table('bank_accounts')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'gl_account_id' => $this->ctx['accounts']['bank_main'], 'bank_name' => 'City Bank', 'account_no_masked' => '****4471', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    $this->cheque = fn (string $no, int $amount, array $allocations, string $date = '2026-09-10') => app(ReceiptService::class)->record(new RecordReceiptRequest(
        $this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cheque', $amount, 'BDT', CarbonImmutable::parse($date), $this->bankAccountId, "CHQ {$no}", array_values($allocations),
        new ChequeDetails($no, 'Sonali Bank', CarbonImmutable::parse($date))), $this->world['admin']);
    $this->gl = fn (string $role): int => (int) DB::table('journal_lines')->where('account_id', $this->ctx['accounts'][$role])
        ->selectRaw("coalesce(sum(case when side = 'debit' then amount_minor else -amount_minor end), 0) as b")->value('b');
    $this->lines = fn (string $eventType): array => array_values(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.description', $eventType)
        ->orderBy('j.id')->orderBy('l.line_no')->get(['l.role_code', 'l.side', 'l.amount_minor'])->map(fn (object $l): array => [(string) $l->role_code, (string) $l->side, (int) $l->amount_minor])->all());
});

it('keeps a cheque in clearing until the bank credits it, then moves it into the bank', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $receipt = ($this->cheque)('900001', 5_000_000, [new AllocationLine($this->installments[0], 4_000_000)]); // 1,000,000 in suspense
        expect(DB::table('receipts')->where('id', $receipt->id)->value('in_clearing'))->toBeTrue()
            ->and(($this->lines)('PREMIUM_RECEIVED'))->toBe([['cheques_in_clearing', 'debit', 4_000_000], ['premium_receivable', 'credit', 4_000_000]])
            ->and(($this->lines)('RECEIPT_RECORDED'))->toBe([['cheques_in_clearing', 'debit', 1_000_000], ['suspense_receipts', 'credit', 1_000_000]])
            ->and([($this->gl)('cheques_in_clearing'), ($this->gl)('bank_main')])->toBe([5_000_000, 0]);

        $clearing = app(ChequeClearingService::class);
        expect(thrownBy(fn () => $clearing->clear($receipt->id, $this->manager, CarbonImmutable::parse('2026-09-09')), BusinessRuleViolation::class)->reasonCode)->toBe('CLEARED_BEFORE_RECEIPT')
            ->and(fn () => $clearing->clear($receipt->id, userWithPermissions($this->ctx['tenant_id'], ['receipt.create']), CarbonImmutable::parse('2026-09-12')))->toThrow(PermissionDenied::class);

        $clearing->clear($receipt->id, $this->manager, CarbonImmutable::parse('2026-09-12'));
        expect(($this->lines)('CHEQUE_CLEARED'))->toBe([['bank_main', 'debit', 5_000_000], ['cheques_in_clearing', 'credit', 5_000_000]])
            ->and([($this->gl)('cheques_in_clearing'), ($this->gl)('bank_main')])->toBe([0, 5_000_000])
            ->and(DB::table('accounting_events')->where('event_type', 'CHEQUE_CLEARED')->value('payload'))->toContain('"receipt_number": "'.$receipt->number.'"')
            ->and(thrownBy(fn () => $clearing->clear($receipt->id, $this->manager, CarbonImmutable::parse('2026-09-13')), BusinessRuleViolation::class)->reasonCode)->toBe('ALREADY_CLEARED')
            ->and(DB::table('audit_events')->where('action', 'receipt.cheque_cleared')->count())->toBe(1);

        $cash = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 1_000, 'BDT', CarbonImmutable::parse('2026-09-10'), null, null, []), $this->world['admin']);
        expect(thrownBy(fn () => $clearing->clear($cash->id, $this->manager, CarbonImmutable::parse('2026-09-12')), BusinessRuleViolation::class)->reasonCode)->toBe('NOT_A_CHEQUE');

        // A cheque that bounces after it cleared leaves the bank, as before.
        app(ChequeBounceService::class)->bounce($receipt->id, 'Stopped by drawer', $this->manager, CarbonImmutable::parse('2026-09-14')); // the cash receipt above left 1,000 in the bank
        expect(($this->lines)('PREMIUM_RECEIPT_REVERSED'))->toBe([['premium_receivable', 'debit', 4_000_000], ['bank_main', 'credit', 4_000_000]])
            ->and([($this->gl)('cheques_in_clearing'), ($this->gl)('bank_main')])->toBe([0, 1_000]);

        ($this->cheque)('900002', 2_000_000, []);
        $register = app(ChequeRegisterQuery::class)->register($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
        expect(array_column($register['rows'], 'state'))->toBe(['bounced', 'in_clearing'])
            ->and($register['totals'])->toBe(['presented_minor' => 2_000_000, 'bounced_minor' => 5_000_000, 'in_clearing_minor' => 2_000_000, 'cleared_minor' => 0]);
    });
});

it('reverses a cheque bounced before clearing out of clearing, books the bank charge and chases the premium at once', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $receipt = ($this->cheque)('900003', 4_000_000, [new AllocationLine($this->installments[0], 4_000_000)]);
        app(ReconciliationService::class)->runAll((string) DB::table('fiscal_periods')->where('ends', '2026-09-30')->value('id'), CarbonImmutable::parse('2026-09-11'));

        app(ChequeBounceService::class)->bounce($receipt->id, 'Insufficient funds', $this->manager, CarbonImmutable::parse('2026-09-15'), 57_500);

        expect(($this->lines)('PREMIUM_RECEIPT_REVERSED'))->toBe([['premium_receivable', 'debit', 4_000_000], ['cheques_in_clearing', 'credit', 4_000_000]])
            ->and(($this->lines)('CHEQUE_RETURN_CHARGED'))->toBe([['bank_charges', 'debit', 57_500], ['bank_main', 'credit', 57_500]])
            ->and([($this->gl)('cheques_in_clearing'), ($this->gl)('bank_main'), ($this->gl)('bank_charges')])->toBe([0, -57_500, 57_500])
            ->and(DB::table('receipts')->where('id', $receipt->id)->value('bounce_charge_minor'))->toBe(57_500)
            ->and(DB::table('dunning_notices')->get(['installment_id', 'level', 'issued_on', 'outstanding_minor', 'days_overdue'])->map(fn (object $n): array => (array) $n)->all())
                ->toBe([['installment_id' => $this->installments[0], 'level' => 1, 'issued_on' => '2026-09-15', 'outstanding_minor' => 4_000_000, 'days_overdue' => 14]])
            ->and(DB::table('outbox')->where('message_type', 'DunningNoticeDue')->value('payload'))->toContain('cheque_bounced')
            ->and(DB::table('accounting_events')->where('status', '<>', 'posted')->count())->toBe(0);

        foreach (['2026-09-15', '2026-09-30'] as $asOf) {
            app(ReconciliationService::class)->runAll((string) DB::table('fiscal_periods')->where('ends', '2026-09-30')->value('id'), CarbonImmutable::parse($asOf));
        }
        expect(DB::table('reconciliation_runs')->where('status', 'variance')->count())->toBe(0)
            ->and(thrownBy(fn () => app(ChequeClearingService::class)->clear($receipt->id, $this->manager, CarbonImmutable::parse('2026-09-16')), BusinessRuleViolation::class)->reasonCode)->toBe('ALREADY_BOUNCED')
            ->and(thrownBy(fn () => app(ChequeBounceService::class)->bounce(($this->cheque)('900004', 1_000, [])->id, 'x', $this->manager, CarbonImmutable::parse('2026-09-15'), -1), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_AMOUNT');
    });

    // The policy says so, and the branch's Home lists it until the premium is paid again.
    $officer = asTenant($this->ctx['tenant_id'], function (): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => $id.'@demo.test', 'name' => 'Officer', 'password' => 'x',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', 'branch_manager')->value('id'),
            'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);

        return User::query()->findOrFail($id);
    });
    $number = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('policies')->where('id', $this->policyId)->value('number'));
    actingAs($officer)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('policies/Show')
        ->has('bouncedPremium', 1)->where('bouncedPremium.0.cheque_no', '900003')->where('bouncedPremium.0.installment_no', 1)->where('bouncedPremium.0.outstanding', '40,000.00')
        ->where('bouncedPremium.0.bounced_on', '2026-09-15'));
    actingAs($officer)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.5.key', 'bounced_premium')->where('queues.5.count', 1)->where('queues.5.rows.0.cells.policy', $number) // after GA-03's receipts to allocate
        ->where('queues.5.rows.0.cells.amount', '40,000.00')->where('queues.5.rows.0.href', "/policies/{$this->policyId}"));

    asTenant($this->ctx['tenant_id'], fn () => app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 4_000_000, 'BDT',
        CarbonImmutable::parse('2026-09-16'), $this->bankAccountId, 'TRF', [new AllocationLine($this->installments[0], 4_000_000)]), $this->world['admin']));
    actingAs($officer)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('bouncedPremium', 0));
    actingAs($officer)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('queues.5.key', 'bounced_premium')->where('queues.5.count', 0));
});

it('posts cheques straight to the bank for a company without a cheques-in-clearing account', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        DB::table('account_role_mappings')->where('role_code', 'cheques_in_clearing')->delete();
        $receipt = ($this->cheque)('900005', 3_000_000, [new AllocationLine($this->installments[0], 3_000_000)]);

        expect(DB::table('receipts')->where('id', $receipt->id)->value('in_clearing'))->toBeFalse()
            ->and(($this->lines)('PREMIUM_RECEIVED'))->toBe([['bank_main', 'debit', 3_000_000], ['premium_receivable', 'credit', 3_000_000]])
            ->and(thrownBy(fn () => app(ChequeClearingService::class)->clear($receipt->id, $this->manager, CarbonImmutable::parse('2026-09-12')), BusinessRuleViolation::class)->reasonCode)->toBe('CHEQUE_NOT_IN_CLEARING');

        app(ChequeBounceService::class)->bounce($receipt->id, 'Refer to drawer', $this->manager, CarbonImmutable::parse('2026-09-13'));
        expect(($this->lines)('PREMIUM_RECEIPT_REVERSED'))->toBe([['premium_receivable', 'debit', 3_000_000], ['bank_main', 'credit', 3_000_000]])
            ->and(app(ChequeRegisterQuery::class)->register($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))['rows'][0]['state'])->toBe('bounced');
    });
});

it('clears and bounces from the screens, with the journal shown first and today as the date', function (): void {
    $manager = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->manager));
    $receiptId = asTenant($this->ctx['tenant_id'], fn (): string => ($this->cheque)('900006', 2_500_000, [new AllocationLine($this->installments[0], 2_500_000)])->id);
    $preview = [...$this->headers, 'X-Journal-Preview' => '1', 'Accept' => 'application/json'];

    actingAs($manager)->get("/receipts/{$receiptId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Show')
        ->where('receipt.in_clearing', true)->where('receipt.cleared_on', null)->where('actions.clear', true)->where('actions.bounce', true)->where('businessToday', '2026-09-16'));
    actingAs($manager)->get('/cheques?from=2026-09-01&to=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Cheques')
        ->where('register.rows.0.state', 'in_clearing')->where('register.totals.in_clearing', '25,000.00')->where('today', '2026-09-16'));

    actingAs($manager)->postJson("/receipts/{$receiptId}/bounce", ['bounced_on' => '2026-09-16', 'reason' => 'Insufficient funds', 'bank_charge' => '575.00'], $preview)->assertOk()
        ->assertJsonPath('journals.0.event', 'PREMIUM_RECEIPT_REVERSED')->assertJsonPath('journals.0.lines.1.role', 'cheques_in_clearing')
        ->assertJsonPath('journals.1.event', 'CHEQUE_RETURN_CHARGED')->assertJsonPath('journals.1.lines.0', ['account' => '5600', 'name' => 'Bank Charges', 'debit' => '575.00', 'credit' => null, 'role' => 'bank_charges']);
    actingAs($manager)->postJson("/receipts/{$receiptId}/clear", ['cleared_on' => '2026-09-16'], $preview)->assertOk()
        ->assertJsonPath('journals.0.event', 'CHEQUE_CLEARED')->assertJsonPath('journals.0.totals', ['debit' => '25,000.00', 'credit' => '25,000.00']);
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('receipts')->where('id', $receiptId)->value('cleared_on')))->toBeNull();

    actingAs($manager)->post("/receipts/{$receiptId}/clear", ['cleared_on' => '2026-09-16'], $this->headers)->assertSessionHasNoErrors();
    actingAs($manager)->get("/receipts/{$receiptId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.cleared_on', '2026-09-16')->where('actions.clear', false));
});
