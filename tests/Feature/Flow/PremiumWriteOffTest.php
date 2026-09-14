<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Insurance\Collections\Application\PremiumWriteOffService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Authorization\RoleTemplates;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Money\MinorUnits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fixes W7 (GA-24 remainder): a cancelled policy whose customer still owes a small part of the premium earned before the cancellation stayed in receivable
 * ageing with no route but collection. "Write off small balance" asks for the write-off (branch manager, receipt.write_off_request), the approval engine sends
 * it to finance (receipt.write_off_approve, never the requester), and the final approval credits the installments and posts PREMIUM_WRITTEN_OFF: debit
 * Premium Written Off, credit Premium Receivable. Above the configured limit the balance is collected instead. Cancelled policies' unpaid installments are
 * also offered for collection in the installment lookup and the allocation workbench.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->role = fn (string $role): User => ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], RoleTemplates::all()[$role]['permissions'])));
    $this->cancelledOwing = fn (): string => ($this->in)(function (): string {
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $this->world['admin']);
        $lifecycle->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        $lifecycle->cancel($policy->id, CarbonImmutable::parse('2026-10-15'), 'Customer request', $this->world['admin']);

        return $policy->id;
    });
    $this->owed = fn (string $policyId): int => ($this->in)(fn (): int => (int) DB::table('installments')->where('policy_id', $policyId)->sum(DB::raw('amount_minor - paid_minor - cancelled_minor')));
    $this->postQueued = fn () => ($this->in)(function (): void {
        foreach (DB::table('accounting_events')->where('status', 'queued')->pluck('id') as $id) {
            app(PostingEngine::class)->post((string) $id);
        }
    });
});

it('gives the request to the branch manager and the approval to finance, never both to one template, and to existing tenants by migration', function (): void {
    $templates = RoleTemplates::all();
    $holders = fn (string $permission): array => array_keys(array_filter($templates, fn (array $t): bool => in_array($permission, $t['permissions'], true)));
    expect($holders('receipt.write_off_request'))->toBe(['branch_manager'])
        ->and($holders('receipt.write_off_approve'))->toBe(['finance_manager', 'cfo']);

    seedRoleTemplates($this->ctx['tenant_id']);
    $granted = fn (): array => ($this->in)(fn (): array => DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->whereIn('rp.permission_code', ['receipt.write_off_request', 'receipt.write_off_approve'])->where('r.code', 'not like', 'test-%')
        ->orderBy('r.code')->orderBy('rp.permission_code')->get(['r.code', 'rp.permission_code'])->map(fn (object $row): string => "{$row->code}:{$row->permission_code}")->all());
    ($this->in)(fn () => DB::table('role_permissions')->whereIn('permission_code', ['receipt.write_off_request', 'receipt.write_off_approve'])
        ->whereIn('role_id', DB::table('roles')->where('code', 'not like', 'test-%')->select('id'))->delete());
    expect($granted())->toBe([]);

    $migration = require database_path('migrations/2026_10_05_000702_grant_premium_write_off_permissions.php');
    $migration->up();
    $migration->up();
    expect($granted())->toBe(['branch_manager:receipt.write_off_request', 'cfo:receipt.write_off_approve', 'finance_manager:receipt.write_off_approve']);
});

it('maps the new account role in every existing book that maps premium receivable, once', function (): void {
    $mapping = fn (): array => ($this->in)(fn (): array => DB::table('account_role_mappings as m')->join('accounts as a', 'a.id', '=', 'm.account_id')
        ->where('m.role_code', 'premium_written_off')->get(['a.code', 'a.name', 'a.type'])->map(fn (object $a): string => "{$a->code} {$a->name} {$a->type}")->all());
    expect($mapping())->toBe(['5450 Premium Written Off expense']);
    ($this->in)(function (): void {
        DB::table('account_role_mappings')->where('role_code', 'premium_written_off')->delete();
        DB::table('accounts')->where('code', '5450')->update(['name' => 'Office Repairs']);
    });

    $migration = require database_path('migrations/2026_10_05_000703_map_premium_written_off_account.php');
    $migration->up();
    $migration->up();
    // 5450 is taken by another account now, so the next free code is used; the role is mapped once.
    expect($mapping())->toBe(['5451 Premium Written Off expense']);
});

it('writes off a cancelled policy\'s small unpaid premium only after someone in finance approves it, and posts the journal then', function (): void {
    $policyId = ($this->cancelledOwing)();
    $owed = ($this->owed)($policyId);
    expect($owed)->toBeGreaterThan(0);
    config(['erp.premium_write_off.max_minor' => $owed]);
    $manager = ($this->role)('branch_manager');
    $finance = ($this->role)('finance_manager');
    $amount = MinorUnits::format($owed, 'BDT');

    actingAs($manager)->get("/policies/{$policyId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('actions.write_off', true)->where('writeOff', ['owed' => $amount, 'limit' => $amount, 'within_limit' => true, 'pending' => null]));
    actingAs($finance)->get("/policies/{$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.write_off', false));

    actingAs($manager)->post("/policies/{$policyId}/write-off", ['reason' => ''], $this->headers)->assertSessionHasErrors('reason');
    actingAs($manager)->post("/policies/{$policyId}/write-off", ['reason' => 'Customer abroad, not worth chasing'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', "Write-off of {$amount} sent for approval. Nothing is posted until finance approves it.");
    ($this->postQueued)();
    expect(($this->owed)($policyId))->toBe($owed)
        ->and(($this->in)(fn (): int => DB::table('accounting_events')->where('event_type', 'PREMIUM_WRITTEN_OFF')->count()))->toBe(0);
    actingAs($manager)->get("/policies/{$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('writeOff.pending.requested', $amount));
    actingAs($manager)->post("/policies/{$policyId}/write-off", ['reason' => 'again'], $this->headers)->assertSessionHasErrors(['form']);

    $approvalId = ($this->in)(fn (): string => (string) DB::table('approvals')->where('object_type', 'premium_write_off')->where('status', 'pending')->value('id'));
    // The requester is not offered it and cannot decide it.
    actingAs($manager)->get('/approvals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('approvals', []));
    expect(fn () => ($this->in)(fn () => app(ApprovalService::class)->decide($approvalId, (string) $manager->id, Decision::Approved, null)))->toThrow(App\Modules\Platform\Authorization\PermissionDenied::class);

    // Finance sees what approving posts before deciding.
    actingAs($finance)->get('/approvals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('approvals.0.object_type', 'premium_write_off')->where('approvals.0.amount', $amount)->where('approvals.0.final_step', true)
        ->where('approvals.0.preview.posts_on_final_step', true)
        ->where('approvals.0.preview.lines', [['account' => '5450', 'name' => 'Premium Written Off', 'debit' => $amount, 'credit' => null], ['account' => '1100', 'name' => 'Premium Receivable', 'debit' => null, 'credit' => $amount]]));

    actingAs($finance)->post("/approvals/{$approvalId}/decide", ['decision' => 'approved'], $this->headers)->assertSessionHasNoErrors();
    ($this->postQueued)();
    expect(($this->owed)($policyId))->toBe(0);
    $journal = ($this->in)(fn (): array => DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
        ->where('j.source_type', 'premium_write_off')->where('j.status', 'posted')->orderBy('l.line_no')->get(['a.code', 'l.side', 'l.amount_minor', 'j.posting_date'])
        ->map(fn (object $l): array => [$l->code, $l->side, (int) $l->amount_minor, (string) $l->posting_date])->all());
    expect($journal)->toBe([['5450', 'debit', $owed, '2026-10-15'], ['1100', 'credit', $owed, '2026-10-15']]);
    expect(($this->in)(fn (): array => DB::table('audit_events')->where('object_type', 'policy')->where('object_id', $policyId)->where('action', 'like', 'premium_write_off.%')->orderBy('occurred_at')->orderBy('id')->pluck('action')->all()))
        ->toBe(['premium_write_off.requested', 'premium_write_off.approved']);
    actingAs($manager)->get("/policies/{$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('writeOff', null));
});

it('refuses a write-off of a policy that is not cancelled, owes nothing or owes more than a small balance, and records a rejection', function (): void {
    $manager = ($this->role)('branch_manager');
    $service = fn (): PremiumWriteOffService => app(PremiumWriteOffService::class);
    $reason = fn (callable $call): string => thrownBy(fn () => ($this->in)($call), BusinessRuleViolation::class)->reasonCode;

    $active = ($this->in)(function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 1_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return $policy->id;
    });
    expect($reason(fn () => $service()->request($active, 'small', (string) $manager->id)))->toBe('WRITE_OFF_POLICY_NOT_CANCELLED');

    $policyId = ($this->cancelledOwing)();
    config(['erp.premium_write_off.max_minor' => ($this->owed)($policyId) - 1]);
    expect($reason(fn () => $service()->request($policyId, 'small', (string) $manager->id)))->toBe('WRITE_OFF_ABOVE_LIMIT');
    actingAs($manager)->get("/policies/{$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('writeOff.within_limit', false));

    config(['erp.premium_write_off.max_minor' => ($this->owed)($policyId)]);
    $officer = ($this->role)('branch_officer');
    expect(fn () => ($this->in)(fn () => $service()->request($policyId, 'small', (string) $officer->id)))->toThrow(App\Modules\Platform\Authorization\PermissionDenied::class);

    $writeOff = ($this->in)(fn () => $service()->request($policyId, 'Customer unreachable', (string) $manager->id));
    $finance = ($this->role)('finance_manager');
    ($this->in)(fn () => app(ApprovalService::class)->decide((string) $writeOff->approval_id, (string) $finance->id, Decision::Rejected, 'Call the customer first'));
    expect(($this->in)(fn (): array => (array) DB::table('premium_write_offs')->where('id', $writeOff->id)->first(['status', 'rejection_reason'])))->toBe(['status' => 'rejected', 'rejection_reason' => 'Call the customer first'])
        ->and(($this->owed)($policyId))->toBeGreaterThan(0)
        ->and(($this->in)(fn (): int => DB::table('accounting_events')->where('event_type', 'PREMIUM_WRITTEN_OFF')->count()))->toBe(0);
});

it('offers a cancelled policy\'s unpaid installments for collection in the installment lookup and the allocation workbench', function (): void {
    $policyId = ($this->cancelledOwing)();
    $number = ($this->in)(fn (): string => (string) DB::table('policies')->where('id', $policyId)->value('number'));
    $manager = ($this->role)('branch_manager');

    actingAs($manager)->getJson('/lookup/installment?q='.urlencode($number), $this->headers)->assertOk()
        ->assertJson(fn ($json) => $json->where('results', fn (Illuminate\Support\Collection $results): bool => $results->contains(fn (array $r): bool => str_starts_with($r['label'], $number)))->etc());

    $receiptId = ($this->in)(fn (): string => app(App\Modules\Insurance\Collections\Application\ReceiptService::class)->record(new App\Modules\Insurance\Collections\Application\RecordReceiptRequest(
        $this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 10_000, 'BDT', CarbonImmutable::parse('2026-10-15'), null, 'TT 9', []), $this->world['admin'])->id);
    actingAs($manager)->get("/receipts/{$receiptId}/allocate", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('candidates', fn (Illuminate\Support\Collection $candidates): bool => $candidates->contains(fn (array $c): bool => $c['policy_number'] === $number)));
});
