<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * Design §2.4 bank_accounts: a company bank account and the GL account its cash posts to (§4.2 overrides bank_main).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $gl_account_id
 * @property string $bank_name
 * @property string $account_no_masked
 * @property string $currency
 * @property string $status
 */
final class BankAccount extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'bank_accounts';
    protected $guarded = [];
}
