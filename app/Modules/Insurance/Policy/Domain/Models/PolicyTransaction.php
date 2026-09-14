<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain\Models;

use App\Modules\Insurance\Policy\Domain\Enums\PolicyTransactionType;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Design §2.4 policy_transactions: one row per new business, endorsement, cancellation or renewal; the
 * source of its accounting event (idempotency key uses this id). Append-only.
 *
 * @property string $id
 * @property string $policy_id
 * @property PolicyTransactionType $type
 * @property CarbonImmutable $effective_date
 * @property int $premium_delta_minor gross
 * @property int $net_delta_minor
 * @property int $tax_delta_minor
 * @property int $policy_version
 * @property string|null $reason
 * @property array<string, int>|null $amounts
 * @property int $stamp_duty_delta_minor slice R7
 * @property array<string, mixed>|null $rating_result an endorsement's re-rating (RatingResult::toArray()), frozen
 * @property string|null $rating_basis original_plan | current_tariff
 * @property string|null $endorsement_kind gap fixes W7 (GA-25): name | address | mortgagee | contact for an endorsement that changes no premium
 * @property array{before: array<string, string|null>, after: array<string, string|null>}|null $details_change
 */
final class PolicyTransaction extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    public const UPDATED_AT = null;

    protected $table = 'policy_transactions';
    protected $guarded = [];
    protected $casts = [
        'type' => PolicyTransactionType::class, 'effective_date' => 'immutable_date', 'amounts' => 'array',
        'premium_delta_minor' => 'int', 'net_delta_minor' => 'int', 'tax_delta_minor' => 'int', 'policy_version' => 'int',
        'stamp_duty_delta_minor' => 'int', 'rating_result' => 'array', 'details_change' => 'array',
    ];
}
