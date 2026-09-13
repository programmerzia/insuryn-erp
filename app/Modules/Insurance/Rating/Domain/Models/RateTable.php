<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Models;

use App\Modules\Insurance\Rating\Domain\Enums\RateValueType;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 3 design §1 rate_tables.
 *
 * @property string $id
 * @property string $plan_id
 * @property string $code
 * @property string $name
 * @property list<string> $dimensions
 * @property RateValueType $value_type
 */
final class RateTable extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'rate_tables';
    protected $guarded = [];
    protected $casts = ['dimensions' => 'array', 'value_type' => RateValueType::class];

    /** @return HasMany<RateTableRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(RateTableRow::class, 'table_id')->orderBy('position');
    }
}
