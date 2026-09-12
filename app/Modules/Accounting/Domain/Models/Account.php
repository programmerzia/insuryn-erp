<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

final class Account extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'accounts';
    protected $guarded = [];
    protected $casts = ['type' => \App\Modules\Accounting\Domain\Enums\AccountType::class, 'normal_side' => \App\Modules\Accounting\Domain\Enums\Side::class, 'is_postable' => 'bool', 'is_control' => 'bool'];
}
