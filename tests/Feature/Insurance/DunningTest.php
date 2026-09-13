<?php

declare(strict_types=1);

use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\Dunning\DunningRun;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Policy\Infrastructure\Jobs\DunningJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Spec §4 "Installments, dunning, grace, auto-lapse" (ASSUMPTION A-10, erp.collections.*): reminders at configured days overdue, and a policy whose
 * installment stays unpaid past the grace period lapses automatically (reinstatement gives a fresh grace period).
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    config(['erp.collections.dunning_notice_days' => [7, 21], 'erp.collections.grace_days' => 30, 'erp.collections.auto_lapse' => true]);
    [$this->policyId, $this->installments] = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 3), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        app(PolicyLifecycle::class)->activateDue(CarbonImmutable::parse('2026-09-01'));

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    });
    $this->run = fn (string $date) => app(DunningRun::class)->run($this->ctx['entity_id'], CarbonImmutable::parse($date));
    $this->status = fn (): string => (string) DB::table('policies')->where('id', $this->policyId)->value('status');
});

it('issues each reminder level once per unpaid installment and queues it for delivery', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->run)('2026-09-07');                   // installment 1 due 2026-09-01: 6 days overdue
        expect(DB::table('dunning_notices')->count())->toBe(0);

        ($this->run)('2026-09-08');
        ($this->run)('2026-09-08');
        ($this->run)('2026-09-22');

        expect(DB::table('dunning_notices')->orderBy('level')->get(['installment_id', 'level', 'days_overdue', 'outstanding_minor', 'issued_on'])
            ->map(fn (object $n): array => [(string) $n->installment_id, (int) $n->level, (int) $n->days_overdue, (int) $n->outstanding_minor, (string) $n->issued_on])->all())
            ->toBe([[$this->installments[0], 1, 7, 4_000_000, '2026-09-08'], [$this->installments[0], 2, 21, 4_000_000, '2026-09-22']])
            ->and(DB::table('outbox')->where('message_type', 'DunningNoticeDue')->count())->toBe(2);
    });
});

it('sends no reminder for a paid installment', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 4_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-03'), null, 'r', [new AllocationLine($this->installments[0], 4_000_000)]), $this->world['admin']);

        ($this->run)('2026-09-30');
        expect(DB::table('dunning_notices')->count())->toBe(0)->and(($this->status)())->toBe('active');
    });
});

it('lapses a policy only once an installment is unpaid beyond the grace period, as the system, and only when auto-lapse is on', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->run)('2026-10-01');                   // 30 days overdue: still within grace
        expect(($this->status)())->toBe('active');

        config(['erp.collections.auto_lapse' => false]);
        ($this->run)('2026-10-02');
        expect(($this->status)())->toBe('active');

        config(['erp.collections.auto_lapse' => true]);
        $result = ($this->run)('2026-10-02');          // 31 days overdue
        $audit = DB::table('audit_events')->where('object_id', $this->policyId)->where('action', 'policy.lapsed')->first();

        expect(($this->status)())->toBe('lapsed')
            ->and($result->policiesLapsed)->toBe(1)
            ->and($audit?->actor_user_id)->toBeNull()
            ->and((string) $audit?->reason)->toContain('grace');
    });
});

it('gives a reinstated policy a fresh grace period', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->run)('2026-10-02');
        app(PolicyLifecycle::class)->reinstate($this->policyId, 'Customer promised payment', $this->world['admin']);
        DB::table('policies')->where('id', $this->policyId)->update(['reinstated_on' => '2026-10-05']);

        ($this->run)('2026-10-20');
        expect(($this->status)())->toBe('active');

        ($this->run)('2026-11-05');                   // 31 days after reinstatement, still unpaid
        expect(($this->status)())->toBe('lapsed');
    });
});

it('runs nightly for every tenant', function (): void {
    CarbonImmutable::setTestNow('2026-10-02 01:30:00');
    app()->call([new DunningJob(), 'handle']);
    CarbonImmutable::setTestNow();

    asTenant($this->ctx['tenant_id'], function (): void {
        expect(DB::table('dunning_notices')->count())->toBe(2) // installment 1: levels 1 and 2; installment 2 (due 2026-10-01) is 1 day overdue
            ->and(($this->status)())->toBe('lapsed');
    });
});

it('lists dunning notices over the API', function (): void {
    asTenant($this->ctx['tenant_id'], fn () => ($this->run)('2026-09-22'));
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $reader = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['receipt.allocate'])));
    $other = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['claim.register'])));

    Pest\Laravel\actingAs($other)->getJson("/api/insurance/dunning-notices?entity_id={$this->ctx['entity_id']}&from=2026-09-01&to=2026-09-30", $headers)->assertForbidden();
    Pest\Laravel\actingAs($reader)->getJson("/api/insurance/dunning-notices?entity_id={$this->ctx['entity_id']}&from=2026-09-01&to=2026-09-30", $headers)
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.level', 1);
});
