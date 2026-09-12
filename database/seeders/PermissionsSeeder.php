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
    ];

    /** Segregation-of-duties conflicts (design §7.3), seeded per tenant by TenantSeeder. */
    public const SOD = [
        ['receipt.refund_request', 'receipt.refund_release'],
        ['claim.pay_request', 'claim.pay_release'],
        ['claim.reserve', 'claim.approve'],
        ['accounting.create_manual_journal', 'accounting.approve_journal'],
        ['commission.approve', 'commission.pay'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $p) {
            DB::table('permissions')->updateOrInsert(['code' => $p], []);
        }
    }
}
