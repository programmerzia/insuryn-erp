<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Application;

use App\Modules\Distribution\Application\Licences\LicenceRegistry;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Distribution design note §2 step 1 and §3 (producer eligibility) seen from a quotation: may this producer write this product's class on the day?
 * Quoting is never blocked by it (slice R4); the answer is recorded on the quotation and feeds the underwriting referral rules (R5).
 * `eligible` is null when there is no producer (direct business).
 */
final readonly class ProducerEligibility
{
    public function __construct(
        public ?bool $eligible,
        public ?string $reason = null,
        public ?string $note = null,
    ) {}

    public static function check(?string $producerId, string $productId, CarbonImmutable $on): self
    {
        if ($producerId === null) {
            return new self(null);
        }
        $insuranceClass = (string) DB::table('products')->where('id', $productId)->value('insurance_class');
        try {
            app(LicenceRegistry::class)->assertMayWriteNewBusiness($producerId, $insuranceClass === '' ? 'non_life' : $insuranceClass, $on);
        } catch (BusinessRuleViolation $refused) {
            return new self(false, $refused->reasonCode, $refused->getMessage());
        }

        return new self(true);
    }
}
