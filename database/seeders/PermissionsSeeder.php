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
        // Phase 3 rating (slice R2): draft plans and duties / approve, activate and retire plans (A-69)
        'rating.manage_plans','rating.approve_plans',
        // Phase 3 documents (slice R8): generate printable documents / edit and activate templates (A-101)
        'document.generate','document.manage_templates',
        // Phase 3 quotations (slice R4, A-83)
        'quotation.create',
        // Phase 3 underwriting (slice R5, A-86, A-87)
        'underwriting.decide','underwriting.manage_limits',
        // Phase 3 cover notes (slice R6, A-94)
        'cover_note.issue','cover_note.cancel',
        // Phase 3 renewals (slice R9, A-126)
        'renewal.manage',
        // Gap fix GA-12 (A-175): the claims reports for the claims desk
        'reports.claims',
        // Gap fixes W7 (GA-24, A-232): writing off a cancelled policy's small unpaid premium, requested by one person and approved by another
        'receipt.write_off_request', 'receipt.write_off_approve',
        // Market gap G5 (A-268): mark regulatory returns filed; prepare / approve the quarterly technical provisions run
        'regulatory.file','provisions.run','provisions.approve',
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
        ['rating.manage_plans', 'rating.approve_plans', 'object'], // slice R2: whoever drafted or edited a rating plan does not approve it
        ['quotation.create', 'underwriting.decide', 'object'], // slice R5: whoever prepared a proposal does not decide its referral
        ['provisions.run', 'provisions.approve', 'object'], // market gap G5: whoever prepared a technical provisions run does not approve and post it
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $p) {
            DB::table('permissions')->updateOrInsert(['code' => $p], []);
        }
    }
}
