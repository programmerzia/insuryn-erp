<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Application;

use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use App\Modules\Insurance\Reports\Application\PremiumRegisterQuery;
use Carbon\CarbonImmutable;

/**
 * Market gap G5, solvency snapshot for the Regulatory dashboard. ASSUMPTION A-269 (placeholder formula, verify with IDRA's solvency rules): available capital =
 * total assets − total liabilities from the ledger; required capital = the greatest of the minimum paid-up capital, a factor of net written premium over the
 * last four quarters and a factor of claims incurred over the same months.
 */
final class SolvencySnapshot
{
    public function __construct(
        private readonly FinancialStatementsQuery $statements,
        private readonly PremiumRegisterQuery $premiums,
    ) {}

    /**
     * @return array{as_of: string, available_minor: int, required_minor: int, minimum_capital_minor: int, premium_basis_minor: int, claims_basis_minor: int,
     *     premium_component_minor: int, claims_component_minor: int, ratio_bp: int, meets: bool}
     */
    public function at(string $entityId, CarbonImmutable $asOf): array
    {
        $balance = $this->statements->balanceSheet($entityId, $asOf);
        $available = $balance['total_assets_minor'] - $balance['total_liabilities_minor'];
        $from = $asOf->subYear()->addDay();
        $premium = array_sum(array_column($this->premiums->register($entityId, $from, $asOf)['rows'], 'net_minor'));
        $claims = 0;
        foreach (['claims_expense', 'claims_ibnr_expense'] as $role) {
            $claims += array_sum($this->statements->roleMovementByDimension($entityId, $role, $from, $asOf, 'branch')['by_dimension']);
        }
        $minimum = (int) config('erp.regulatory.solvency.minimum_capital_minor', 0);
        $premiumComponent = intdiv(max(0, $premium) * (int) config('erp.regulatory.solvency.premium_factor_bp', 0), 10_000);
        $claimsComponent = intdiv(max(0, $claims) * (int) config('erp.regulatory.solvency.claims_factor_bp', 0), 10_000);
        $required = max($minimum, $premiumComponent, $claimsComponent);

        return ['as_of' => $asOf->toDateString(), 'available_minor' => $available, 'required_minor' => $required, 'minimum_capital_minor' => $minimum,
            'premium_basis_minor' => $premium, 'claims_basis_minor' => $claims, 'premium_component_minor' => $premiumComponent, 'claims_component_minor' => $claimsComponent,
            'ratio_bp' => $required > 0 ? intdiv($available * 10_000, $required) : 0, 'meets' => $available >= $required];
    }
}
