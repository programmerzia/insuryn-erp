<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Models;

use App\Modules\Insurance\Rating\Domain\Enums\RatingStepKind;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 design §1 rating_steps.
 *
 * @property string $id
 * @property string $plan_id
 * @property int $order_no
 * @property string $code
 * @property RatingStepKind $kind
 * @property string $expression
 * @property string|null $condition
 * @property string|null $applies_to
 * @property string $label_en
 * @property string $label_bn
 */
final class RatingStep extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'rating_steps';
    protected $guarded = [];
    protected $casts = ['order_no' => 'int', 'kind' => RatingStepKind::class];
}
