<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * One bill in a payment run, with the supplier's bank account as it was when the bill was added (INVARIANT §B.4: the file pays that snapshot).
 *
 * @property string $id
 * @property string $run_id
 * @property string $payable_type
 * @property string $payable_id
 * @property string $supplier_id
 * @property string $payee_party_id
 * @property string $branch_id
 * @property array{bank_name: string|null, bank_branch: string|null, routing_no: string|null, account_name: string|null, account_no_masked: string|null, account_no_enc: string|null} $bank_account_snapshot
 * @property int $amount_minor
 * @property string $status
 */
final class PaymentRunItem extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'payment_run_items';
    protected $guarded = [];
    protected $casts = ['bank_account_snapshot' => 'array', 'amount_minor' => 'int'];
}
