<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Models;

use App\Modules\Insurance\Product\Domain\Enums\EarningMethod;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $product_id
 * @property int $version
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property int $term_months
 * @property EarningMethod $earning_method
 * @property array<int, mixed>|null $short_rate_table
 * @property array{tax_type: string|null, jurisdiction: string|null, inclusive: bool, refund_tax_on_cancellation: bool} $tax_profile
 * @property string|null $commission_plan_id
 * @property string|null $posting_rule_set
 * @property list<array<string, mixed>> $coverages
 */
final class ProductVersion extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'product_versions';
    protected $guarded = [];
    protected $casts = [
        'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date', 'term_months' => 'int', 'version' => 'int',
        'earning_method' => EarningMethod::class, 'short_rate_table' => 'array', 'tax_profile' => 'array', 'coverages' => 'array',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function refundsTaxOnCancellation(): bool
    {
        return (bool) ($this->tax_profile['refund_tax_on_cancellation'] ?? true);
    }
}
