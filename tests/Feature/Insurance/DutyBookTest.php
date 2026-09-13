<?php

declare(strict_types=1);

use App\Modules\Insurance\Rating\Application\DutyBook;
use App\Modules\Insurance\Rating\Domain\Definition\DutyDefinition;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Phase 3 design §1 duties (slice R2, OPEN 1): effective-dated VAT, stamp duty and levies per product class, flagged "verify" until confirmed. */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->manager = userWithPermissions($this->ctx['tenant_id'], ['rating.manage_plans']);
    $this->duties = app(DutyBook::class);
});

/** @return list<string> "code:amount" of the duties in force for a class on a day, on a net premium of 100,000.00 and a sum insured of 5,000,000.00 */
function dutiesOn(string $class, string $day): array
{
    return array_map(fn (DutyDefinition $d): string => $d->code.':'.$d->amountFor(10_000_000, 500_000_000), app(DutyBook::class)->inForce($class, CarbonImmutable::parse($day)));
}

it('records duties, finds those in force for a class on a date, and refuses overlaps', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $vat = $this->duties->record(['code' => 'vat', 'basis' => 'pct_of_premium', 'rate_bp' => 1500, 'class_codes' => ['motor', 'fire', 'marine_cargo', 'misc'],
            'effective_from' => '2026-01-01', 'label_en' => 'VAT', 'label_bn' => 'মূসক', 'source' => 'placeholder_verify'], $this->manager);
        $this->duties->record(['code' => 'stamp', 'basis' => 'flat_per_policy', 'amount_minor' => 5_000, 'class_codes' => ['motor'], 'effective_from' => '2026-01-01',
            'label_en' => 'Stamp duty', 'label_bn' => 'স্ট্যাম্প শুল্ক'], $this->manager);
        $this->duties->record(['code' => 'stamp', 'basis' => 'per_sum_insured_band', 'bands' => [['from' => 0, 'to' => 100_000_000, 'amount_minor' => 10_000],
            ['from' => 100_000_000, 'to' => null, 'amount_minor' => 25_000]], 'class_codes' => ['fire'], 'effective_from' => '2026-01-01', 'label_en' => 'Stamp duty', 'label_bn' => 'স্ট্যাম্প শুল্ক'], $this->manager);

        expect(dutiesOn('motor', '2026-09-01'))->toBe(['stamp:5000', 'vat:1500000'])
            ->and(dutiesOn('fire', '2026-09-01'))->toBe(['stamp:25000', 'vat:1500000'])
            ->and(dutiesOn('marine_cargo', '2026-09-01'))->toBe(['vat:1500000'])
            ->and(dutiesOn('motor', '2025-12-31'))->toBe([])
            ->and($vat->verify)->toBeTrue()
            ->and($vat->source)->toBe('placeholder_verify')
            ->and(thrownBy(fn () => $this->duties->record(['code' => 'vat', 'basis' => 'pct_of_premium', 'rate_bp' => 1000, 'class_codes' => ['misc'], 'effective_from' => '2026-06-01',
                'label_en' => 'VAT', 'label_bn' => 'মূসক'], $this->manager), BusinessRuleViolation::class)->reasonCode)->toBe('DUTY_OVERLAP')
            ->and(thrownBy(fn () => $this->duties->record(['code' => 'vat', 'basis' => 'pct_of_premium', 'rate_bp' => 1000, 'class_codes' => ['aviation'], 'effective_from' => '2026-06-01',
                'label_en' => 'VAT', 'label_bn' => 'মূসক'], $this->manager), BusinessRuleViolation::class)->reasonCode)->toBe('PRODUCT_CLASS_UNKNOWN');

        // A new rate: end the old row, then record its successor.
        $this->duties->end($vat->id, '2027-07-01', $this->manager);
        $this->duties->record(['code' => 'vat', 'basis' => 'pct_of_premium', 'rate_bp' => 1000, 'class_codes' => ['motor', 'fire', 'marine_cargo', 'misc'],
            'effective_from' => '2027-07-01', 'label_en' => 'VAT', 'label_bn' => 'মূসক'], $this->manager);
        expect(dutiesOn('motor', '2027-06-30'))->toBe(['stamp:5000', 'vat:1500000'])
            ->and(dutiesOn('motor', '2027-07-01'))->toBe(['stamp:5000', 'vat:1000000'])
            ->and(thrownBy(fn () => $this->duties->end($vat->id, '2028-01-01', $this->manager), BusinessRuleViolation::class)->reasonCode)->toBe('DUTY_RANGE_INVALID')
            ->and(DB::table('audit_events')->where('object_type', 'duty')->count())->toBe(5)
            ->and(fn () => DB::table('duties')->where('id', $vat->id)->update(['amount_minor' => 5]))->toThrow(QueryException::class, 'duties_basis_value');
    });
});

it('needs rating.manage_plans to record duties', function (): void {
    $stranger = userWithPermissions($this->ctx['tenant_id'], ['product.manage']);
    asTenant($this->ctx['tenant_id'], fn () => expect(fn () => $this->duties->record(['code' => 'vat', 'basis' => 'pct_of_premium', 'rate_bp' => 1500, 'class_codes' => ['motor'],
        'effective_from' => '2026-01-01', 'label_en' => 'VAT', 'label_bn' => 'মূসক'], $stranger))->toThrow(PermissionDenied::class));
});
