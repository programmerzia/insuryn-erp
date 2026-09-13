<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Policy\Application\PayerShare;
use App\Modules\Insurance\Policy\Application\PayerStatementQuery;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec §4 "Multi-payer": a policy's premium can be shared between payers (e.g. employer 60%, employee 40%). Each installment is billed to every
 * payer by share; credits (premium decrease, cancellation) are shared the same way; payers pay and are chased on their own installments.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->employer = asTenant($this->ctx['tenant_id'], fn (): string => app(PartyService::class)->create(PartyKind::Organization, 'Acme Garments Ltd', null,
        [PartyRoleType::Customer], $this->world['admin'])->id);
    $this->quote = fn (array $payers, int $premium = 10_000_001, int $installments = 2) => app(PolicyLifecycle::class)->quote(new QuoteRequest(
        $this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'),
        $premium, 'BDT', $installments, array_values(array_filter($payers, fn (mixed $p): bool => $p instanceof PayerShare))), $this->world['admin']);
    $this->issue = fn (string $policyId) => app(PolicyLifecycle::class)->issue($policyId, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
    $this->rows = fn (string $policyId): array => DB::table('installments')->where('policy_id', $policyId)->orderBy('no')->orderBy('amount_minor', 'desc')
        ->get(['no', 'payer_party_id', 'amount_minor', 'cancelled_minor'])->map(fn (object $i): array => [(int) $i->no, (string) $i->payer_party_id, (int) $i->amount_minor, (int) $i->cancelled_minor])->all();
});

it('validates payer shares at quote time', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        expect(thrownBy(fn () => ($this->quote)([new PayerShare($this->employer, 6000), new PayerShare($this->world['policyholder_id'], 3000)]), BusinessRuleViolation::class)->reasonCode)->toBe('PAYER_SHARES_INVALID')
            ->and(thrownBy(fn () => ($this->quote)([new PayerShare($this->employer, 5000), new PayerShare($this->employer, 5000)]), BusinessRuleViolation::class)->reasonCode)->toBe('PAYER_SHARES_INVALID')
            ->and(thrownBy(fn () => ($this->quote)([new PayerShare($this->employer, 10000), new PayerShare($this->world['policyholder_id'], 0)]), BusinessRuleViolation::class)->reasonCode)->toBe('PAYER_SHARES_INVALID')
            ->and(thrownBy(fn () => ($this->quote)([new PayerShare((string) Str::uuid7(), 10000)]), BusinessRuleViolation::class)->reasonCode)->toBe('UNKNOWN_PAYER')
            ->and(DB::table('policies')->count())->toBe(0);
    });
});

it('bills every installment to each payer by share, the last payer absorbing rounding', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $policy = ($this->quote)([new PayerShare($this->employer, 6000), new PayerShare($this->world['policyholder_id'], 4000)]);
        ($this->issue)($policy->id);
        $holder = $this->world['policyholder_id'];

        // gross 10,000,001 in 2 installments: 5,000,000 and 5,000,001; employer 60% = 3,000,000 / 3,000,001 (half-even), holder the rest
        expect(($this->rows)($policy->id))->toBe([[1, $this->employer, 3_000_000, 0], [1, $holder, 2_000_000, 0], [2, $this->employer, 3_000_001, 0], [2, $holder, 2_000_000, 0]])
            ->and((int) DB::table('installments')->where('policy_id', $policy->id)->sum('amount_minor'))->toBe(10_000_001)
            ->and(DB::table('policy_payers')->where('policy_id', $policy->id)->orderByDesc('share_bp')->pluck('share_bp')->map(fn ($s): int => (int) $s)->all())->toBe([6000, 4000]);

        $single = ($this->quote)([]);
        ($this->issue)($single->id);
        expect(DB::table('installments')->where('policy_id', $single->id)->pluck('payer_party_id')->unique()->values()->all())->toBe([$holder]);
    });
});

it('splits premium increases and credits by share and keeps receivable reconciled', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $policy = ($this->quote)([new PayerShare($this->employer, 6000), new PayerShare($this->world['policyholder_id'], 4000)], 12_000_000, 1);
        ($this->issue)($policy->id);
        $employerInstallment = (string) DB::table('installments')->where('policy_id', $policy->id)->where('payer_party_id', $this->employer)->value('id');
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->employer, 'bank_transfer', 7_200_000, 'BDT',
            CarbonImmutable::parse('2026-09-05'), null, 'employer share', [new AllocationLine($employerInstallment, 7_200_000)]), $this->world['admin']);

        app(PolicyLifecycle::class)->endorse($policy->id, CarbonImmutable::parse('2026-10-01'), 1_150_000, 'extra cover', $this->world['admin']);
        expect(array_values(array_filter(($this->rows)($policy->id), fn (array $r): bool => $r[0] === 2)))->toBe([[2, $this->employer, 690_000, 0], [2, $this->world['policyholder_id'], 460_000, 0]]);

        app(PolicyLifecycle::class)->cancel($policy->id, CarbonImmutable::parse('2026-11-01'), 'scheme ended', $this->world['admin']);
        $credited = (int) json_decode((string) DB::table('policy_transactions')->where('policy_id', $policy->id)->where('type', 'cancellation')->value('amounts'), true)['receivable_outstanding'];
        $statement = app(PayerStatementQuery::class)->forPolicy($policy->id);

        expect((int) DB::table('installments')->where('policy_id', $policy->id)->sum('cancelled_minor'))->toBe($credited)
            ->and(array_column($statement['payers'], 'party_id'))->toBe([$this->employer, $this->world['policyholder_id']])
            ->and($statement['payers'][0]['paid_minor'])->toBe(7_200_000)
            ->and(array_sum(array_column($statement['payers'], 'outstanding_minor')))->toBe((int) DB::table('installments')->where('policy_id', $policy->id)
                ->selectRaw('sum(amount_minor - paid_minor - cancelled_minor) as o')->value('o'));

        foreach (['2026-09-30', '2026-10-31', '2026-11-30'] as $monthEnd) {
            app(ReconciliationService::class)->runAll((string) DB::table('fiscal_periods')->where('ends', $monthEnd)->value('id'));
        }
        expect(DB::table('reconciliation_runs')->where('status', 'variance')->count())->toBe(0);
    });
});

it('quotes with payers and shows the payer statement over the API', function (): void {
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $admin = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail((string) $this->world['admin']));

    $policyId = Pest\Laravel\actingAs($admin)->postJson('/api/insurance/policies', ['branch_id' => $this->ctx['branch_id'], 'product_id' => $this->world['product_id'],
        'policyholder_party_id' => $this->world['policyholder_id'], 'inception' => '2026-09-01', 'premium_minor' => 1_000_000, 'installment_count' => 1,
        'payers' => [['party_id' => $this->employer, 'share_bp' => 7000], ['party_id' => $this->world['policyholder_id'], 'share_bp' => 3000]]], $headers)->assertCreated()->json('data.id');
    Pest\Laravel\actingAs($admin)->postJson("/api/insurance/policies/{$policyId}/issue", ['on' => '2026-09-01'], $headers)->assertOk();

    Pest\Laravel\actingAs($admin)->getJson("/api/insurance/policies/{$policyId}/payers", $headers)->assertOk()
        ->assertJsonPath('data.payers.0.share_bp', 7000)->assertJsonPath('data.payers.0.billed_minor', 700_000);
});
