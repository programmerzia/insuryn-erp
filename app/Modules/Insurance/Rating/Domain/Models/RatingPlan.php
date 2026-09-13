<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Models;

use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanSource;
use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 3 design §1 rating_plans. Changed through RatingPlanService only.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $class_code
 * @property int $version
 * @property string $currency
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property RatingPlanStatus $status
 * @property RatingPlanSource $source
 * @property bool $verify placeholder values still to be confirmed
 * @property string|null $notes
 * @property string|null $copied_from_plan_id
 * @property string $created_by
 * @property string|null $approved_by
 * @property string|null $activated_by
 * @property string|null $retired_by
 */
final class RatingPlan extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'rating_plans';
    protected $guarded = [];
    protected $casts = [
        'version' => 'int', 'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date', 'status' => RatingPlanStatus::class, 'source' => RatingPlanSource::class,
        'verify' => 'bool', 'approved_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime',
    ];

    /** @return HasMany<RateTable, $this> */
    public function tables(): HasMany
    {
        return $this->hasMany(RateTable::class, 'plan_id')->orderBy('code');
    }

    /** @return HasMany<RatingStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(RatingStep::class, 'plan_id')->orderBy('order_no');
    }
}
