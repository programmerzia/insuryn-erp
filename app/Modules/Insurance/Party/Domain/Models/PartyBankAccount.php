<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * The full account number is stored only encrypted (application key); every read path uses the mask.
 *
 * @property string $id
 * @property string $party_id
 * @property string $bank_name
 * @property string $account_no_masked
 * @property string $account_no_enc decrypted value
 * @property bool $is_default
 */
final class PartyBankAccount extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'party_bank_accounts';
    protected $guarded = [];
    protected $hidden = ['account_no_enc'];
    protected $casts = ['account_no_enc' => 'encrypted', 'is_default' => 'bool'];

    public static function mask(string $accountNumber): string
    {
        $digits = preg_replace('/\D/', '', $accountNumber) ?? '';

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
