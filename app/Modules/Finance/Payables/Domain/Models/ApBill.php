<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Domain\Models;

use App\Modules\Finance\Payables\Domain\Enums\BillStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier bill (addendum v2 §B.4). Totals are the sums of its lines: gross = net + VAT, payable = gross − VAT deducted at source − tax withheld.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $branch_id
 * @property string|null $number
 * @property string $supplier_id
 * @property string $supplier_reference
 * @property CarbonImmutable $bill_date
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $accounting_date
 * @property string $currency
 * @property string|null $description
 * @property int $net_minor
 * @property int $vat_minor
 * @property int $vds_minor
 * @property int $tds_minor
 * @property int $gross_minor
 * @property int $payable_minor
 * @property int $paid_minor
 * @property BillStatus $status
 * @property string $source_type
 * @property string|null $source_id
 * @property string|null $approval_id
 * @property string $created_by
 * @property string|null $submitted_by
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_on
 * @property string|null $cancelled_reason
 * @property CarbonImmutable $created_at
 */
final class ApBill extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'ap_bills';
    protected $guarded = [];
    protected $casts = ['status' => BillStatus::class, 'bill_date' => 'immutable_date', 'due_date' => 'immutable_date', 'accounting_date' => 'immutable_date',
        'cancelled_on' => 'immutable_date', 'approved_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
        'net_minor' => 'int', 'vat_minor' => 'int', 'vds_minor' => 'int', 'tds_minor' => 'int', 'gross_minor' => 'int', 'payable_minor' => 'int', 'paid_minor' => 'int'];

    /** @return HasMany<ApBillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ApBillLine::class, 'bill_id')->orderBy('line_no');
    }

    public function outstandingMinor(): int
    {
        return $this->payable_minor - $this->paid_minor;
    }
}
