<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A supplier (addendum v2 §B.4): a party holding the vendor role, with payment terms, a withholding category, its tax numbers and the bank account it
 * is paid into. The full account number is stored only encrypted; screens and files show the mask, the bank file decrypts.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $party_id
 * @property string $code
 * @property string $category
 * @property string $status
 * @property int $payment_terms_days
 * @property string|null $default_account_id
 * @property string|null $tin
 * @property string|null $bin
 * @property bool $vat_registered
 * @property string|null $bank_name
 * @property string|null $bank_branch
 * @property string|null $routing_no
 * @property string|null $account_name
 * @property string|null $account_no_masked
 * @property string|null $account_no_enc decrypted value
 * @property string $created_by
 * @property string|null $updated_by
 * @property CarbonImmutable $created_at
 */
final class Supplier extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'suppliers';
    protected $guarded = [];
    protected $hidden = ['account_no_enc'];
    protected $casts = ['account_no_enc' => 'encrypted', 'vat_registered' => 'bool', 'payment_terms_days' => 'int', 'created_at' => 'immutable_datetime'];

    public static function mask(string $accountNumber): string
    {
        $digits = preg_replace('/\D/', '', $accountNumber) ?? '';

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
