<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Design §7.1. Global catalogue; roles map to these per tenant. */
final class PermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'accounting.view_journals','accounting.create_manual_journal','accounting.approve_journal','accounting.reverse_journal',
        'accounting.post_to_control','accounting.post_in_soft_locked','accounting.requeue_event','accounting.manage_coa','accounting.manage_posting_rules',
        'periods.soft_lock','periods.lock','periods.reopen',
        'policy.create','policy.issue','policy.endorse','policy.cancel',
        'receipt.create','receipt.allocate','receipt.refund_request','receipt.refund_release',
        'claim.register','claim.reserve','claim.approve','claim.pay_request','claim.pay_release',
        'commission.approve','commission.pay','bank.match','bank.import','numbering.void',
        'platform.manage_users','platform.manage_roles','audit.view','reports.financial','reports.regulatory',
        // Catalogue extensions for Phase 1A/1B configuration and CRUD (docs/PROGRESS.md "Catalogue extensions")
        'party.manage','agent.manage','product.manage','bank.manage_accounts','commission.manage_plans','claim.close',
        // Fix F3 (A-54): approval limits
        'platform.manage_approvals',
    ];

    /**
     * Segregation-of-duties conflicts (design §7.3), seeded per tenant by DemoTenantSeeder:
     * [permission_a, permission_b, applies_to]. `object` = never both on the same object ("same claim").
     */
    public const SOD = [
        ['receipt.refund_request', 'receipt.refund_release', 'user'],
        ['claim.pay_request', 'claim.pay_release', 'user'],
        ['claim.reserve', 'claim.approve', 'object'],
        ['accounting.create_manual_journal', 'accounting.approve_journal', 'object'],
        ['commission.approve', 'commission.pay', 'user'],
        ['platform.manage_roles', 'accounting.*', 'user'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $p) {
            DB::table('permissions')->updateOrInsert(['code' => $p], []);
        }
    }
}
