<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 design §1 rate_table_rows (units: D-20).
 *
 * @property string $id
 * @property string $table_id
 * @property int $position
 * @property array<string, string> $keys
 * @property int|null $value_minor
 * @property int|null $value_bp
 * @property int|null $band_from
 * @property int|null $band_to
 * @property string|null $band_label
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_to
 */
final class RateTableRow extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'rate_table_rows';
    protected $guarded = [];
    protected $casts = [
        'position' => 'int', 'keys' => 'array', 'value_minor' => 'int', 'value_bp' => 'int', 'band_from' => 'int', 'band_to' => 'int',
        'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date',
    ];
}
