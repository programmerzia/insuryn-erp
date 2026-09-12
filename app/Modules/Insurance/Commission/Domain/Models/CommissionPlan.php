<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * A commission plan: a flat rate on premium received, with optional withholding tax from the tax engine (ASSUMPTION A-6).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int $rate_bp
 * @property string|null $withholding_jurisdiction
 * @property string|null $withholding_tax_type
 * @property string $status
 */
final class CommissionPlan extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'commission_plans';
    protected $guarded = [];
    protected $casts = ['rate_bp' => 'int'];
}
