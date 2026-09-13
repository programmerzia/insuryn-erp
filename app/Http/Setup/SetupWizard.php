<?php

declare(strict_types=1);

namespace App\Http\Setup;

use App\Modules\Accounting\Application\Setup\FiscalYearSetup;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Setup\CompanySetup;
use App\Modules\Platform\Setup\SetupProgress;
use Illuminate\Support\Facades\DB;

/**
 * The setup wizard's steps (session S1) and who may do each — the permission that owns the data, so segregation of duties holds during
 * setup — plus the first sign-in rule: a tenant without products that nobody has finished setting up opens the wizard for anyone who can
 * do a step.
 */
final class SetupWizard
{
    /** step => [label, permission, the role template that holds it] */
    public const STEPS = [
        'company' => ['Company and branches', CompanySetup::PERMISSION, 'Tenant Admin'],
        'fiscal_year' => ['Fiscal year and currency', FiscalYearSetup::PERMISSION, 'Finance Manager'],
        'chart_of_accounts' => ['Chart of accounts', 'accounting.manage_coa', 'Finance Manager'],
        'product' => ['First product', 'product.manage', 'Finance Manager'],
        'users' => ['Users and roles', 'platform.manage_users', 'Tenant Admin'],
        'done' => ['Done', null, null],
    ];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SetupProgress $progress,
    ) {}

    public function canUse(string $userId): bool
    {
        foreach (self::STEPS as [, $permission]) {
            if ($permission !== null && $this->permissions->has($userId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function shouldOpenFor(string $userId): bool
    {
        return ! DB::table('products')->exists() && ! $this->progress->isFinished() && $this->canUse($userId);
    }

    /** @return list<array{id: string, label: string, done: bool, allowed: bool, owner: string|null}> */
    public function steps(string $userId): array
    {
        $completed = $this->progress->completed();
        $steps = [];
        foreach (self::STEPS as $id => [$label, $permission, $owner]) {
            $steps[] = ['id' => $id, 'label' => $label, 'done' => in_array($id, $completed, true), 'allowed' => $permission === null || $this->permissions->has($userId, $permission), 'owner' => $owner];
        }

        return $steps;
    }

    /** The step after $step; `done` is last. */
    public static function next(string $step): string
    {
        $ids = array_keys(self::STEPS);
        $index = array_search($step, $ids, true);

        return $ids[min(($index === false ? 0 : $index) + 1, count($ids) - 1)];
    }
}
