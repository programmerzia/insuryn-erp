<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Models;

use App\Modules\Insurance\Product\Domain\DutyProfile;
use App\Modules\Insurance\Product\Domain\Enums\EarningMethod;
use App\Modules\Insurance\Product\Domain\Enums\PremiumRecognition;
use App\Modules\Insurance\Product\Domain\Risk\RiskSchema;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property string|null $compensation_scheme_id Distribution scheme its policies are paid under (slice D4)
 * @property string|null $posting_rule_set
 * @property list<array<string, mixed>> $coverages Phase 1 coverage list, kept for compatibility; Phase 3 coverages are rows in `coverages` (D-19)
 * @property string|null $class_code product_classes.code (slice R1)
 * @property list<array<string, mixed>>|null $risk_schema see RiskSchema
 * @property array<string, mixed>|null $duty_profile see DutyProfile
 * @property string|null $document_set_id documents, Phase 3 R8
 * @property bool $allow_short_period
 * @property int|null $min_premium_minor product minimum premium (applied with the plan's minimum, the higher wins, A-67)
 * @property PremiumRecognition $recognise_at OPEN 3, A-65
 * @property bool $allow_credit_issue OPEN 4, A-65
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
        'risk_schema' => 'array', 'duty_profile' => 'array', 'allow_short_period' => 'bool', 'min_premium_minor' => 'int', 'recognise_at' => PremiumRecognition::class,
        'allow_credit_issue' => 'bool',
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

    /** @return HasMany<Coverage, $this> */
    public function coverageDefinitions(): HasMany
    {
        return $this->hasMany(Coverage::class)->orderBy('sort_order')->orderBy('code');
    }

    public function riskSchema(): RiskSchema
    {
        return $this->risk_schema === null ? RiskSchema::empty() : RiskSchema::fromArray($this->risk_schema);
    }

    public function dutyProfile(): DutyProfile
    {
        return DutyProfile::fromArray($this->duty_profile);
    }
}
