<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain\Models;

use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Design §2.4 policies. Premium totals are the current totals after endorsements, in minor units.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $branch_id
 * @property string|null $number
 * @property string $product_id
 * @property string $product_version_id
 * @property string $policyholder_party_id
 * @property string|null $agent_id
 * @property string $channel
 * @property PolicyStatus $status
 * @property CarbonImmutable $inception
 * @property CarbonImmutable $expiry
 * @property string $currency
 * @property int $gross_premium_minor
 * @property int $tax_minor
 * @property int $net_premium_minor
 * @property int $installment_count
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $cancel_date
 * @property string|null $cancel_reason
 * @property int $version
 * @property string|null $renewal_of_policy_id
 * @property CarbonImmutable|null $reinstated_on
 * @property int $stamp_duty_minor stamp duty of the premium (slice R7; 0 for products without a rating plan)
 * @property string|null $quotation_id slice R7: issued from this quotation's proposal
 * @property string|null $proposal_id
 * @property string|null $cover_note_id the cover note the policy superseded
 * @property array<string, int|string|bool|null>|null $risk_inputs frozen at issue (INVARIANT)
 * @property list<string>|null $risk_keys duplicate-risk keys of the current risk (A-88)
 * @property array<string, mixed>|null $rating_result RatingResult::toArray(), frozen at issue (INVARIANT)
 * @property string|null $rating_plan_code
 * @property int|null $rating_plan_version
 * @property list<array{code: string, text: string, loading_bp?: int, reason?: string}>|null $special_terms special terms shown on the schedule (a manual loading and its reason)
 * @property string|null $issue_basis credit | premium_received (A-117)
 * @property string|null $premium_received_reference
 * @property array<string, string|null>|null $insured_details gap fixes W7 (GA-25): the name, address, mortgagee and contact details endorsements set
 */
final class Policy extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'policies';
    protected $guarded = [];
    protected $casts = [
        'status' => PolicyStatus::class, 'inception' => 'immutable_date', 'expiry' => 'immutable_date', 'cancel_date' => 'immutable_date', 'reinstated_on' => 'immutable_date',
        'issued_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        'gross_premium_minor' => 'int', 'tax_minor' => 'int', 'net_premium_minor' => 'int', 'installment_count' => 'int', 'version' => 'int',
        'stamp_duty_minor' => 'int', 'risk_inputs' => 'array', 'risk_keys' => 'array', 'rating_result' => 'array', 'rating_plan_version' => 'int', 'special_terms' => 'array',
        'insured_details' => 'array',
    ];

    /** The frozen rating result of a policy issued from a proposal; null for products without a rating plan. */
    public function ratingResult(): ?\App\Modules\Insurance\Rating\Domain\RatingResult
    {
        return $this->rating_result === null ? null : \App\Modules\Insurance\Rating\Domain\RatingResult::fromArray($this->rating_result);
    }

    /** @return HasMany<PolicyTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PolicyTransaction::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<Installment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('no');
    }
}
