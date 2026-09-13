<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Models;

use App\Modules\Insurance\Rating\Domain\Enums\DutyBasis;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 design §1 duties (VAT, stamp duty, levies on premium). Recorded through DutyBook.
 *
 * @property string $id
 * @property string $code vat | stamp | levy
 * @property DutyBasis $basis
 * @property int|null $rate_bp
 * @property int|null $amount_minor
 * @property list<array{from: int, to: int|null, amount_minor: int}>|null $bands
 * @property list<string> $class_codes
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $label_en
 * @property string $label_bn
 * @property bool $verify placeholder value still to be confirmed (design OPEN 1)
 * @property string|null $source
 * @property string $created_by
 */
final class Duty extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'duties';
    protected $guarded = [];
    protected $casts = [
        'basis' => DutyBasis::class, 'rate_bp' => 'int', 'amount_minor' => 'int', 'bands' => 'array', 'class_codes' => 'array',
        'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date', 'verify' => 'bool',
    ];
}
