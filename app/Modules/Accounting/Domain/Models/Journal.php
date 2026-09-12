<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

final class Journal extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'journals';
    protected $guarded = [];
    protected $casts = ['transaction_date' => 'immutable_date', 'posting_date' => 'immutable_date', 'effective_date' => 'immutable_date', 'posted_at' => 'immutable_datetime', 'status' => \App\Modules\Accounting\Domain\Enums\JournalStatus::class, 'kind' => \App\Modules\Accounting\Domain\Enums\JournalKind::class];

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<JournalLine, $this> */
    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }
}
