<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Domain\Models;

use App\Modules\Insurance\Quotation\Domain\Enums\QuotationStatus;
use App\Modules\Insurance\Rating\Domain\RatingResult;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 design §2 quotation (slice R4). `rating_result` is RatingResult::toArray() of the last rating; frozen once the quotation is issued.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $entity_id
 * @property string $branch_id
 * @property string|null $number
 * @property string $product_id
 * @property string $product_version_id
 * @property string|null $class_code
 * @property string|null $customer_party_id
 * @property string|null $producer_id
 * @property CarbonImmutable $inception
 * @property array<string, mixed> $risk_inputs
 * @property list<string> $coverages chosen optional coverages
 * @property list<string>|null $risk_keys normalised duplicate-risk keys (R5)
 * @property array<string, mixed>|null $rating_result
 * @property string|null $rating_plan_code
 * @property int|null $rating_plan_version
 * @property string $currency
 * @property int|null $sum_insured_minor
 * @property int|null $net_premium_minor
 * @property int|null $duties_minor
 * @property int|null $gross_premium_minor
 * @property CarbonImmutable|null $valid_until last day the quotation may be accepted
 * @property QuotationStatus $status
 * @property bool|null $producer_eligible
 * @property string|null $producer_eligibility_reason
 * @property string|null $producer_eligibility_note
 * @property string|null $decline_reason
 * @property string|null $declined_by
 * @property CarbonImmutable|null $declined_at
 * @property string|null $issued_by
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable|null $converted_at
 * @property string $created_by
 * @property string|null $updated_by
 * @property CarbonImmutable $created_at
 */
final class Quotation extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'quotations';
    protected $guarded = [];
    protected $casts = [
        'status' => QuotationStatus::class, 'inception' => 'immutable_date', 'valid_until' => 'immutable_date', 'risk_inputs' => 'array', 'coverages' => 'array',
        'risk_keys' => 'array', 'rating_result' => 'array', 'rating_plan_version' => 'int', 'sum_insured_minor' => 'int', 'net_premium_minor' => 'int', 'duties_minor' => 'int',
        'gross_premium_minor' => 'int', 'producer_eligible' => 'bool', 'declined_at' => 'immutable_datetime', 'issued_at' => 'immutable_datetime',
        'expired_at' => 'immutable_datetime', 'converted_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
    ];

    public function ratingResult(): ?RatingResult
    {
        return $this->rating_result === null ? null : RatingResult::fromArray($this->rating_result);
    }
}
