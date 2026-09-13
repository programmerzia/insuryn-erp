<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain;

/**
 * Phase 3 design §2 step 2 "counter (re-rate with manual loading, reason mandatory, audit)" and §5 "every manual rating override: reason, approver,
 * shown … on the schedule (as special terms)" (slice R5). A percentage loading in basis points applied by the calculator after the plan's premium steps
 * and the product minimum, before rounding and duties — so VAT and rounding follow the loaded premium.
 *
 * ASSUMPTION: A-91 — a manual override is a loading only (no manual discount) of 0.01 % to 100.00 % (1–10,000 bp).
 */
final readonly class ManualLoading
{
    public const MAX_BASIS_POINTS = 10_000;

    public function __construct(
        public int $basisPoints,
        public string $reason,
    ) {
        if ($basisPoints < 1 || $basisPoints > self::MAX_BASIS_POINTS) {
            throw new RatingFailed('MANUAL_LOADING_INVALID', 'A manual loading is between 0.01% and 100.00%.');
        }
        if (trim($reason) === '') {
            throw new RatingFailed('LOADING_REASON_REQUIRED', 'A manual loading needs a reason.');
        }
    }

    public function percent(): string
    {
        return intdiv($this->basisPoints, 100).'.'.str_pad((string) ($this->basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }

    public function labelEn(): string
    {
        return "Special terms loading {$this->percent()}%";
    }

    public function labelBn(): string
    {
        return 'বিশেষ শর্ত লোডিং '.strtr($this->percent(), ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']).'%';
    }
}
