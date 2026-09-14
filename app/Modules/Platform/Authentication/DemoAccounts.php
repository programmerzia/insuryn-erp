<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authentication;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Local convenience for the sign-in page: the resolved tenant's seeded accounts (AdminUserSeeder, DemoBusinessSeeder — `*.local` emails)
 * with the seed password, so a developer fills the form in one click. Returns null outside the local environment so the prop never ships.
 */
final class DemoAccounts
{
    /** One line per role template (RoleTemplates codes): what the demo shows when signed in as it. */
    private const DESCRIPTIONS = [
        'branch_officer' => 'quotes, proposals, policies and receipts at the branch',
        'branch_manager' => 'approves referrals, records receipts, keeps producers and targets',
        'claims_officer' => 'registers claims and sets reserves',
        'claims_manager' => 'approves claim payments and closes claims',
        'accountant' => 'bank, suppliers, bills, payment runs, fixed assets, budget, salary release',
        'finance_manager' => 'approvals, reinsurance, month-end close, reports',
        'cfo' => 'releases payments, regulatory returns and technical provisions',
        'hr_manager' => 'hires employees, keeps payroll rules and calculates the monthly payroll',
        'auditor' => 'reads everything, changes nothing',
        'tenant_admin' => 'users, roles, approval and underwriting limits, setup',
    ];

    /**
     * @return list<array{email: string, name: string, roles: list<string>, description: string, password: string}>|null
     */
    public static function forSignIn(): ?array
    {
        if (! app()->environment('local')) {
            return null;
        }
        if (! TenantContext::has()) {
            return [];
        }

        $rows = DB::table('users')->leftJoin('user_roles', 'user_roles.user_id', '=', 'users.id')->leftJoin('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('users.status', 'active')->where('users.email', 'like', '%.local')
            ->orderBy('users.email')->orderBy('roles.name')->get(['users.email', 'users.name', 'roles.name as role', 'roles.code as code']);
        $password = (string) config('erp.seed.admin_password');

        $accounts = [];
        $codes = [];
        foreach ($rows as $row) {
            $email = (string) $row->email;
            $accounts[$email] ??= ['email' => $email, 'name' => (string) $row->name, 'roles' => [], 'description' => '', 'password' => $password];
            $codes[$email] ??= [];
            if ($row->role !== null && ! in_array((string) $row->role, $accounts[$email]['roles'], true)) {
                $accounts[$email]['roles'][] = (string) $row->role;
                $codes[$email][] = (string) $row->code;
            }
        }
        foreach ($accounts as $email => $account) {
            $parts = array_values(array_filter(array_map(static fn (string $code): ?string => self::DESCRIPTIONS[$code] ?? null, $codes[$email])));
            $accounts[$email]['description'] = $parts === [] ? '' : ucfirst(implode('; ', $parts)).'.';
        }

        return array_values($accounts);
    }
}
