<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Posting\TenantDimensionRequirements;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Accounting\Infrastructure\Jobs\OutboxRelayJob;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Messaging\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Gap audit GA-46: announcements with no subscriber (JournalPosted, period transitions) were never relayed, and the close and the nightly job each
 * recorded a clean reconciliation run for the same balances. GA-47: `dimension_requirements` was empty and unenforced.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
});

it('marks announcements with no subscriber relayed and leaves messages that wait for a consumer', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        DB::table('outbox')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'message_type' => 'JournalPosted',
            'payload' => json_encode(['batch_id' => 'b', 'event_id' => 'e']), 'created_at' => now()]);
        app(Outbox::class)->add('PeriodLocked', ['period_id' => 'p']);
        app(Outbox::class)->add('CommissionPayableToAp', ['commission_statement_id' => 's']);
    });

    app()->call([new OutboxRelayJob(), 'handle']);

    asTenant($this->ctx['tenant_id'], function (): void {
        expect(DB::table('outbox')->whereNull('relayed_at')->pluck('message_type')->all())->toBe(['CommissionPayableToAp'])
            ->and(DB::table('outbox')->whereIn('message_type', ['JournalPosted', 'PeriodLocked'])->whereNotNull('relayed_at')->count())->toBe(2);
    });
});

it('reuses the latest clean reconciliation run when the balances have not changed, and records a new one when they have', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'daily_365');
    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $issue = function () use ($world): void {
            $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $world['product_id'], $world['policyholder_id'],
                null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $world['admin']);
            app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $world['admin']);
        };
        $issue();
        $september = (string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id');
        $service = app(ReconciliationService::class);

        $first = $service->runAll($september);
        $again = $service->runAll($september);
        expect($again)->toBe($first)->and(DB::table('reconciliation_runs')->where('period_id', $september)->count())->toBe(count($first));

        $issue(); // the premium subledger moves: a new clean run for it
        $service->runAll($september);
        expect(DB::table('reconciliation_runs')->where('period_id', $september)->where('subledger', 'premium')->count())->toBe(2)
            ->and(DB::table('reconciliation_runs')->where('period_id', $september)->where('status', '<>', 'clean')->count())->toBe(0);
    });
});

it('enforces the tenant dimension requirements on top of the posting rule', function (): void {
    Queue::fake();
    $submit = fn (array $dimensions, string $key): AccountingEvent => DB::transaction(fn () => app(SubmitAccountingEvent::class)(
        $this->ctx['entity_id'], 'PREMIUM_EARNED', 'test', (string) Str::uuid7(), $key, CarbonImmutable::parse('2026-09-30'), CarbonImmutable::parse('2026-09-30'), 'BDT',
        ['earned' => 1_000, 'period_id' => 'p'], $dimensions));
    $dims = ['branch' => $this->ctx['branch_id'], 'product' => (string) Str::uuid7(), 'policy' => (string) Str::uuid7()];

    asTenant($this->ctx['tenant_id'], function () use ($submit, $dims): void {
        // No tenant rows: the rule's own requirements (branch, product, policy) are enough.
        $before = $submit($dims, 'no-rows');
        app(PostingEngine::class)->post($before->id);
        expect(AccountingEvent::query()->findOrFail($before->id)->status->value)->toBe('posted');

        expect(TenantDimensionRequirements::seedCurrentTenant($this->ctx['tenant_id']))->toBe(30)
            ->and(TenantDimensionRequirements::seedCurrentTenant($this->ctx['tenant_id']))->toBe(0);

        $missing = $submit($dims, 'without-class');
        app(PostingEngine::class)->post($missing->id);
        $failed = AccountingEvent::query()->findOrFail($missing->id);
        expect($failed->status->value)->toBe('failed')->and((string) $failed->failure_reason)->toContain('DIMENSION_MISSING')->toContain("'lob'");

        $complete = $submit($dims + ['lob' => 'motor'], 'with-class');
        app(PostingEngine::class)->post($complete->id);
        expect(AccountingEvent::query()->findOrFail($complete->id)->status->value)->toBe('posted');
    });
});
