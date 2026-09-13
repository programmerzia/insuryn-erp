<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Domain;

/**
 * Why a proposal is referred to an underwriter instead of being approved automatically (Phase 3 design §2 step 2, §5): SUM_INSURED_ABOVE_LIMIT,
 * PRODUCER_INELIGIBLE, RISK_FLAG, DUPLICATE_RISK, KYC_NOT_VERIFIED — with a sentence for the referral queue.
 */
final readonly class ReferralReason
{
    public const SUM_INSURED_ABOVE_LIMIT = 'SUM_INSURED_ABOVE_LIMIT';
    public const PRODUCER_INELIGIBLE = 'PRODUCER_INELIGIBLE';
    public const RISK_FLAG = 'RISK_FLAG';
    public const DUPLICATE_RISK = 'DUPLICATE_RISK';
    public const KYC_NOT_VERIFIED = 'KYC_NOT_VERIFIED';

    public function __construct(
        public string $code,
        public string $detail,
    ) {}

    /** @return array{code: string, detail: string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'detail' => $this->detail];
    }
}
