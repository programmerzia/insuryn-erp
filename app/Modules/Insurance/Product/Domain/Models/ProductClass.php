<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 design §1 product_classes: global catalogue (no tenant, D-18).
 *
 * @property string $code
 * @property string $name_en
 * @property string $name_bn
 * @property string $insurance_class life | non_life
 * @property string $status active | later
 * @property int $sort_order
 */
final class ProductClass extends Model
{
    protected $table = 'product_classes';
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['sort_order' => 'int'];
}
