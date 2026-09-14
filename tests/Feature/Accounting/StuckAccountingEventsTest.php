<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\Events\StuckAccountingEvents;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\RoleTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fixes GA-08 and GA-13. GA-08: failed accounting events were listed with raw codes and could never be reposted (no screen, no role held
 * accounting.requeue_event), and events stuck in the queue were not listed at all. Accounting events lists both; the finance manager and CFO requeue.
 * GA-13: the accountant, who ties bank and suspense to the ledger, could not open the trial balance, close or reports, and their Home showed an
 * approval block they could never use; they now read reports and follow the journals they submitted.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->asRole = fn (string $role): User => ($this->in)(function () use ($role): User {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'email' => "{$role}-{$id}@example.test", 'name' => $role, 'status' => 'active']);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $role)->value('id'),
            'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);

        return User::query()->findOrFail($id);
    });
    // A policy issued while VAT payable has no account mapped: its POLICY_ISSUED event fails with UNMAPPED_ROLE.
    $this->failedIssue = fn (): array => ($this->in)(function (): array {
        $mapping = (array) DB::table('account_role_mappings')->where('role_code', 'premium_tax_payable')->first();
        DB::table('account_role_mappings')->where('role_code', 'premium_tax_payable')->delete();
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-15'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-15'), $this->world['admin']);
        $event = DB::table('accounting_events')->where('event_type', 'POLICY_ISSUED')->firstOrFail(['id', 'status', 'failure_reason']);

        return ['policy' => $policy->id, 'event' => (string) $event->id, 'status' => (string) $event->status, 'reason' => (string) $event->failure_reason, 'mapping' => $mapping];
    });
});

it('gives accounting.requeue_event to the finance manager and CFO and reports.financial to the accountant, to existing tenants by migration', function (): void {
    $templates = RoleTemplates::all();
    $holding = fn (string $permission): array => array_keys(array_filter($templates, fn (array $t): bool => in_array($permission, $t['permissions'], true)));
    expect($holding('accounting.requeue_event'))->toBe(['finance_manager', 'cfo'])
        ->and($holding('reports.financial'))->toBe(['accountant', 'finance_manager', 'cfo', RoleTemplates::AUDITOR])
        ->and($holding('platform.manage_roles'))->toBe(['tenant_admin']); // §7.3 platform.manage_roles ✕ accounting.*: no requeue holder manages roles

    $holders = fn (string $permission): array => ($this->in)(fn (): array => DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->where('rp.permission_code', $permission)->where('r.code', 'not like', 'test-%')->orderBy('r.code')->pluck('r.code')->all());
    ($this->in)(fn () => DB::table('role_permissions')->where(fn ($q) => $q->where('permission_code', 'accounting.requeue_event')
        ->orWhere(fn ($r) => $r->where('permission_code', 'reports.financial')->whereIn('role_id', DB::table('roles')->where('code', 'accountant')->select('id'))))->delete());
    expect($holders('accounting.requeue_event'))->toBe([])->and($holders('reports.financial'))->toBe(['auditor', 'cfo', 'finance_manager']);

    $migration = require database_path('migrations/2026_09_30_000073_grant_accountant_reports_and_event_requeue.php');
    $migration->up();
    $migration->up();
    expect($holders('accounting.requeue_event'))->toBe(['cfo', 'finance_manager'])->and($holders('reports.financial'))->toBe(['accountant', 'auditor', 'cfo', 'finance_manager']);
});

it('lists failed and long-queued events, and the finance manager requeues a failed one once its cause is fixed', function (): void {
    $failed = ($this->failedIssue)();
    expect($failed['status'])->toBe('failed')->and($failed['reason'])->toStartWith('UNMAPPED_ROLE');
    $number = ($this->in)(fn (): string => (string) DB::table('policies')->where('id', $failed['policy'])->value('number'));
    // An event the worker never picked up, queued 20 minutes ago (the default cut-off is 15), and one queued a minute ago.
    [$stale, $fresh] = ($this->in)(function (): array {
        $ids = [];
        foreach ([20, 1] as $minutes) {
            $ids[] = $id = (string) Str::uuid7();
            DB::table('accounting_events')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'event_type' => 'PREMIUM_EARNED',
                'source_type' => 'premium_earning_ledger', 'source_id' => (string) Str::uuid7(), 'source_version' => 1, 'idempotency_key' => "stale-{$minutes}", 'occurred_at' => now(),
                'transaction_date' => '2026-09-15', 'effective_date' => '2026-09-15', 'currency' => 'BDT', 'payload' => '{}', 'dimensions' => '{}', 'status' => 'queued',
                'created_at' => now()->subMinutes($minutes)]);
        }

        return $ids;
    });

    $finance = ($this->asRole)('finance_manager');
    actingAs($finance)->get('/accounting/events', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/events/Index')
        ->where('can.requeue', true)->where('staleAfterMinutes', 15)->has('events', 2)
        // Oldest first: the event queued 20 minutes ago, then the failure.
        ->where('events.0.id', $stale)->where('events.0.status', 'queued')->where('events.0.failure_reason', null)
        ->where('events.1.id', $failed['event'])->where('events.1.event_type', 'POLICY_ISSUED')->where('events.1.status', 'failed')
        ->where('events.1.source', ['url' => "/policies/{$failed['policy']}", 'label' => $number]));
    actingAs($finance)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.5.key', 'failed_events')->where('queues.5.href', '/accounting/events')->where('queues.5.count', 2)
        ->where('queues.5.columns.0.type', 'event')->where('queues.5.rows.1.href', '/accounting/events')->where('queues.5.rows.1.cells.reason', 'Not posted after 15 minutes')->where('queues.5.rows.0.cells.event', 'POLICY_ISSUED'));

    // The accountant sees the list but cannot requeue; the fresh queued event cannot be requeued by anyone yet.
    $accountant = ($this->asRole)('accountant');
    actingAs($accountant)->get('/accounting/events', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('can.requeue', false));
    actingAs($accountant)->post("/accounting/events/{$failed['event']}/requeue", [], $this->headers)->assertSessionHasErrors('form');
    actingAs($finance)->post("/accounting/events/{$fresh}/requeue", [], $this->headers)->assertSessionHasErrors('form');

    // Requeued while the cause is still there, it fails again with the reason; once the role is mapped it posts.
    actingAs($finance)->post("/accounting/events/{$failed['event']}/requeue", [], $this->headers)->assertSessionHasNoErrors()->assertRedirect('/accounting/events');
    expect(($this->in)(fn (): string => (string) DB::table('accounting_events')->where('id', $failed['event'])->value('status')))->toBe('failed');
    ($this->in)(fn () => DB::table('account_role_mappings')->insert($failed['mapping']));
    actingAs($finance)->post("/accounting/events/{$failed['event']}/requeue", [], $this->headers)->assertSessionHasNoErrors();
    $event = ($this->in)(fn (): object => DB::table('accounting_events')->where('id', $failed['event'])->firstOrFail(['status', 'failure_reason', 'journal_batch_id']));
    expect($event->status)->toBe('posted')->and($event->failure_reason)->toBeNull()->and($event->journal_batch_id)->not->toBeNull();
    expect(($this->in)(fn (): int => DB::table('audit_events')->where('action', 'accounting_event.requeued')->where('object_id', $failed['event'])->count()))->toBe(2);
    // Posted: it cannot be requeued again, and only the stale event is left.
    actingAs($finance)->post("/accounting/events/{$failed['event']}/requeue", [], $this->headers)->assertSessionHasErrors('form');
    actingAs($finance)->get('/accounting/events', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('events', 1)->where('events.0.id', $stale));
    expect(StuckAccountingEvents::staleAfterMinutes())->toBe(15);
});

it('opens the trial balance, close and reports to the accountant, and shows the journals they submitted instead of an approval block', function (): void {
    $accountant = ($this->asRole)('accountant');
    actingAs($accountant)->get('/reports', $this->headers)->assertOk();
    actingAs($accountant)->get('/close', $this->headers)->assertOk();
    actingAs($accountant)->get('/accounting/trial-balance?as_of=2026-09-15', $this->headers)->assertOk();

    ($this->in)(function () use ($accountant): void {
        $journals = app(ManualJournalService::class);
        $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-10'), 'Bank charges', JournalKind::Manual, 'Bank charges', 'BDT', [
            new ManualJournalLine($this->ctx['accounts']['salary_expense'], Side::Debit, 35_000, ['branch' => $this->ctx['branch_id']]),
            new ManualJournalLine($this->ctx['accounts']['bank_main'], Side::Credit, 35_000, ['branch' => $this->ctx['branch_id']]),
        ]), $accountant->id);
        $journals->submit($journal->id, $accountant->id);
    });
    actingAs($accountant)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.2.key', 'journals_submitted')->where('queues.2.title', 'Journals I submitted')->where('queues.2.count', 1)
        ->where('queues.2.rows.0.cells.description', 'Bank charges')->where('queues.2.rows.0.cells.status', 'pending_approval')->where('queues.2.rows.0.cells.amount', '350.00'));
});
