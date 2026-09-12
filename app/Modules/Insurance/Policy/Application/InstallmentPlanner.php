<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Insurance\Policy\Domain\Enums\InstallmentStatus;
use App\Modules\Insurance\Policy\Domain\Models\Installment;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;

/**
 * Premium schedule (design §2.4 installments): gross premium in equal monthly installments from inception, the last
 * absorbing the remainder. Decreases credit unpaid installments from the last one backwards.
 */
final class InstallmentPlanner
{
    public function planFor(Policy $policy): void
    {
        $count = max(1, $policy->installment_count);
        $share = intdiv($policy->gross_premium_minor, $count);
        for ($no = 1; $no <= $count; $no++) {
            $amount = $no === $count ? $policy->gross_premium_minor - $share * ($count - 1) : $share;
            $this->add($policy, $no, $policy->inception->addMonthsNoOverflow($no - 1), $amount);
        }
    }

    /** A premium increase becomes a new installment due on the effective date. */
    public function addIncrease(Policy $policy, CarbonImmutable $dueDate, int $amountMinor): void
    {
        $this->add($policy, (int) Installment::query()->where('policy_id', $policy->id)->max('no') + 1, $dueDate, $amountMinor);
    }

    public function outstanding(Policy $policy): int
    {
        return (int) Installment::query()->where('policy_id', $policy->id)->selectRaw('coalesce(sum(amount_minor - paid_minor - cancelled_minor), 0) as o')->value('o');
    }

    /**
     * Credits $amountMinor against unpaid installments, last first.
     *
     * @throws BusinessRuleViolation PREMIUM_CREDIT_EXCEEDS_OUTSTANDING
     */
    public function credit(Policy $policy, int $amountMinor): void
    {
        $remaining = $amountMinor;
        foreach (Installment::query()->where('policy_id', $policy->id)->orderByDesc('no')->lockForUpdate()->get() as $installment) {
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
        if ($remaining > 0) {
            throw new BusinessRuleViolation('PREMIUM_CREDIT_EXCEEDS_OUTSTANDING', "Policy {$policy->id} has less unpaid premium than the {$amountMinor} to credit.");
        }
    }

    private function add(Policy $policy, int $no, CarbonImmutable $dueDate, int $amountMinor): void
    {
        Installment::query()->create(['policy_id' => $policy->id, 'no' => $no, 'due_date' => $dueDate->toDateString(),
            'amount_minor' => $amountMinor, 'paid_minor' => 0, 'cancelled_minor' => 0, 'status' => InstallmentStatus::Pending->value]);
    }
}
