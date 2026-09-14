<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §7.2 role templates, seeded per tenant and editable afterwards. "+" in the design means "the
 * previous role's permissions plus"; §7.1 permission codes. Tenant Admin has no financial permissions.
 */
final class RoleTemplates
{
    public const AUDITOR = 'auditor';

    /** Permissions an auditor may hold: read-only (§7.3 "Auditor role can never be combined with write permissions"). */
    public const READ_ONLY_PERMISSIONS = ['accounting.view_journals', 'audit.view', 'reports.financial', 'reports.regulatory'];

    private const BRANCH_OFFICER = ['policy.create', 'policy.issue', 'receipt.create', 'party.manage',
        // ASSUMPTION A-101 (slice R8): whoever quotes, issues and records receipts at the branch prints the schedule, endorsement and receipt for the customer.
        'document.generate',
        // ASSUMPTION A-83 (slice R4): whoever quotes policies today rates and issues quotations (branch officer, and the branch manager through "+").
        'quotation.create',
        // ASSUMPTION A-94 (slice R6): the officer who prepares a proposal issues its cover note.
        'cover_note.issue',
        // ASSUMPTION A-126 (slice R9): the branch that sold and quotes a policy works its expiry register (branch officer, and the branch manager through "+").
        'renewal.manage'];
    private const CLAIMS_OFFICER = ['claim.register', 'claim.reserve'];
    private const ACCOUNTANT = ['accounting.view_journals', 'accounting.create_manual_journal', 'bank.match', 'bank.import', 'receipt.allocate'];
    private const FINANCE_MANAGER_EXTRA = ['accounting.approve_journal', 'accounting.reverse_journal', 'periods.soft_lock', 'periods.lock',
        'commission.approve', 'claim.pay_release', 'receipt.refund_release', 'product.manage', 'bank.manage_accounts', 'commission.manage_plans',
        // Interpretation: the finance manager signs off financial statements in the close (§5.7 tasks 14–15), so holds reports.financial.
        'reports.financial',
        // Interpretation (A-28, session S1): no template could maintain the chart of accounts; the finance manager owns it (setup wizard, COA import).
        'accounting.manage_coa',
        // ASSUMPTION A-69 (slice R2): no §7.2 template covers tariffs; the finance manager (and CFO) draft and approve rating plans — never the same plan (SoD object rule).
        'rating.manage_plans', 'rating.approve_plans',
        // ASSUMPTION A-86 (slice R5): no §7.2 template decides underwriting referrals; the finance manager (and CFO) do, within their underwriting limits.
        'underwriting.decide',
        // Fix G3 (flow audit, Part A step 14): the finance manager (and CFO) file the regulatory returns, such as the IDRA agency register.
        'reports.regulatory'];
    private const CFO_EXTRA = ['periods.reopen', 'accounting.post_to_control'];

    /** @return array<string, array{name: string, permissions: list<string>}> */
    public static function all(): array
    {
        $financeManager = [...self::ACCOUNTANT, ...self::FINANCE_MANAGER_EXTRA];

        return [
            'branch_officer' => ['name' => 'Branch Officer', 'permissions' => self::BRANCH_OFFICER],
            'branch_manager' => ['name' => 'Branch Manager', 'permissions' => [...self::BRANCH_OFFICER, 'policy.cancel', 'receipt.allocate', 'claim.register', 'agent.manage',
                // ASSUMPTION A-86 (slice R5): the branch manager decides underwriting referrals within their limit, never on a proposal they prepared (SoD).
                'underwriting.decide',
                // ASSUMPTION A-94 (slice R6): cancelling evidence of cover is a branch manager's decision.
                'cover_note.cancel',
                // ASSUMPTION A-137 (fix G3): §7.2 gives no role policy.endorse; changing an issued policy is a branch manager's decision (not the officer's).
                'policy.endorse']],
            'claims_officer' => ['name' => 'Claims Officer', 'permissions' => self::CLAIMS_OFFICER],
            'claims_manager' => ['name' => 'Claims Manager', 'permissions' => [...self::CLAIMS_OFFICER, 'claim.approve', 'claim.pay_request', 'claim.close']],
            'accountant' => ['name' => 'Accountant', 'permissions' => [...self::ACCOUNTANT,
                // ASSUMPTION A-138 (fix G3): §7.2 gives no role commission.pay; the accountant pays the statement the finance manager approved. Not in ACCOUNTANT, so the
                // finance manager and CFO (built on it) never hold both sides of commission.approve ✕ commission.pay.
                'commission.pay']],
            'finance_manager' => ['name' => 'Finance Manager', 'permissions' => $financeManager],
            'cfo' => ['name' => 'CFO', 'permissions' => [...$financeManager, ...self::CFO_EXTRA]],
            self::AUDITOR => ['name' => 'Auditor', 'permissions' => self::READ_ONLY_PERMISSIONS],
            // Interpretation (A-54, fix F3): approval limits are platform configuration (platform.*), not a financial permission.
            'tenant_admin' => ['name' => 'Tenant Admin', 'permissions' => ['platform.manage_users', 'platform.manage_roles', 'platform.manage_approvals',
                // ASSUMPTION A-101 (slice R8): document templates are tenant configuration (document.*, not accounting.*), so the §7.3 rule platform.manage_roles ✕ accounting.* is untouched.
                'document.manage_templates',
                // ASSUMPTION A-87 (slice R5): underwriting limits are configuration like approval limits.
                'underwriting.manage_limits']],
        ];
    }

    /** Creates the template roles in the current tenant; roles and permissions that already exist are left as they are (safe to rerun). */
    public static function seedCurrentTenant(): void
    {
        $tenantId = TenantContext::id();
        foreach (self::all() as $code => $template) {
            DB::table('roles')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $template['name'], 'created_at' => now(), 'updated_at' => now()]);
            $roleId = (string) DB::table('roles')->where('code', $code)->value('id');
            DB::table('role_permissions')->insertOrIgnore(array_map(
                fn (string $permission): array => ['tenant_id' => $tenantId, 'role_id' => $roleId, 'permission_code' => $permission],
                array_values(array_unique($template['permissions'])),
            ));
        }
    }
}
