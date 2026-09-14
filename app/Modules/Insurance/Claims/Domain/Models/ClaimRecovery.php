<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Design §4.8 recovery (salvage, subrogation, third party) received in cash.
 *
 * @property string $id
 * @property string $claim_id
 * @property string $type
 * @property int $amount_minor
 * @property string $currency
 * @property CarbonImmutable $received_on
 * @property string|null $bank_account_id
 * @property string|null $reference
 * @property string $recorded_by
 * @property string|null $number gap fix GA-21: the recovery receipt number (null for recoveries recorded before)
 * @property string|null $payer_party_id gap fix GA-21: who paid the recovery
 */
final class ClaimRecovery extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    public const UPDATED_AT = null;

    protected $table = 'claim_recoveries';
    protected $guarded = [];
    protected $casts = ['received_on' => 'immutable_date', 'amount_minor' => 'int'];
}
