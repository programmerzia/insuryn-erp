<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

final class FiscalPeriod extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'fiscal_periods';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['starts' => 'date', 'ends' => 'date', 'status' => \App\Modules\Accounting\Domain\Enums\PeriodStatus::class];
}
