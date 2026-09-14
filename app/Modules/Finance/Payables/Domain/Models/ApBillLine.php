<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a supplier bill: the expense account, the net amount, its VAT and the amounts deducted at source; optionally the claim (a garage,
 * surveyor or hospital bill) or policy it is for.
 *
 * @property string $id
 * @property string $bill_id
 * @property int $line_no
 * @property string $description
 * @property string $account_id
 * @property string|null $claim_id
 * @property string|null $policy_id
 * @property int $net_minor
 * @property int $vat_minor
 * @property int $vds_minor
 * @property int $tds_minor
 */
final class ApBillLine extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    public $timestamps = false;
    protected $table = 'ap_bill_lines';
    protected $guarded = [];
    protected $casts = ['line_no' => 'int', 'net_minor' => 'int', 'vat_minor' => 'int', 'vds_minor' => 'int', 'tds_minor' => 'int'];
}
