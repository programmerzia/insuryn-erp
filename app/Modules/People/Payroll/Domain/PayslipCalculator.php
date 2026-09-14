<?php

declare(strict_types=1);

namespace App\Modules\People\Payroll\Domain;

/**
 * One employee's month (design §B.10.2 calculation order): earnings → gross → provident fund → taxable income annualised over the tax year → tax from
 * the slab table → net. Pure and deterministic: every rate, band and amount comes in as data (payroll_settings, salary_structures, payroll_tax_slabs),
 * nothing Bangladeshi is written here. Integer minor units and basis points only.
 *
 * ASSUMPTION A-281: monthly tax deducted at source = (tax on the projected annual income) ÷ 12; the projection is this month's regular earnings × 12 plus
 * the year's festival bonuses and any taxable one-off inputs, less the exempt share (a fraction of income, capped). Investment rebate is LATER.
 * ASSUMPTION A-283 (CQ-F4 open): commission paid through payroll is shown and paid but not taxed again, because tax was withheld when it was earned.
 */
final class PayslipCalculator
{
    /**
     * @param array{basic_minor: int, employment_type: string, days_in_month: int, days_employed: int, service_months: int} $employment
     * @param array{house_rent_bp: int, medical_bp: int, medical_cap_minor: int|null, conveyance_minor: int} $structure
     * @param array{pf_employee_bp: int, pf_employer_bp: int, pf_employment_types: list<string>, festival_bonus_bp: int, festival_bonus_min_service_months: int,
     *     festivals_this_month: list<string>, festivals_in_year: int, tax_exempt_fraction_bp: int, tax_exempt_cap_minor: int, minimum_tax_minor: int, commission_taxable: bool} $settings
     * @param list<array{band_minor: int|null, rate_bp: int}> $slabs
     * @param list<array{component_code: string, label: string, amount_minor: int, pre_accrued: bool, taxable: bool}> $inputs
     * @return array{lines: list<array{component_code: string, kind: string, label: string, amount_minor: int, pre_accrued: bool}>, basic: int, regular: int, bonus: int,
     *     commission: int, gross: int, pf_employee: int, pf_employer: int, taxable_annual: int, tax: int, net: int, trace: list<array{step: string, value: int|string}>}
     */
    public static function calculate(array $employment, array $structure, array $settings, array $slabs, array $inputs): array
    {
        $trace = [];
        $prorate = fn (int $amount): int => $employment['days_employed'] >= $employment['days_in_month'] ? $amount
            : PayrollMath::prorate($amount, max(0, $employment['days_employed']), $employment['days_in_month']);
        if ($employment['days_employed'] < $employment['days_in_month']) {
            $trace[] = ['step' => 'Days employed in the month', 'value' => "{$employment['days_employed']} of {$employment['days_in_month']}"];
        }

        $basicFull = $employment['basic_minor'];
        $houseRentFull = PayrollMath::bp($basicFull, $structure['house_rent_bp']);
        $medicalFull = PayrollMath::bp($basicFull, $structure['medical_bp']);
        if ($structure['medical_cap_minor'] !== null) {
            $medicalFull = min($medicalFull, $structure['medical_cap_minor']);
        }
        $conveyanceFull = $structure['conveyance_minor'];

        $basic = $prorate($basicFull);
        $houseRent = $prorate($houseRentFull);
        $medical = $prorate($medicalFull);
        $conveyance = $prorate($conveyanceFull);
        $lines = [];
        $earn = function (string $code, string $label, int $amount, bool $preAccrued = false) use (&$lines): void {
            if ($amount !== 0) {
                $lines[] = ['component_code' => $code, 'kind' => 'earning', 'label' => $label, 'amount_minor' => $amount, 'pre_accrued' => $preAccrued];
            }
        };
        $earn('basic', 'Basic salary', $basic);
        $earn('house_rent', 'House rent allowance', $houseRent);
        $earn('medical', 'Medical allowance', $medical);
        $earn('conveyance', 'Conveyance allowance', $conveyance);
        $regular = $basic + $houseRent + $medical + $conveyance;
        $trace[] = ['step' => 'Basic', 'value' => $basic];
        $trace[] = ['step' => 'House rent '.self::percent($structure['house_rent_bp']).' of basic', 'value' => $houseRent];
        $trace[] = ['step' => 'Medical '.self::percent($structure['medical_bp']).' of basic'.($structure['medical_cap_minor'] !== null ? ' (capped)' : ''), 'value' => $medical];
        $trace[] = ['step' => 'Conveyance (fixed)', 'value' => $conveyance];

        $bonusEligible = $employment['service_months'] >= $settings['festival_bonus_min_service_months'];
        $bonus = 0;
        foreach ($settings['festivals_this_month'] as $festival) {
            if ($bonusEligible) {
                $amount = PayrollMath::bp($basicFull, $settings['festival_bonus_bp']);
                $earn('festival_bonus', "Festival bonus ({$festival})", $amount);
                $bonus += $amount;
                $trace[] = ['step' => "Festival bonus {$festival}: ".self::percent($settings['festival_bonus_bp']).' of basic', 'value' => $amount];
            } else {
                $trace[] = ['step' => "Festival bonus {$festival}: not yet eligible ({$employment['service_months']} months of service)", 'value' => 0];
            }
        }

        $commission = 0;
        $taxableInputs = 0;
        foreach ($inputs as $input) {
            $earn($input['component_code'], $input['label'], $input['amount_minor'], $input['pre_accrued']);
            if ($input['component_code'] === 'commission') {
                $commission += $input['amount_minor'];
            }
            $taxed = $input['component_code'] === 'commission' ? $settings['commission_taxable'] : $input['taxable'];
            if ($taxed) {
                $taxableInputs += $input['amount_minor'];
            }
            $trace[] = ['step' => $input['label'].($taxed ? '' : ' (not taxed again)'), 'value' => $input['amount_minor']];
        }
        $others = array_sum(array_map(fn (array $i): int => $i['component_code'] === 'commission' ? 0 : $i['amount_minor'], $inputs));
        $gross = $regular + $bonus + $commission + $others;
        $trace[] = ['step' => 'Gross', 'value' => $gross];

        $pfMember = in_array($employment['employment_type'], $settings['pf_employment_types'], true);
        $pfEmployee = $pfMember ? PayrollMath::bp($basic, $settings['pf_employee_bp']) : 0;
        $pfEmployer = $pfMember ? PayrollMath::bp($basic, $settings['pf_employer_bp']) : 0;
        $trace[] = ['step' => $pfMember ? 'Provident fund '.self::percent($settings['pf_employee_bp']).' of basic (employee), '.self::percent($settings['pf_employer_bp']).' (employer)' : 'Provident fund: not a member ('.$employment['employment_type'].')', 'value' => $pfEmployee];

        $annualRegular = ($basicFull + $houseRentFull + $medicalFull + $conveyanceFull) * 12;
        $annualBonus = $bonusEligible ? $settings['festivals_in_year'] * PayrollMath::bp($basicFull, $settings['festival_bonus_bp']) : 0;
        $annualIncome = $annualRegular + $annualBonus + $taxableInputs;
        $exempt = min(PayrollMath::bp($annualIncome, $settings['tax_exempt_fraction_bp']), $settings['tax_exempt_cap_minor']);
        $taxableAnnual = max(0, $annualIncome - $exempt);
        $slabTax = PayrollMath::slabTax($taxableAnnual, $slabs);
        $annualTax = $slabTax['tax'] > 0 ? max($slabTax['tax'], $settings['minimum_tax_minor']) : 0;
        $tax = PayrollMath::divide($annualTax, 12);
        $trace[] = ['step' => 'Projected annual income (regular × 12 + festival bonuses'.($taxableInputs > 0 ? ' + taxable inputs' : '').')', 'value' => $annualIncome];
        $trace[] = ['step' => 'Exempt: '.self::percent($settings['tax_exempt_fraction_bp']).' of income, capped', 'value' => $exempt];
        $trace[] = ['step' => 'Taxable annual income', 'value' => $taxableAnnual];
        foreach ($slabTax['bands'] as $band) {
            $trace[] = ['step' => 'Band '.self::percent($band['rate_bp']).' on '.$band['taxed'], 'value' => $band['tax']];
        }
        $trace[] = ['step' => 'Annual tax'.($annualTax !== $slabTax['tax'] ? ' (minimum tax)' : ''), 'value' => $annualTax];
        $trace[] = ['step' => 'Tax deducted this month (annual ÷ 12)', 'value' => $tax];

        $deductions = $tax + $pfEmployee;
        if ($deductions > $gross) { // never a negative net: tax gives way first
            $tax = max(0, $gross - $pfEmployee);
            $deductions = $tax + $pfEmployee;
        }
        if ($pfEmployee > 0) {
            $lines[] = ['component_code' => 'pf_employee', 'kind' => 'deduction', 'label' => 'Provident fund (employee)', 'amount_minor' => $pfEmployee, 'pre_accrued' => false];
        }
        if ($tax > 0) {
            $lines[] = ['component_code' => 'income_tax', 'kind' => 'deduction', 'label' => 'Income tax deducted at source', 'amount_minor' => $tax, 'pre_accrued' => false];
        }
        if ($pfEmployer > 0) {
            $lines[] = ['component_code' => 'pf_employer', 'kind' => 'employer_contribution', 'label' => 'Provident fund (employer)', 'amount_minor' => $pfEmployer, 'pre_accrued' => false];
        }
        $net = $gross - $deductions;
        $trace[] = ['step' => 'Net pay', 'value' => $net];

        return ['lines' => $lines, 'basic' => $basic, 'regular' => $regular + $others, 'bonus' => $bonus, 'commission' => $commission, 'gross' => $gross,
            'pf_employee' => $pfEmployee, 'pf_employer' => $pfEmployer, 'taxable_annual' => $taxableAnnual, 'tax' => $tax, 'net' => $net, 'trace' => $trace];
    }

    private static function percent(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = $basisPoints % 100;

        return $whole.($fraction === 0 ? '' : '.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT)).'%';
    }
}
