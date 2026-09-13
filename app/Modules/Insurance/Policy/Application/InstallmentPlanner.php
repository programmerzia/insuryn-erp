<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Insurance\Policy\Domain\Enums\InstallmentStatus;
use App\Modules\Insurance\Policy\Domain\Models\Installment;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\PremiumMath;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Premium schedule (design §2.4 installments): gross premium in equal monthly installments from inception, the last absorbing the remainder.
 * Multi-payer (spec §4): each installment is billed to every payer by share (half-even, the last payer absorbing rounding); without payers the
 * policyholder pays 100%. Credits (premium decrease, cancellation) are shared by payer the same way, each payer's unpaid installments credited
 * from the last one backwards; a payer with less unpaid than its share passes the rest to the others.
 */
final class InstallmentPlanner
{
    private const WHOLE_BP = 10_000;

    public function planFor(Policy $policy): void
    {
        $count = max(1, $policy->installment_count);
        $share = intdiv($policy->gross_premium_minor, $count);
        for ($no = 1; $no <= $count; $no++) {
            $amount = $no === $count ? $policy->gross_premium_minor - $share * ($count - 1) : $share;
            $this->addForPayers($policy, $no, $policy->inception->addMonthsNoOverflow($no - 1), $amount);
        }
    }

    /** A premium increase becomes a new installment due on the effective date, billed to the payers by share. */
    public function addIncrease(Policy $policy, CarbonImmutable $dueDate, int $amountMinor): void
    {
        $this->addForPayers($policy, (int) Installment::query()->where('policy_id', $policy->id)->max('no') + 1, $dueDate, $amountMinor);
    }

    public function outstanding(Policy $policy): int
    {
        return (int) Installment::query()->where('policy_id', $policy->id)->selectRaw('coalesce(sum(amount_minor - paid_minor - cancelled_minor), 0) as o')->value('o');
    }

    /**
     * The policy's payers in the order they were named; the policyholder alone when none were.
     *
     * @return list<PayerShare>
     */
    public function payers(Policy $policy): array
    {
        $payers = [];
        foreach (DB::table('policy_payers')->where('policy_id', $policy->id)->orderBy('id')->get(['party_id', 'share_bp']) as $row) {
            $payers[] = new PayerShare((string) $row->party_id, (int) $row->share_bp);
        }

        return $payers === [] ? [new PayerShare($policy->policyholder_party_id, self::WHOLE_BP)] : $payers;
    }

    /**
     * Credits $amountMinor against unpaid installments, shared by payer.
     *
     * @throws BusinessRuleViolation PREMIUM_CREDIT_EXCEEDS_OUTSTANDING
     */
    public function credit(Policy $policy, int $amountMinor): void
    {
        $shortfall = 0;
        foreach ($this->split($policy, $amountMinor) as [$payerId, $portion]) {
            $shortfall += $this->creditInstallments($policy, $payerId, $portion);
        }
        $remaining = $this->creditInstallments($policy, null, $shortfall);
        if ($remaining > 0) {
            throw new BusinessRuleViolation('PREMIUM_CREDIT_EXCEEDS_OUTSTANDING', "Policy {$policy->id} has less unpaid premium than the {$amountMinor} to credit.");
        }
    }

    /** Credits unpaid installments (of one payer, or of anyone when null) from the last backwards; returns what could not be credited. */
    private function creditInstallments(Policy $policy, ?string $payerId, int $amountMinor): int
    {
        $remaining = $amountMinor;
        $installments = Installment::query()->where('policy_id', $policy->id)->when($payerId !== null, fn ($q) => $q->where('payer_party_id', $payerId))
            ->orderByDesc('no')->orderByDesc('id')->lockForUpdate()->get();
        foreach ($installments as $installment) {
            if ($remaining === 0) {
                break;
            }
            $take = min($remaining, $installment->outstanding());
            if ($take === 0) {
                continue;
            }
            $installment->cancelled_minor += $take;
            $installment->status = $installment->outstanding() === 0 && $installment->paid_minor === 0 ? InstallmentStatus::Cancelled
                : ($installment->outstanding() === 0 ? InstallmentStatus::Paid : $installment->status);
            $installment->save();
            $remaining -= $take;
        }

        return $remaining;
    }

    /** @return list<array{0: string, 1: int}> payer id → portion of $amountMinor, the last payer absorbing rounding */
    private function split(Policy $policy, int $amountMinor): array
    {
        $payers = $this->payers($policy);
        $portions = [];
        $allocated = 0;
        foreach ($payers as $index => $payer) {
            $portion = $index === count($payers) - 1 ? $amountMinor - $allocated : PremiumMath::prorate($amountMinor, $payer->shareBp, self::WHOLE_BP);
            $portions[] = [$payer->partyId, $portion];
            $allocated += $portion;
        }

        return $portions;
    }

    private function addForPayers(Policy $policy, int $no, CarbonImmutable $dueDate, int $amountMinor): void
    {
        foreach ($this->split($policy, $amountMinor) as [$payerId, $portion]) {
            if ($portion > 0) {
                Installment::query()->create(['policy_id' => $policy->id, 'no' => $no, 'payer_party_id' => $payerId, 'due_date' => $dueDate->toDateString(),
                    'amount_minor' => $portion, 'paid_minor' => 0, 'cancelled_minor' => 0, 'status' => InstallmentStatus::Pending->value]);
            }
        }
    }
}
