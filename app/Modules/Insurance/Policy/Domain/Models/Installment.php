<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain\Models;

use App\Modules\Insurance\Policy\Domain\Enums\InstallmentStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Design §2.4 installments. Outstanding = amount − paid − cancelled (cancelled = receivable credited on cancellation
 * or a premium decrease).
 *
 * @property string $id
 * @property string $policy_id
 * @property int $no
 * @property CarbonImmutable $due_date
 * @property int $amount_minor
 * @property int $paid_minor
 * @property int $cancelled_minor
 * @property InstallmentStatus $status
 */
final class Installment extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'installments';
    protected $guarded = [];
    protected $casts = ['status' => InstallmentStatus::class, 'due_date' => 'immutable_date', 'no' => 'int',
        'amount_minor' => 'int', 'paid_minor' => 'int', 'cancelled_minor' => 'int'];

    public function outstanding(): int
    {
        return $this->amount_minor - $this->paid_minor - $this->cancelled_minor;
    }
}
