<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Domain\Models;

use App\Modules\Insurance\Rating\Domain\RatingResult;
use App\Modules\Insurance\Underwriting\Domain\Enums\KycStatus;
use App\Modules\Insurance\Underwriting\Domain\Enums\ProposalStatus;
use App\Modules\Insurance\Underwriting\Domain\Enums\UnderwritingStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 design §2 proposal (slice R5): an accepted quotation with KYC, documents and the underwriting outcome. `rating_result` starts as the
 * quotation's frozen result and is re-rated when an underwriter applies a manual loading.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $entity_id
 * @property string $branch_id
 * @property string $quotation_id
 * @property string $number
 * @property string $product_id
 * @property string $product_version_id
 * @property string $class_code
 * @property string $customer_party_id
 * @property string|null $producer_id
 * @property CarbonImmutable $inception
 * @property array<string, mixed> $risk_inputs
 * @property list<string> $risk_keys
 * @property array<string, mixed> $rating_result
 * @property string $rating_plan_code
 * @property int $rating_plan_version
 * @property string $currency
 * @property int $sum_insured_minor
 * @property int $net_premium_minor
 * @property int $duties_minor
 * @property int $gross_premium_minor
 * @property ProposalStatus $status
 * @property KycStatus $kyc_status
 * @property string|null $kyc_id_type
 * @property string|null $kyc_id_number
 * @property string|null $kyc_verified_by
 * @property CarbonImmutable|null $kyc_verified_at
 * @property string|null $kyc_waiver_reason
 * @property UnderwritingStatus|null $underwriting_status
 * @property list<array{code: string, detail: string}>|null $referral_reasons
 * @property string|null $approval_id
 * @property int|null $manual_loading_bp
 * @property string|null $manual_loading_reason
 * @property string|null $manual_loading_by
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property string|null $policy_id
 * @property CarbonImmutable|null $issued_at
 * @property string $created_by
 * @property CarbonImmutable $created_at
 */
final class Proposal extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'proposals';
    protected $guarded = [];
    protected $casts = [
        'status' => ProposalStatus::class, 'kyc_status' => KycStatus::class, 'underwriting_status' => UnderwritingStatus::class, 'inception' => 'immutable_date',
        'risk_inputs' => 'array', 'risk_keys' => 'array', 'rating_result' => 'array', 'referral_reasons' => 'array', 'rating_plan_version' => 'int', 'sum_insured_minor' => 'int',
        'net_premium_minor' => 'int', 'duties_minor' => 'int', 'gross_premium_minor' => 'int', 'manual_loading_bp' => 'int', 'kyc_verified_at' => 'immutable_datetime',
        'submitted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'issued_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
    ];

    public function ratingResult(): RatingResult
    {
        return RatingResult::fromArray($this->rating_result);
    }
}
