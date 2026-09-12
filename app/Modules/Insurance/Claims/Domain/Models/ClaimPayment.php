<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Domain\Models;

use App\Modules\Insurance\Claims\Domain\Enums\ClaimPaymentStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An approved amount of a claim and its payment: approve (CLAIM_APPROVED) → request release → release (CLAIM_PAID).
 *
 * @property string $id
 * @property string $claim_id
 * @property string $payee_party_id
 * @property int $amount_minor
 * @property string $currency
 * @property ClaimPaymentStatus $status
 * @property CarbonImmutable $approved_on
 * @property string $approved_by
 * @property string|null $release_requested_by
 * @property string|null $bank_account_id
 * @property string|null $released_by
 * @property CarbonImmutable|null $paid_on
 */
final class ClaimPayment extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'claim_payments';
    protected $guarded = [];
    protected $casts = ['status' => ClaimPaymentStatus::class, 'approved_on' => 'immutable_date', 'paid_on' => 'immutable_date', 'amount_minor' => 'int'];
}
