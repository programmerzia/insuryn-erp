<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

final class Book extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'books';
    protected $guarded = [];
    public $timestamps = false;
}
