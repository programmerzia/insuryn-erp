<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Design §2.4 bank_statement_lines. amount_minor is signed (positive = money in). line_hash makes imports idempotent.
 *
 * @property string $id
 * @property string $bank_account_id
 * @property CarbonImmutable $posted_on
 * @property int $amount_minor
 * @property string|null $reference
 * @property string|null $description
 * @property string $match_status unmatched|matched|explained
 * @property string|null $explanation
 */
final class BankStatementLine extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    public $timestamps = false;
    protected $table = 'bank_statement_lines';
    protected $guarded = [];
    protected $casts = ['posted_on' => 'immutable_date', 'amount_minor' => 'int', 'raw' => 'array', 'imported_at' => 'immutable_datetime'];
}
