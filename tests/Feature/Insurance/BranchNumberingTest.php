<?php

declare(strict_types=1);

use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\AgentDepositService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Party\Application\AgentService;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fix G5: receipts, claims and agent deposits are numbered per entity + branch + fiscal year (design §2.1), and their numbers are unique in the
 * tenant. The number therefore carries the branch code, like policies (fix F1): the first receipt, claim and deposit of the year in a second
 * branch must not repeat the first branch's number (which the unique constraints refused).
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->ctg = asTenant($this->ctx['tenant_id'], function (): string {
        DB::table('branches')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'CTG',
            'name' => 'Chattogram', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    $this->issue = fn (string $branchId, string $agentId): string => asTenant($this->ctx['tenant_id'], function () use ($branchId, $agentId): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $branchId, $this->world['product_id'],
            $this->world['policyholder_id'], $agentId, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return $policy->id;
    });
});

it('numbers the first receipt of the year in each branch with its branch code', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $record = fn (string $branchId): string => app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $branchId, null, 'bank_transfer',
            1_000_000, 'BDT', CarbonImmutable::parse('2026-09-15'), null, 'unidentified transfer', []), $this->world['admin'])->number;

        $numbers = [$record($this->ctx['branch_id']), $record($this->ctg), $record($this->ctx['branch_id'])];

        expect($numbers)->toBe(['RCT-HO-2026-000001', 'RCT-CTG-2026-000001', 'RCT-HO-2026-000002'])
            ->and(DB::table('receipts')->count())->toBe(3);
    });
});

it('numbers the first claim of the year in each branch with its branch code', function (): void {
    $hoPolicy = ($this->issue)($this->ctx['branch_id'], $this->world['agent_id']);
    $ctgPolicy = ($this->issue)($this->ctg, $this->world['agent_id']);

    asTenant($this->ctx['tenant_id'], function () use ($hoPolicy, $ctgPolicy): void {
        $register = fn (string $policyId): string => app(ClaimService::class)->register($policyId, CarbonImmutable::parse('2026-09-05'), 'Rear-end collision',
            $this->world['admin'], CarbonImmutable::parse('2026-09-06'))->number;

        $numbers = [$register($hoPolicy), $register($ctgPolicy)];

        expect($numbers)->toBe(['CLM-HO-2026-000001', 'CLM-CTG-2026-000001'])
            ->and(DB::table('claims')->count())->toBe(2);
    });
});

it('numbers the first agent deposit of the year in each branch with its branch code', function (): void {
    $ctgAgent = asTenant($this->ctx['tenant_id'], function (): string {
        $party = app(PartyService::class)->create(PartyKind::Individual, 'Karim Agent', null, [PartyRoleType::Agent], $this->world['admin']);

        return app(AgentService::class)->create($party->id, 'AG-002', $this->ctg, null, null, $this->world['admin'])->id;
    });
    $policies = [$this->world['agent_id'] => ($this->issue)($this->ctx['branch_id'], $this->world['agent_id']), $ctgAgent => ($this->issue)($this->ctx['branch_id'], $this->world['agent_id'])];

    asTenant($this->ctx['tenant_id'], function () use ($policies): void {
        $gl = (string) Str::uuid7();
        DB::table('accounts')->insert(['id' => $gl, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => '1013', 'name' => 'Bank - Collections',
            'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true, 'is_control' => false, 'status' => 'active']);
        $bankAccount = app(BankAccountService::class)->create($this->ctx['entity_id'], $gl, 'Collections Bank', '****7', 'BDT', $this->world['admin']);
        $deposits = [];
        foreach ($policies as $agentId => $policyId) {
            $installment = (string) DB::table('installments')->where('policy_id', $policyId)->orderBy('no')->value('id');
            app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 1_000_000, 'BDT',
                CarbonImmutable::parse('2026-09-10'), null, 'field collection', [new AllocationLine($installment, 1_000_000)], null, $agentId), $this->world['admin']);
            $deposits[] = app(AgentDepositService::class)->record($agentId, 1_000_000, $bankAccount->id, 'DEP', $this->world['admin'], CarbonImmutable::parse('2026-09-13'))->number;
        }

        expect($deposits)->toBe(['ADP-HO-2026-000001', 'ADP-CTG-2026-000001'])
            ->and(DB::table('agent_deposits')->count())->toBe(2);
    });
});
