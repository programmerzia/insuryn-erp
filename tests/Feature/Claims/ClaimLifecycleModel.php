<?php

declare(strict_types=1);

namespace Tests\Feature\Claims;

use Tests\Support\Property\SeededGenerator;

/**
 * Reference model of one claim for ClaimReservePropertyTest, written from design §4.6–§4.8 / §5.5 and the slice 1B.1 interpretations
 * (reserve takes the new total; reserve never below committed; close and reject release the unapproved reserve; reopen by approval), not
 * from the services' code paths. It predicts whether an operation is accepted (null) or refused (the BusinessRuleViolation reason code),
 * applies accepted ones, and draws random operations biased towards the ones the claim's state can take.
 */
final class ClaimLifecycleModel
{
    /** Approval policy thresholds seeded by the test (min_amount_minor: amount ≥ value waits for approval). */
    public const PAYMENT_APPROVAL_FROM = 5_000_000;

    public const RELEASE_APPROVAL_FROM = 3_000_000;

    public const REOPEN_APPROVAL_FROM = 2_000_000;

    private const COMMITTED = ['pending_approval', 'approved', 'release_requested', 'release_pending_approval', 'paid'];

    private const APPROVED = ['approved', 'release_requested', 'release_pending_approval', 'paid'];

    private const UNSETTLED = ['pending_approval', 'approved', 'release_requested', 'release_pending_approval'];

    public string $status = 'registered';

    public int $reserve = 0;

    public int $versions = 0;

    /** @var array<string, array{amount: int, status: string}> payment id → amount and status */
    public array $payments = [];

    public int $recovered = 0;

    public int $recoveries = 0;

    public bool $reopenPending = false;

    public function __construct(public readonly string $claimId) {}

    /** Committed against the reserve: awaiting approval, approved, on its way to payment, or paid. */
    public function committed(): int
    {
        return $this->sumWhere(self::COMMITTED);
    }

    /** Passed CLAIM_APPROVED: moved from claims_outstanding to claims_payable. */
    public function approved(): int
    {
        return $this->sumWhere(self::APPROVED);
    }

    public function paid(): int
    {
        return $this->sumWhere(['paid']);
    }

    /** Payments that posted CLAIM_APPROVED. */
    public function approvedCount(): int
    {
        return count(array_filter($this->payments, fn (array $p): bool => in_array($p['status'], self::APPROVED, true)));
    }

    /** Payments that posted CLAIM_PAID. */
    public function paidCount(): int
    {
        return count($this->paymentsIn('paid'));
    }

    /** Follow-up H3: reopened after being paid — `reserved` with money already paid (a claim only returns to reserved with payments by reopening). */
    public function reopenedPaid(): bool
    {
        return $this->status === 'reserved' && $this->paid() > 0;
    }

    /** @return list<string> */
    public function paymentsIn(string $status): array
    {
        return array_keys(array_filter($this->payments, fn (array $p): bool => $p['status'] === $status));
    }

    /** The reason code the services must refuse $op with, or null when they must accept it. */
    public function refusal(ClaimOperation $op): ?string
    {
        $payment = $this->payments[$op->payment] ?? null;

        return match ($op->kind) {
            'reserve' => match (true) {
                $op->amount <= 0 => 'INVALID_AMOUNT',
                ! in_array($this->status, ['registered', 'reserved', 'approved', 'paid'], true) => 'INVALID_CLAIM_TRANSITION',
                $op->amount === $this->reserve => 'RESERVE_UNCHANGED',
                $op->amount < $this->committed() => 'RESERVE_BELOW_APPROVED',
                default => null,
            },
            'approve' => match (true) {
                $op->amount <= 0 => 'INVALID_AMOUNT',
                ! in_array($this->status, ['reserved', 'approved', 'paid'], true) => 'INVALID_CLAIM_TRANSITION',
                $op->amount > $this->reserve - $this->committed() => 'APPROVAL_EXCEEDS_RESERVE',
                default => null,
            },
            'request_release' => $payment !== null && $payment['status'] === 'approved' ? null : 'INVALID_PAYMENT_TRANSITION',
            'release' => $payment !== null && $payment['status'] === 'release_requested' ? null : 'INVALID_PAYMENT_TRANSITION',
            // Follow-up H3 (D-61): a reopened claim that was paid is `reserved` again and closes without a new payment.
            'close' => match (true) {
                ! in_array($this->status, ['approved', 'paid'], true) && ! $this->reopenedPaid() => 'INVALID_CLAIM_TRANSITION',
                $this->hasUnsettled() => 'PAYMENTS_OUTSTANDING',
                default => null,
            },
            'reject' => match (true) {
                trim($op->reason) === '' => 'REASON_REQUIRED',
                ! in_array($this->status, ['registered', 'reserved'], true) => 'INVALID_CLAIM_TRANSITION',
                $this->hasUnsettled() => 'PAYMENTS_OUTSTANDING',
                default => null,
            },
            'reopen' => match (true) {
                trim($op->reason) === '' => 'REASON_REQUIRED',
                $this->status !== 'closed' => 'INVALID_CLAIM_TRANSITION',
                $this->reopenPending => 'REOPEN_PENDING',
                default => null,
            },
            'recover' => match (true) {
                $op->amount <= 0 => 'INVALID_AMOUNT',
                ! in_array($op->type, ['salvage', 'subrogation', 'third_party'], true) => 'INVALID_RECOVERY_TYPE',
                // Follow-up H3 (D-61): §5.5 "recovery* (any time after paid)": whenever anything was ever paid on the claim, whatever its status now.
                $this->paid() === 0 => 'CLAIM_NOT_PAID',
                default => null,
            },
            // Approval decisions are only drawn for an approval that is pending, and no claim rule refuses them.
            'decide_payment', 'decide_release', 'decide_reopen' => null,
            default => throw new \LogicException("Unknown operation {$op->kind}."),
        };
    }

    /** Applies an accepted operation. $paymentId names the payment an accepted `approve` created. */
    public function apply(ClaimOperation $op, ?string $paymentId = null): void
    {
        switch ($op->kind) {
            case 'reserve':
                $this->setReserve($op->amount);
                if ($this->status === 'registered') {
                    $this->status = 'reserved';
                }
                break;
            case 'approve':
                $this->payments[(string) $paymentId] = ['amount' => $op->amount, 'status' => 'pending_approval'];
                if ($op->amount < self::PAYMENT_APPROVAL_FROM) {
                    $this->approvePayment((string) $paymentId);
                }
                break;
            case 'decide_payment':
                $op->approve ? $this->approvePayment($op->payment) : $this->movePayment($op->payment, 'rejected');
                break;
            case 'request_release':
                $this->movePayment($op->payment, 'release_requested');
                break;
            case 'release':
                $this->movePayment($op->payment, 'release_pending_approval');
                if (($this->payments[$op->payment]['amount'] ?? 0) < self::RELEASE_APPROVAL_FROM) {
                    $this->payPayment($op->payment);
                }
                break;
            case 'decide_release':
                $op->approve ? $this->payPayment($op->payment) : $this->movePayment($op->payment, 'approved');
                break;
            case 'close':
                $this->releaseUnapproved();
                $this->status = 'closed';
                break;
            case 'reject':
                $this->releaseUnapproved();
                $this->status = 'rejected';
                break;
            case 'reopen':
                if ($this->reserve >= self::REOPEN_APPROVAL_FROM) {
                    $this->reopenPending = true;
                } else {
                    $this->status = 'reserved';
                }
                break;
            case 'decide_reopen':
                $this->reopenPending = false;
                if ($op->approve) {
                    $this->status = 'reserved';
                }
                break;
            case 'recover':
                $this->recovered += $op->amount;
                $this->recoveries++;
                break;
        }
    }

    /**
     * Draws the next operation: mostly ones the state can take, sometimes deliberately invalid ones (wrong status, amounts over the reserve,
     * zero or negative amounts, blank reasons, an unknown recovery type) so refusals are exercised too.
     */
    public function draw(SeededGenerator $gen): ClaimOperation
    {
        $open = in_array($this->status, ['registered', 'reserved', 'approved', 'paid'], true);
        $available = $this->reserve - $this->committed();
        $pending = $this->paymentsIn('pending_approval');
        $approved = $this->paymentsIn('approved');
        $requested = $this->paymentsIn('release_requested');
        $releasing = $this->paymentsIn('release_pending_approval');
        $all = array_keys($this->payments);
        $kind = $gen->weighted([
            'reserve' => $open ? 12 : 2,
            'approve' => in_array($this->status, ['reserved', 'approved', 'paid'], true) ? ($available > 0 ? 16 : 3) : 1,
            'decide_payment' => $pending !== [] ? 15 : 0,
            'request_release' => $all === [] ? 0 : ($approved !== [] ? 20 : 1),
            'release' => $all === [] ? 0 : ($requested !== [] ? 20 : 1),
            'decide_release' => $releasing !== [] ? 15 : 0,
            'close' => in_array($this->status, ['approved', 'paid'], true) || $this->reopenedPaid() ? ($this->hasUnsettled() ? 3 : 25) : 1,
            'reject' => in_array($this->status, ['registered', 'reserved'], true) ? 2 : 1,
            'reopen' => $this->status === 'closed' ? 15 : 1,
            'decide_reopen' => $this->reopenPending ? 15 : 0,
            'recover' => $this->paid() > 0 ? ($this->reopenedPaid() ? 10 : 6) : 2,
        ]);

        return match ($kind) {
            'reserve' => new ClaimOperation('reserve', amount: $this->drawReserve($gen)),
            'approve' => new ClaimOperation('approve', amount: match (true) {
                $gen->chance(8) => $gen->chance(50) ? 0 : -$gen->int(1, 100_000),
                $available <= 0 || $gen->chance(12) => max(1, $available) + $gen->int(1, 1_000_000),
                $gen->chance(25) => $available,
                default => $gen->int(1, $available),
            }),
            'decide_payment' => new ClaimOperation('decide_payment', payment: $gen->pick($pending), approve: $gen->chance(70)),
            'request_release' => new ClaimOperation('request_release', payment: $gen->pick($approved !== [] && $gen->chance(85) ? $approved : $all)),
            'release' => new ClaimOperation('release', payment: $gen->pick($requested !== [] && $gen->chance(85) ? $requested : $all)),
            'decide_release' => new ClaimOperation('decide_release', payment: $gen->pick($releasing), approve: $gen->chance(70)),
            'close' => new ClaimOperation('close', reason: 'Settled'),
            'reject' => new ClaimOperation('reject', reason: $gen->chance(15) ? ' ' : 'Not covered'),
            'reopen' => new ClaimOperation('reopen', reason: $gen->chance(10) ? '' : 'Further damage found'),
            'decide_reopen' => new ClaimOperation('decide_reopen', approve: $gen->chance(70)),
            'recover' => new ClaimOperation('recover', amount: $gen->chance(5) ? 0 : $gen->int(1, 2_000_000),
                type: $gen->chance(10) ? 'refund' : $gen->pick(['salvage', 'subrogation', 'third_party'])),
            default => throw new \LogicException("Unknown operation {$kind}."),
        };
    }

    private function drawReserve(SeededGenerator $gen): int
    {
        $committed = $this->committed();

        return match (true) {
            $gen->chance(5) => $gen->chance(50) ? 0 : -$gen->int(1, 500_000),
            $this->reserve > 0 && $gen->chance(6) => $this->reserve,
            $committed > 1 && $gen->chance(8) => $committed - $gen->int(1, min($committed - 1, 50_000)),
            $committed > 0 && $gen->chance(10) => $committed,
            // Increases and decreases around the current reserve, sometimes crossing the payment and reopen approval thresholds.
            $this->reserve > 0 && $gen->chance(50) => max(1, $committed, $this->reserve + $gen->int(-3_000_000, 3_000_000)),
            default => $gen->int(max(1, $committed), $committed + 12_000_000),
        };
    }

    private function movePayment(string $paymentId, string $status): void
    {
        $this->payments[$paymentId] = ['amount' => $this->payments[$paymentId]['amount'] ?? 0, 'status' => $status];
    }

    private function approvePayment(string $paymentId): void
    {
        $this->movePayment($paymentId, 'approved');
        if ($this->status === 'reserved') {
            $this->status = 'approved';
        }
    }

    private function payPayment(string $paymentId): void
    {
        $this->movePayment($paymentId, 'paid');
        if (in_array($this->status, ['reserved', 'approved'], true)) {
            $this->status = 'paid';
        }
    }

    private function releaseUnapproved(): void
    {
        if ($this->reserve > $this->approved()) {
            $this->setReserve($this->approved());
        }
    }

    private function setReserve(int $amount): void
    {
        $this->reserve = $amount;
        $this->versions++;
    }

    private function hasUnsettled(): bool
    {
        return array_filter($this->payments, fn (array $p): bool => in_array($p['status'], self::UNSETTLED, true)) !== [];
    }

    /** @param list<string> $statuses */
    private function sumWhere(array $statuses): int
    {
        return array_sum(array_map(fn (array $p): int => in_array($p['status'], $statuses, true) ? $p['amount'] : 0, $this->payments));
    }
}
