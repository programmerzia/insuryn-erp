<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimRecoveryReceipts;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
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
 * Gap fix GA-21 (DECISION D-88): recovery money is receipted like any inflow — by the collections duties (receipt.create or receipt.allocate on the claim's
 * branch), never by the claims staff who settled the claim — with a recovery receipt number, the payer and the bank account it went into. The accounting
 * stays design §4.8 (CLAIM_RECOVERED on the claim), into that bank account's GL account.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-20 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->user = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    [$this->claimId, $this->bankAccountId, $this->cityGl, $this->buyerId] = asTenant($this->ctx['tenant_id'], function (): array {
        $admin = $this->world['admin'];
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'], null,
            CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $admin);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $admin);
        $claim = app(ClaimService::class)->register($policy->id, CarbonImmutable::parse('2026-09-05'), 'Collision', $admin, CarbonImmutable::parse('2026-09-06'));
        app(ClaimService::class)->reserve($claim->id, 5_000_000, 'Initial', userWithPermissions($this->ctx['tenant_id'], ['claim.reserve']), CarbonImmutable::parse('2026-09-06'));
        $payment = app(ClaimPaymentService::class)->approve($claim->id, 4_000_000, $this->world['policyholder_id'], userWithPermissions($this->ctx['tenant_id'], ['claim.approve']), CarbonImmutable::parse('2026-09-07'));
        app(ClaimPaymentService::class)->requestRelease($payment->id, userWithPermissions($this->ctx['tenant_id'], ['claim.pay_request']), null);
        app(ClaimPaymentService::class)->release($payment->id, userWithPermissions($this->ctx['tenant_id'], ['claim.pay_release']), CarbonImmutable::parse('2026-09-08'));

        DB::table('accounts')->insert(['id' => $gl = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => '1011',
            'name' => 'Bank - City Bank', 'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true, 'is_control' => false, 'status' => 'active']);
        DB::table('bank_accounts')->insert(['id' => $bank = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'gl_account_id' => $gl, 'bank_name' => 'City Bank', 'account_no_masked' => '****4471', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $buyer = app(PartyService::class)->create(PartyKind::Organization, 'Dhaka Salvage Traders', null, [App\Modules\Insurance\Party\Domain\Enums\PartyRoleType::Vendor], $admin);

        return [$claim->id, $bank, $gl, $buyer->id];
    });
});

it('receipts a recovery with its number, payer and bank account, posting into that bank', function (): void {
    $cashier = ($this->user)(['receipt.allocate']);
    asTenant($this->ctx['tenant_id'], function () use ($cashier): void {
        $recovery = app(ClaimRecoveryReceipts::class)->receive($this->claimId, 'salvage', 600_000, $this->bankAccountId, $this->buyerId, 'Sold wreck', $cashier->id, CarbonImmutable::parse('2026-09-18'));

        expect($recovery->number)->toBe('RCV-HO-2026-000001')
            ->and(DB::table('claim_recoveries')->where('id', $recovery->id)->first(['payer_party_id', 'bank_account_id', 'recorded_by', 'received_on']))
                ->toEqual((object) ['payer_party_id' => $this->buyerId, 'bank_account_id' => $this->bankAccountId, 'recorded_by' => $cashier->id, 'received_on' => '2026-09-18'])
            ->and(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.description', 'CLAIM_RECOVERED')->orderBy('l.line_no')
                ->get(['l.account_id', 'l.side', 'l.amount_minor'])->map(fn (object $l): array => (array) $l)->all())
                ->toBe([['account_id' => $this->cityGl, 'side' => 'debit', 'amount_minor' => 600_000], ['account_id' => $this->ctx['accounts']['claims_recovery_income'], 'side' => 'credit', 'amount_minor' => 600_000]])
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_RECOVERED')->value('payload'))->toContain('"receipt_number": "RCV-HO-2026-000001"')
            ->and(DB::table('audit_events')->where('action', 'claim.recovery_receipted')->count())->toBe(1);
    });
});

it('refuses the claims staff, a missing bank account or payer, and an unpaid claim', function (): void {
    $service = app(ClaimRecoveryReceipts::class);
    asTenant($this->ctx['tenant_id'], function () use ($service): void {
        $claimsManager = userWithPermissions($this->ctx['tenant_id'], ['claim.approve', 'claim.pay_request', 'claim.close']);
        $cashier = userWithPermissions($this->ctx['tenant_id'], ['receipt.create']);
        expect(fn () => $service->receive($this->claimId, 'salvage', 1_000, $this->bankAccountId, $this->buyerId, null, $claimsManager, CarbonImmutable::parse('2026-09-18')))->toThrow(PermissionDenied::class)
            ->and(thrownBy(fn () => $service->receive($this->claimId, 'salvage', 1_000, (string) Str::uuid7(), $this->buyerId, null, $cashier, CarbonImmutable::parse('2026-09-18')), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_BANK_ACCOUNT')
            ->and(thrownBy(fn () => $service->receive($this->claimId, 'salvage', 1_000, $this->bankAccountId, (string) Str::uuid7(), null, $cashier, CarbonImmutable::parse('2026-09-18')), BusinessRuleViolation::class)->reasonCode)->toBe('UNKNOWN_PAYER')
            ->and(thrownBy(fn () => $service->receive($this->claimId, 'salvage', 0, $this->bankAccountId, $this->buyerId, null, $cashier, CarbonImmutable::parse('2026-09-18')), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_AMOUNT')
            ->and(thrownBy(fn () => $service->receive($this->claimId, 'bribe', 1_000, $this->bankAccountId, $this->buyerId, null, $cashier, CarbonImmutable::parse('2026-09-18')), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_RECOVERY_TYPE');

        $unpaid = app(ClaimService::class)->register((string) DB::table('claims')->value('policy_id'), CarbonImmutable::parse('2026-09-10'), 'Theft', $this->world['admin'], CarbonImmutable::parse('2026-09-11'));
        expect(thrownBy(fn () => $service->receive($unpaid->id, 'salvage', 1_000, $this->bankAccountId, $this->buyerId, null, $cashier, CarbonImmutable::parse('2026-09-18')), BusinessRuleViolation::class)->reasonCode)->toBe('CLAIM_NOT_PAID')
            ->and(DB::table('claim_recoveries')->count())->toBe(0);
    });
});

it('offers the recovery on the claim page to the collections side, with the bank accounts and a payer lookup', function (): void {
    $finance = ($this->user)(['receipt.allocate', 'reports.financial']);
    $claimsManager = ($this->user)(['claim.approve', 'claim.pay_request', 'claim.close']);

    actingAs($claimsManager)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.recover', false));
    actingAs($finance)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('claims/Show')
        ->where('actions.recover', true)->where('recoveryBankAccounts', [['id' => $this->bankAccountId, 'label' => 'City Bank ****4471']]));
    actingAs($finance)->getJson('/lookup/payer?q=salvage', $this->headers)->assertOk()->assertJsonPath('results.0.id', $this->buyerId);
    actingAs($claimsManager)->getJson('/lookup/payer?q=salvage', $this->headers)->assertForbidden();

    $form = ['type' => 'salvage', 'amount' => '6,000.00', 'received_on' => '2026-09-18', 'reference' => 'Sold wreck'];
    actingAs($finance)->post("/claims/{$this->claimId}/recover", $form, $this->headers)->assertSessionHasErrors(['bank_account_id', 'payer_party_id']);
    actingAs($finance)->postJson("/claims/{$this->claimId}/recover", [...$form, 'bank_account_id' => $this->bankAccountId, 'payer_party_id' => $this->buyerId], [...$this->headers, 'X-Journal-Preview' => '1'])
        ->assertOk()->assertJsonPath('journals.0.event', 'CLAIM_RECOVERED')->assertJsonPath('journals.0.lines.0.account', '1011');
    actingAs($finance)->post("/claims/{$this->claimId}/recover", [...$form, 'bank_account_id' => $this->bankAccountId, 'payer_party_id' => $this->buyerId], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Recovery receipt RCV-HO-2026-000001 recorded.');
    actingAs($finance)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('recoveries.0.number', 'RCV-HO-2026-000001')->where('recoveries.0.payer', 'Dhaka Salvage Traders')->where('recoveries.0.amount', '6,000.00'));

    // The claims API follows the same rule.
    $headers = [...$this->headers, 'Accept' => 'application/json'];
    actingAs($claimsManager)->postJson("/api/insurance/claims/{$this->claimId}/recover", ['type' => 'salvage', 'amount_minor' => 1_000, 'received_on' => '2026-09-19',
        'bank_account_id' => $this->bankAccountId, 'payer_party_id' => $this->buyerId], $headers)->assertForbidden();
    actingAs($finance)->postJson("/api/insurance/claims/{$this->claimId}/recover", ['type' => 'salvage', 'amount_minor' => 1_000, 'received_on' => '2026-09-19',
        'bank_account_id' => $this->bankAccountId, 'payer_party_id' => $this->buyerId], $headers)->assertCreated()->assertJsonPath('data.number', 'RCV-HO-2026-000002');
});
