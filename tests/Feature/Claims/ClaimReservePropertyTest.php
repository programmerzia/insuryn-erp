<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\LedgerQuery;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimsReconciler;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Feature\Claims\ClaimLifecycleModel;
use Tests\Support\Property\SeededGenerator;

/**
 * Slice 2.0d (Phase 1 exit checklist: "generator-based property test for the claim reserve lifecycle"). Random but valid-shaped operation
 * sequences drive the real claim services — register, reserve/adjust, approve within and over the approval limit, approval decisions,
 * request release, release within and over the limit, close, reject, reopen (direct and by approval), recovery, and (follow-up H3) closing or recovering on a
 * paid claim that was reopened — on a fresh claim per run,
 * with actors that satisfy SoD (claim.reserve ✕ claim.approve, claim.pay_request ✕ claim.pay_release). The posting worker runs
 * synchronously (QUEUE_CONNECTION=sync). After EVERY operation the claim is compared with ClaimLifecycleModel and the invariants below.
 *
 * PROPERTY_RUNS (default 100) sets the number of runs, PROPERTY_STEPS (default 20) the most operations per run, PROPERTY_SEED (default
 * 20260914) the first run's seed; run i uses seed + i. A failure names the run's seed, the replay command and the operations so far.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->officer = userWithPermissions($this->ctx['tenant_id'], ['claim.register', 'claim.reserve']);
    $this->manager = userWithPermissions($this->ctx['tenant_id'], ['claim.approve', 'claim.pay_request', 'claim.close']);
    $this->finance = userWithPermissions($this->ctx['tenant_id'], ['claim.pay_release']);
    $this->cfo = userWithPermissions($this->ctx['tenant_id'], ['claim.pay_release', 'periods.lock']);
    approvalPolicy($this->ctx['tenant_id'], 'claim_payment', ['min_amount_minor' => ClaimLifecycleModel::PAYMENT_APPROVAL_FROM], [['permission' => 'claim.pay_release']]);
    approvalPolicy($this->ctx['tenant_id'], 'claim_payment_release', ['min_amount_minor' => ClaimLifecycleModel::RELEASE_APPROVAL_FROM], [['permission' => 'periods.lock']]);
    approvalPolicy($this->ctx['tenant_id'], 'claim_reopen', ['min_amount_minor' => ClaimLifecycleModel::REOPEN_APPROVAL_FROM], [['permission' => 'periods.lock']]);
    $this->policyId = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-07-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-07-01'), $this->world['admin']);

        return $policy->id;
    });
});

/**
 * Tenant-wide row counts plus the claim's own rows: a refused operation must leave all of them as they were.
 *
 * @return array<string, mixed>
 */
function claimPropertySnapshot(string $claimId): array
{
    $counts = [];
    foreach (['claims', 'claim_reserves', 'claim_payments', 'claim_recoveries', 'accounting_events', 'journals', 'journal_lines', 'outbox',
        'approvals', 'approval_decisions', 'audit_events', 'document_numbers'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts + [
        'claim' => (array) DB::table('claims')->where('id', $claimId)->first(),
        'payments' => DB::table('claim_payments')->where('claim_id', $claimId)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'approvals_state' => DB::table('approvals')->orderBy('id')->get(['id', 'status', 'current_step'])->map(fn (object $row): array => (array) $row)->all(),
    ];
}

/**
 * Invariants after every operation, read from the database (subledger rows, GL lines written by the posting engine, approvals).
 *
 * @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx
 */
function assertClaimInvariants(ClaimLifecycleModel $model, array $ctx, CarbonImmutable $on): void
{
    $claimId = $model->claimId;
    $claim = DB::table('claims')->where('id', $claimId)->first();
    $reserves = DB::table('claim_reserves')->where('claim_id', $claimId)->orderBy('version')->get();
    $payments = DB::table('claim_payments')->where('claim_id', $claimId)->get()
        ->mapWithKeys(fn (object $p): array => [(string) $p->id => ['amount' => (int) $p->amount_minor, 'status' => (string) $p->status]])->all();
    ksort($payments);
    $expectedPayments = $model->payments;
    ksort($expectedPayments);

    // The claim agrees with the reference model.
    expect([(string) $claim?->status, (int) $claim?->reserve_minor, (int) $claim?->reserve_version])->toBe([$model->status, $model->reserve, $model->versions])
        ->and($payments)->toBe($expectedPayments)
        ->and((int) DB::table('claim_recoveries')->where('claim_id', $claimId)->sum('amount_minor'))->toBe($model->recovered);

    // Reserve history: contiguous versions, each total non-negative, deltas summing to the current reserve (§4.6 ordered adjustments).
    expect($reserves->pluck('version')->map(fn ($v): int => (int) $v)->all())->toBe($model->versions === 0 ? [] : range(1, $model->versions))
        ->and($reserves->every(fn (object $r): bool => (int) $r->reserve_minor >= 0))->toBeTrue()
        ->and((int) $reserves->sum('delta_minor'))->toBe((int) $claim?->reserve_minor)
        ->and((int) ($reserves->last()->reserve_minor ?? 0))->toBe((int) $claim?->reserve_minor);

    // Payments never exceed the reserve; paid ≤ approved ≤ committed ≤ reserve.
    expect($model->paid())->toBeLessThanOrEqual($model->approved())
        ->and($model->approved())->toBeLessThanOrEqual($model->committed())
        ->and($model->committed())->toBeLessThanOrEqual($model->reserve);

    // GL by claim after the posting worker ran (normal side: credit for liabilities and income, debit for expense and bank).
    $balance = function (string $role, string $normal) use ($ctx, $claimId): int {
        return (int) DB::table('journal_lines')->where('account_id', $ctx['accounts'][$role])->where('dim_claim', $claimId)
            ->selectRaw("coalesce(sum(case when side = ? then amount_minor else -amount_minor end), 0) as b", [$normal])->value('b');
    };
    $outstanding = $balance('claims_outstanding', 'credit');
    expect([
        'claims_outstanding' => $outstanding,
        'claims_payable' => $balance('claims_payable', 'credit'),
        'claims_expense' => $balance('claims_expense', 'debit'),
        'bank_main' => $balance('bank_main', 'debit'),
        'claims_recovery_income' => $balance('claims_recovery_income', 'credit'),
    ])->toBe([
        'claims_outstanding' => $model->reserve - $model->approved(),
        'claims_payable' => $model->approved() - $model->paid(),
        'claims_expense' => $model->reserve,
        'bank_main' => $model->recovered - $model->paid(),
        'claims_recovery_income' => $model->recovered,
    ])->and($outstanding)->toBeGreaterThanOrEqual(0);

    // §4.7 INVARIANT Σ claims_outstanding by claim = 0 after close (and after reject, which also releases the reserve).
    if (in_array($model->status, ['closed', 'rejected'], true)) {
        expect($outstanding)->toBe(0)->and($model->reserve)->toBe($model->approved());
    }

    // One posted, balanced journal per event: a reserve version, an approval, a payment, a recovery. Nothing left unposted.
    $journalIds = DB::table('journal_lines')->where('dim_claim', $claimId)->distinct()->pluck('journal_id')->all();
    $unbalanced = DB::query()->fromSub(DB::table('journal_lines')->whereIn('journal_id', $journalIds)->groupBy('journal_id', 'currency')
        ->havingRaw("sum(case when side = 'debit' then amount_minor else -amount_minor end) <> 0")->select('journal_id'), 'u')->count();
    expect(count($journalIds))->toBe($model->versions + $model->approvedCount() + $model->paidCount() + $model->recoveries)
        ->and($unbalanced)->toBe(0)
        ->and(DB::table('journals')->whereIn('id', $journalIds)->where('status', '<>', 'posted')->count())->toBe(0)
        ->and(DB::table('accounting_events')->where('status', '<>', 'posted')->count())->toBe(0);

    // Pending approvals are exactly the model's waiting steps.
    $pending = fn (string $type): array => DB::table('approvals')->where('object_type', $type)->where('status', 'pending')
        ->whereIn('object_id', $type === 'claim_reopen' ? [$claimId] : array_keys($model->payments))->orderBy('object_id')->pluck('object_id')->all();
    $sorted = function (array $ids): array {
        sort($ids);

        return $ids;
    };
    expect($pending('claim_payment'))->toBe($sorted($model->paymentsIn('pending_approval')))
        ->and($pending('claim_payment_release'))->toBe($sorted($model->paymentsIn('release_pending_approval')))
        ->and($pending('claim_reopen'))->toBe($model->reopenPending ? [$claimId] : []);

    // The claims subledger (design §6.1) equals the GL of claims_outstanding + claims_payable for this claim, and the whole subledger
    // reconciles with no variance as of the period holding the operation's date.
    $yearEnd = CarbonImmutable::parse('2027-06-30');
    $items = array_values(array_filter(app(ClaimsReconciler::class)->itemsAt($ctx['entity_id'], $yearEnd), fn (array $i): bool => $i['object_id'] === $claimId));
    $gl = app(LedgerQuery::class)->normalBalanceByDimension([$ctx['accounts']['claims_outstanding'], $ctx['accounts']['claims_payable']], $ctx['book_id'], $yearEnd, 'claim');
    $periodId = (string) DB::table('fiscal_periods')->where('starts', '<=', $on->toDateString())->where('ends', '>=', $on->toDateString())->value('id');
    expect(array_sum(array_column($items, 'amount_minor')))->toBe($model->reserve - $model->paid())
        ->and($gl['by_dimension'][$claimId] ?? 0)->toBe($model->reserve - $model->paid())
        ->and(app(ReconciliationService::class)->currentVariances($periodId))->not->toHaveKey('claims');
}

it('keeps the claim reserve, its GL and the claims subledger consistent through random operation sequences (INVARIANT)', function (): void {
    $runs = max(1, (int) (getenv('PROPERTY_RUNS') ?: 100));
    $maxSteps = max(1, (int) (getenv('PROPERTY_STEPS') ?: 20));
    $baseSeed = (int) (getenv('PROPERTY_SEED') ?: 20260914);
    $claims = app(ClaimService::class);
    $payments = app(ClaimPaymentService::class);
    $approvals = app(ApprovalService::class);
    $decide = function (string $type, string $objectId, bool $approve) use ($approvals): void {
        $approvals->decide((string) $approvals->pendingFor($type, $objectId), $this->cfo, $approve ? Decision::Approved : Decision::Rejected, $approve ? null : 'Declined');
    };

    /** @var array<string, int> $coverage operation and outcome → times seen */
    $coverage = [];
    for ($run = 0; $run < $runs; $run++) {
        $seed = $baseSeed + $run;
        $gen = new SeededGenerator($seed);
        $log = [];
        try {
            asTenant($this->ctx['tenant_id'], function () use ($gen, $maxSteps, $claims, $payments, $decide, &$log, &$coverage): void {
                $on = CarbonImmutable::parse('2026-07-01')->addDays($gen->int(0, 240));
                $claim = $claims->register($this->policyId, $on, 'Property run', $this->officer, $on);
                $log[] = "register(on={$on->toDateString()})";
                $model = new ClaimLifecycleModel($claim->id);
                assertClaimInvariants($model, $this->ctx, $on);

                for ($step = $gen->int(intdiv($maxSteps + 1, 2), $maxSteps); $step > 0 && $model->status !== 'rejected'; $step--) {
                    $on = $on->addDays($gen->int(0, 3));
                    $op = $model->draw($gen);
                    $log[] = $op->describe()." on {$on->toDateString()}";
                    $expected = $model->refusal($op);
                    $reopenedPaid = $model->reopenedPaid();
                    $before = claimPropertySnapshot($claim->id);
                    $paymentId = null;
                    try {
                        match ($op->kind) {
                            'reserve' => $claims->reserve($claim->id, $op->amount, 'Property reserve', $this->officer, $on),
                            'approve' => $paymentId = $payments->approve($claim->id, $op->amount, $this->world['policyholder_id'], $this->manager, $on)->id,
                            'decide_payment' => $decide('claim_payment', $op->payment, $op->approve),
                            'request_release' => $payments->requestRelease($op->payment, $this->manager, null),
                            'release' => $payments->release($op->payment, $this->finance, $on),
                            'decide_release' => $decide('claim_payment_release', $op->payment, $op->approve),
                            'close' => $claims->close($claim->id, $op->reason, $this->manager, $on),
                            'reject' => $claims->reject($claim->id, $op->reason, $this->manager, $on),
                            'reopen' => $claims->reopen($claim->id, $op->reason, $this->manager, $on),
                            'decide_reopen' => $decide('claim_reopen', $claim->id, $op->approve),
                            'recover' => $claims->recover($claim->id, $op->type, $op->amount, null, 'REC', $this->manager, $on),
                            default => throw new LogicException("Unknown operation {$op->kind}."),
                        };
                        $refused = null;
                    } catch (BusinessRuleViolation $violation) {
                        $refused = $violation->reasonCode;
                    }

                    $key = $op->kind.' '.($refused ?? 'accepted');
                    $coverage[$key] = ($coverage[$key] ?? 0) + 1;
                    if ($reopenedPaid && $refused === null && in_array($op->kind, ['close', 'recover'], true)) {
                        // Follow-up H3: closing or recovering on a paid claim that was reopened, without a new payment.
                        $coverage["{$op->kind} accepted on a reopened paid claim"] = ($coverage["{$op->kind} accepted on a reopened paid claim"] ?? 0) + 1;
                    }
                    expect($refused)->toBe($expected);
                    if ($refused !== null) {
                        expect(claimPropertySnapshot($claim->id))->toBe($before);
                    } else {
                        $model->apply($op, $paymentId);
                    }
                    assertClaimInvariants($model, $this->ctx, $on);
                }
            });
        } catch (Throwable $failure) {
            throw new AssertionFailedError(sprintf(
                "Claim reserve property failed on run %d (seed %d). Replay: PROPERTY_SEED=%d PROPERTY_RUNS=1 PROPERTY_STEPS=%d php vendor/bin/pest %s\nOperations:\n  %s\n%s: %s",
                $run, $seed, $seed, $maxSteps, 'tests/Feature/Claims/ClaimReservePropertyTest.php', implode("\n  ", $log), $failure::class, $failure->getMessage(),
            ));
        }
    }
    ksort($coverage);
    if (getenv('PROPERTY_VERBOSE')) {
        fwrite(STDERR, json_encode($coverage, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    // With enough runs the generator must have reached every operation and every refusal, or the invariants above prove little.
    if ($runs >= 50) {
        $expectedOutcomes = ['reserve accepted', 'approve accepted', 'decide_payment accepted', 'request_release accepted', 'release accepted', 'decide_release accepted',
            'close accepted', 'reject accepted', 'reopen accepted', 'decide_reopen accepted', 'recover accepted', 'reserve INVALID_AMOUNT', 'reserve INVALID_CLAIM_TRANSITION',
            'reserve RESERVE_UNCHANGED', 'reserve RESERVE_BELOW_APPROVED', 'approve APPROVAL_EXCEEDS_RESERVE', 'approve INVALID_AMOUNT', 'approve INVALID_CLAIM_TRANSITION',
            'request_release INVALID_PAYMENT_TRANSITION', 'release INVALID_PAYMENT_TRANSITION', 'close PAYMENTS_OUTSTANDING', 'close INVALID_CLAIM_TRANSITION',
            'reject REASON_REQUIRED', 'reject INVALID_CLAIM_TRANSITION', 'reopen REASON_REQUIRED', 'reopen INVALID_CLAIM_TRANSITION', 'reopen REOPEN_PENDING',
            'recover CLAIM_NOT_PAID', 'recover INVALID_AMOUNT', 'recover INVALID_RECOVERY_TYPE',
            'close accepted on a reopened paid claim', 'recover accepted on a reopened paid claim'];
        expect(array_values(array_diff($expectedOutcomes, array_keys($coverage))))->toBe([]);
    }
});
