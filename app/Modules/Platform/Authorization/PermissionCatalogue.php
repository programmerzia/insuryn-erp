<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

/**
 * Gap fix GA-22: what each permission grants, in words an administrator reads before ticking it (and a refused user reads on the access page, GA-07).
 * Group labels and one line of help per design §7.1 code; a code missing here still shows, with its action words as the label and no help.
 */
final class PermissionCatalogue
{
    /** Context prefix → group label, in the order groups are shown. */
    public const GROUPS = [
        'quotation' => 'Quotes', 'underwriting' => 'Underwriting', 'cover_note' => 'Cover notes', 'policy' => 'Policies', 'renewal' => 'Renewals',
        'receipt' => 'Receipts and refunds', 'claim' => 'Claims', 'commission' => 'Commission', 'agent' => 'Agents and producers', 'party' => 'Customers',
        'product' => 'Products', 'rating' => 'Tariffs', 'ri' => 'Reinsurance', 'document' => 'Documents', 'bank' => 'Bank', 'ap' => 'Payables', 'fa' => 'Fixed assets',
        'budget' => 'Budgets', 'pettycash' => 'Petty cash', 'hr' => 'HR', 'payroll' => 'Payroll', 'accounting' => 'Accounting', 'periods' => 'Periods and close',
        'numbering' => 'Numbering', 'reports' => 'Reports', 'regulatory' => 'Regulatory returns', 'provisions' => 'Technical provisions', 'audit' => 'Audit', 'platform' => 'Administration',
    ];

    /** @var array<string, array{0: string, 1: string}> code → [label, one-line help] */
    private const PERMISSIONS = [
        'accounting.view_journals' => ['View journals', 'Open journals, their lines and the accounting events behind them.'],
        'accounting.create_manual_journal' => ['Prepare manual journals', 'Draft and submit manual journals for someone else to approve.'],
        'accounting.approve_journal' => ['Approve manual journals', 'Approve or reject journals prepared by someone else; approving posts them.'],
        'accounting.reverse_journal' => ['Reverse journals', 'Ask for or approve the reversal of a posted journal.'],
        'accounting.post_to_control' => ['Adjust control accounts', 'Post adjustments straight to control accounts such as premium receivable.'],
        'accounting.post_in_soft_locked' => ['Post into a soft-locked month', 'Post into a month that is being closed.'],
        'accounting.requeue_event' => ['Requeue accounting events', 'Send an accounting event that did not post back to posting once its cause is fixed.'],
        'accounting.manage_coa' => ['Maintain the chart of accounts', 'Import and add accounts, and map account roles to accounts.'],
        'accounting.manage_posting_rules' => ['Maintain posting rules', 'Change how business events become journal lines.'],
        'periods.soft_lock' => ['Soft-lock months', 'Start the month-end close and soft-lock the month.'],
        'periods.lock' => ['Lock months', 'Lock a closed month so nothing more posts into it; open the fiscal year.'],
        'periods.reopen' => ['Reopen months', 'Reopen a locked month (goes for approval).'],
        'policy.create' => ['Quote policies', 'Create policy quotes for products without a tariff.'],
        'policy.issue' => ['Issue policies', 'Issue policies and reinstate lapsed ones.'],
        'policy.endorse' => ['Endorse policies', 'Change an issued policy and its premium.'],
        'policy.cancel' => ['Cancel and lapse policies', 'Cancel or lapse a policy; the unearned premium is credited or refunded.'],
        'receipt.create' => ['Record receipts', 'Record money received; without allocating it, it is held in suspense.'],
        'receipt.allocate' => ['Allocate receipts', 'Allocate received money to installments, and mark cheques bounced.'],
        'receipt.refund_request' => ['Request refunds', 'Ask for the refund of money owed after a cancellation; someone else pays it.'],
        'receipt.refund_release' => ['Pay refunds', 'Pay or reject a refund someone else requested.'],
        'receipt.write_off_request' => ['Request premium write-offs', 'Ask to write off the small premium a cancelled policy still owes; someone else approves it.'],
        'receipt.write_off_approve' => ['Approve premium write-offs', 'Approve or reject the write-off of a cancelled policy\'s small unpaid premium someone else requested.'],
        'claim.register' => ['Register claims', 'Register a claim against a policy.'],
        'claim.reserve' => ['Set claim reserves', 'Set and change what a claim is expected to cost.'],
        'claim.approve' => ['Approve claim payments', 'Approve a payment on a claim someone else reserved, within limits.'],
        'claim.pay_request' => ['Request claim payments', 'Send an approved claim payment to finance.'],
        'claim.pay_release' => ['Pay claims', 'Pay a claim payment someone else requested.'],
        'claim.close' => ['Close, reject and reopen claims', 'Close or reject a claim, record recoveries, and reopen closed claims.'],
        'commission.approve' => ['Approve commission', 'Approve commission statements and producer advances.'],
        'commission.pay' => ['Pay commission', 'Pay approved commission statements and advances.'],
        'commission.manage_plans' => ['Maintain commission plans', 'Set commission plans and compensation schemes.'],
        'bank.match' => ['Match bank statements', 'Match statement lines to the ledger and explain the rest.'],
        'bank.import' => ['Import bank statements', 'Upload bank statement files.'],
        'bank.manage_accounts' => ['Maintain bank accounts', 'Add the company\'s bank accounts and their ledger accounts.'],
        'ap.manage_suppliers' => ['Maintain suppliers', 'Add suppliers with their payment terms, tax profile and bank account.'],
        'ap.enter_bills' => ['Enter supplier bills', 'Enter supplier bills and send them for approval.'],
        'ap.approve_bills' => ['Approve supplier bills', 'Approve or reject bills someone else entered; approving posts them.'],
        'ap.prepare_payments' => ['Prepare payment runs', 'Choose due supplier bills to pay from a bank account.'],
        'ap.approve_payments' => ['Approve payment runs', 'Approve a payment run someone else prepared.'],
        'ap.release_payments' => ['Release payment runs', 'Release an approved run to the bank and download its payment file.'],
        'numbering.void' => ['Void document numbers', 'Void a reserved document number that will not be used.'],
        'platform.manage_users' => ['Manage users', 'Invite, deactivate and give roles to users.'],
        'platform.manage_roles' => ['Manage roles', 'Create roles and choose their permissions.'],
        'platform.manage_approvals' => ['Set approval limits', 'Choose which amounts need which approvers.'],
        'audit.view' => ['View the audit trail', 'See who did what and when.'],
        'reports.financial' => ['Read financial reports', 'Open the trial balance, close checklist, reports and business records, read-only.'],
        'reports.regulatory' => ['File regulatory reports', 'Download the IDRA registers and regulatory exports.'],
        'reports.claims' => ['Read claims reports', 'Open outstanding claims, claims paid and loss ratio, read-only.'],
        // Market gap G5.
        'regulatory.file' => ['Mark returns filed', 'Record that a regulatory return was filed with IDRA, with the filing date and reference.'],
        'provisions.run' => ['Prepare technical provisions', 'Calculate the quarterly IBNR provision, choose the method per class and mark the run reviewed.'],
        'provisions.approve' => ['Approve technical provisions', 'Approve and post a technical provisions run someone else prepared.'],
        'party.manage' => ['Maintain customers', 'Create customers and add their bank accounts.'],
        'agent.manage' => ['Maintain producers', 'Create agents and producers, licences and the hierarchy.'],
        'product.manage' => ['Maintain products', 'Create products and their versions.'],
        'rating.manage_plans' => ['Draft tariffs', 'Draft rating plans and duties.'],
        'rating.approve_plans' => ['Approve tariffs', 'Approve, activate and retire rating plans someone else drafted.'],
        'document.generate' => ['Print documents', 'Print schedules, receipts, quotations and cover notes.'],
        'document.manage_templates' => ['Edit document templates', 'Edit and activate the templates documents print with.'],
        'quotation.create' => ['Quote and prepare proposals', 'Rate and issue quotations, make proposals and record KYC.'],
        'underwriting.decide' => ['Decide referrals', 'Approve or decline referred proposals within your underwriting limit.'],
        'underwriting.manage_limits' => ['Set underwriting limits', 'Choose each role\'s largest sum insured per class.'],
        'cover_note.issue' => ['Issue cover notes', 'Issue temporary cover for an approved proposal.'],
        'cover_note.cancel' => ['Cancel cover notes', 'Cancel a cover note.'],
        'renewal.manage' => ['Work renewals', 'Offer renewal quotations and record why a policy was not renewed.'],
        // Reinsurance MVP (G4).
        'ri.manage_treaties' => ['Set up reinsurance treaties', 'Add reinsurers, set up treaties and their shares, and prepare reinsurer statements.'],
        'ri.place_facultative' => ['Place facultative reinsurance', 'Place a share of a policy with a reinsurer on its own slip; the placement posts the ceded premium.'],
        'ri.view' => ['See reinsurance', 'See cessions, the reinsurance of each policy and reinsurer statements.'],
        // Design addendum v2 §B.7 fixed assets (A-276)
        'fa.manage' => ['Maintain the fixed asset register', 'Set asset classes, capitalise assets, move them between branches and dispose of them.'],
        'fa.post_depreciation' => ['Post depreciation', 'Post the monthly depreciation of the fixed asset register.'],
        // Design addendum v2 §B.8.1 budgets (A-277)
        'budget.prepare' => ['Prepare budgets', 'Enter, paste or copy budget amounts and send a budget version for approval.'],
        'budget.approve' => ['Approve budgets', 'Approve or return a budget version someone else prepared.'],
        // Design addendum v2 §B.6 petty cash (A-278)
        'pettycash.spend' => ['Pay petty cash vouchers', 'Pay small expenses from a branch petty cash float, with the receipt.'],
        'pettycash.replenish' => ['Replenish and count petty cash', 'Ask for a float to be topped up and count a float held by someone else.'],
        'pettycash.approve' => ['Approve petty cash', 'Set up floats and approve replenishments someone else asked for.'],
    ];

    public static function groupLabel(string $code): string
    {
        $context = explode('.', $code, 2)[0];

        return self::GROUPS[$context] ?? ucfirst(str_replace('_', ' ', $context));
    }

    public static function label(string $code): string
    {
        $action = explode('.', $code, 2)[1] ?? $code;

        return self::PERMISSIONS[$code][0] ?? ucfirst(str_replace('_', ' ', $action));
    }

    public static function help(string $code): ?string
    {
        return self::PERMISSIONS[$code][1] ?? null;
    }

    /**
     * The given codes grouped for display: groups in GROUPS order (unknown contexts last), permissions in code order.
     *
     * @param list<string> $codes
     * @return list<array{label: string, permissions: list<array{code: string, label: string, help: string|null}>}>
     */
    public static function grouped(array $codes): array
    {
        $order = array_flip(array_keys(self::GROUPS));
        sort($codes);
        $groups = [];
        foreach ($codes as $code) {
            $context = explode('.', $code, 2)[0];
            $groups[$context][] = ['code' => $code, 'label' => self::label($code), 'help' => self::help($code)];
        }
        uksort($groups, fn (string $a, string $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return array_map(fn (string $context, array $permissions): array => ['label' => self::GROUPS[$context] ?? ucfirst(str_replace('_', ' ', $context)), 'permissions' => $permissions],
            array_keys($groups), $groups);
    }
}
