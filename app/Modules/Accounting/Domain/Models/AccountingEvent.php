<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

final class AccountingEvent extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'accounting_events';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['payload' => 'array', 'dimensions' => 'array', 'occurred_at' => 'immutable_datetime', 'transaction_date' => 'immutable_date', 'effective_date' => 'immutable_date', 'created_at' => 'immutable_datetime', 'status' => \App\Modules\Accounting\Domain\Enums\EventStatus::class];
}
