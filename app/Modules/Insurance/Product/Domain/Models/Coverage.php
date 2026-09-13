<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Models;

use App\Modules\Insurance\Product\Domain\Enums\CoverageBasis;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 design §1 coverages: a cover a product version offers (own damage, passenger liability…), rated by the plan steps that apply to its code.
 *
 * @property string $id
 * @property string $product_version_id
 * @property string $code
 * @property string $name_en
 * @property string $name_bn
 * @property bool $mandatory
 * @property CoverageBasis $basis
 * @property string|null $rating_rule_ref
 * @property array<string, mixed>|null $limit_rule
 * @property array<string, mixed>|null $deductible_rule
 * @property int $sort_order
 */
final class Coverage extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'coverages';
    protected $guarded = [];
    protected $casts = ['mandatory' => 'bool', 'basis' => CoverageBasis::class, 'limit_rule' => 'array', 'deductible_rule' => 'array', 'sort_order' => 'int'];
}
