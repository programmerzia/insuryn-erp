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
    ];

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
