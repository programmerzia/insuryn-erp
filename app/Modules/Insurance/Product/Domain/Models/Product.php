<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $lob
 * @property string $status
 */
final class Product extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'products';
    protected $guarded = [];

    /** @return HasMany<ProductVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class)->orderBy('version');
    }
}
