<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

final class JournalLine extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'journal_lines';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['amount_minor' => 'int', 'base_amount_minor' => 'int', 'side' => \App\Modules\Accounting\Domain\Enums\Side::class, 'dims_ext' => 'array'];
}
