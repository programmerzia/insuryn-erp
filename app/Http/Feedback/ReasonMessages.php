<?php

declare(strict_types=1);

namespace App\Http\Feedback;

use App\Modules\Platform\Money\MinorUnits;

/**
 * Refusals written for people (UX brief §4 "Errors say what happened and what to do", "minor units never shown"): the domain's reason code and
 * message become a sentence with amounts in major units and no record ids. Used for browser forms and journal previews; the JSON API keeps the
 * domain's own message. Unknown reasons fall back to the domain message with ids removed.
 */
final class ReasonMessages
{
    /** @var array<string, string> reasons whose message needs no detail from the domain */
    private const FIXED = [
        'ALREADY_MATCHED' => 'This statement line is already matched or explained. Refresh the page to see its current state.',
        'JOURNAL_LINE_ALREADY_MATCHED' => 'One of the ledger lines is already matched to another statement line. Refresh and choose again.',
        'JOURNAL_LINE_NOT_IN_BANK_ACCOUNT' => 'Choose ledger lines posted to this bank account.',
        'SUSPENSE_NOT_OPEN' => 'Nothing is left in suspense for this receipt.',
        'INVALID_PAYMENT_TRANSITION' => 'This payment has already moved on. Refresh the page to see where it stands.',
        'REFUND_NOT_REQUESTED' => 'This refund has already been decided. Refresh the page.',
        'REOPEN_PENDING' => 'Reopening this claim is already waiting for approval. It reopens once the approver accepts it.',
        'POLICY_NOT_ON_COVER' =>'This policy was never issued, so it has no cover to claim against.',
        'PERIOD_MISSING' => 'No fiscal period covers that date. Choose a date inside the fiscal year that is set up.',
        'CLOSE_ALREADY_RUNNING' => 'A close is already running for this period. Open it from the close list.',
        'CLOSE_TASK_MISSING' => 'That close task no longer exists. Refresh the page.',
        'INVALID_BANK_ACCOUNT' => 'Choose an active bank account of this entity in the same currency.',
        'UNKNOWN_AGENT' => 'Choose an active agent.',
        'UNKNOWN_POLICY' => 'The receipt was taken for a policy that cannot receive premium. Open the receipt from an issued policy.',
        'UNKNOWN_PAYER' => 'Every payer must be a customer or company already set up.',
        'COMMISSION_PLAN_MISSING' => 'The commission plan on the product or agent no longer exists. Choose another plan.',
        'SOD_CONFLICT' => 'You took part in an earlier step of this, so someone else has to do this one.',
        'MAKER_CHECKER' => 'You prepared this, so someone else has to approve it.',
        'PERMISSION_DENIED' => 'You do not have permission for this action. Ask an administrator if you need it.',
        'CONTROL_ACCOUNT' => 'Control accounts only take adjustments. Use an adjustment journal with a reason, or post through the business screen.',
        'CLOSE_TASKS_OPEN' => 'Finish or skip every close task before locking the period.',
        // Slice 2.1b (D-55): pending documents hold the lock; a pending manual journal moves only into an open next period.
        'PERIOD_HAS_PENDING_DOCUMENTS' => 'Documents dated in this period are still waiting for approval, release or posting. Approve or reject each one, or move a pending manual journal to the next period, before locking. The close checklist lists them.',
        'NEXT_PERIOD_NOT_OPEN' => 'The journal can only move into the next period while that period is open. Open the next fiscal year, or reopen the next period, first.',
        // Slice 2.1b (D-56): locking follows the calendar on the company's clock.
        'PERIOD_LAST_DAY_NOT_REACHED' => 'This month can be soft-locked from its last day. Run the trial balance task again on or after that day.',
        'PERIOD_NOT_ENDED' => 'This month has not ended yet, so it cannot be locked. Lock it from the first day of the next month, or ask the CFO to lock it earlier with a written reason.',
        'EARLY_LOCK_REASON_REQUIRED' => 'This month has not ended yet. To lock it now, write the reason for locking early.',
        'RECONCILIATION_VARIANCE' => 'A subledger does not reconcile to the ledger. Rerun the reconciliation tasks and resolve the difference before locking.',
        // Follow-up H2: reasons whose domain message carries record ids, codes, minor units or keys (tests/Unit/Feedback/ReasonMessagesCoverageTest).
        'RISK_INPUTS_INVALID' => 'Check the risk details: each field that needs attention is marked.',
        'DOCUMENT_PDF_FAILED' => 'The PDF could not be produced. Ask an administrator to check the document renderer, then try again.',
        'COMPENSATION_SCHEME_UNKNOWN' => 'That compensation scheme no longer exists. Choose another scheme.',
        'ADVANCE_INVALID' => 'Choose how the advance is recovered: in full, or a percentage of each net commission from 0.01% to 100%.',
        'SCHEME_MODE_INVALID' => 'Choose how producers are paid: commission, salary with incentive, hybrid or none.',
        'INVALID_LICENCE_CLASS' => 'Choose the licence class: life, non-life or both.',
        'PERIOD_NOT_OPEN' => 'This period is not open, so it cannot be closed. Refresh the close list.',
        'DUTY_NOT_FOUND' => 'No duty rate covers this policy (its class, date or sum insured). Check the duties in the tariff editor.',
        'INVALID_RECOVERY_TYPE' => 'Choose the kind of recovery: salvage, subrogation or third party.',
        'INVALID_INSURANCE_CLASS' => 'Choose the insurance class: life or non-life.',
        'MIN_PREMIUM_INVALID' => 'Enter a minimum premium of zero or more.',
        'RECOGNISE_AT_INVALID' => 'Choose when the premium is recognised: at the policy or at the cover note.',
        'JOB_FAILED' => 'The job did not finish; the error is in the application log. Try again, or ask your administrator if it fails again.',
        // Design addendum v2 §B.7 fixed assets.
        'ASSET_CLASS_INVALID' => 'Check the asset class: an active class, a useful life or yearly rate for its method, and active postable accounts of the right kind.',
        'ASSET_BELOW_THRESHOLD' => 'This cost is below the class\'s capitalisation threshold, so it is an expense, not an asset. Post it as an expense instead.',
        'ASSET_BANK_ACCOUNT_REQUIRED' => 'Choose the bank account the money was paid from or went into.',
        'ASSET_PERIOD_NOT_OPEN' => 'That date is in a month that is not open for posting. Choose a date in an open month.',
        'ASSET_NOT_IN_SERVICE' => 'This asset has been disposed of, so nothing more can be done with it. Refresh the page.',
        'ASSET_SAME_BRANCH' => 'The asset is already at that branch. Choose another branch.',
        // Design addendum v2 §B.8.1 budgets.
        'BUDGET_YEAR_UNKNOWN' => 'That fiscal year is not set up. Open the fiscal year first, then prepare its budget.',
        'BUDGET_NOT_DRAFT' => 'This budget version is no longer a draft. Make a new version to change it.',
        'BUDGET_NOT_SUBMITTED' => 'Only a budget sent for approval can be approved or returned. Refresh the page.',
        'BUDGET_ACCOUNT_INVALID' => 'Budget lines are for active income and expense accounts. Choose another account.',
        'BUDGET_EMPTY' => 'The budget has no amounts yet. Enter them before sending it for approval.',
        'BUDGET_NO_ACTUALS' => 'Last year has no posted income or expense by branch to copy. Enter the budget in the grid or paste it instead.',
        // Design addendum v2 §B.6 petty cash.
        'PETTY_CASH_ACCOUNT_INVALID' => 'Choose an active account of the right kind: an asset account for the float, an expense account for a voucher.',
        'PETTY_CASH_INSUFFICIENT' => 'The float does not hold enough cash for this voucher. Ask for a replenishment first.',
        'PETTY_CASH_NOTHING_TO_REPLENISH' => 'No vouchers have been paid since the last replenishment.',
        'PETTY_CASH_REPLENISHMENT_PENDING' => 'A replenishment of this float is already waiting for approval.',
        'PETTY_CASH_NOT_PENDING' => 'This replenishment has already been decided. Refresh the page.',
        'PETTY_CASH_CUSTODIAN_COUNT' => 'The custodian cannot count their own float. Ask someone from accounts to count it.',
        'ASSET_DEPRECIATED_AFTER_DISPOSAL' => 'Depreciation is already posted for the month of this disposal or later. Date the disposal after the last depreciated month.',
        'EVENT_NOT_STUCK' => 'This accounting event has already been posted or is waiting its turn; only failed or long-queued events can be sent again. Refresh the list.',
        'COVERAGE_INVALID' => 'Each coverage needs a code (lower-case letters, digits and underscores), an English and a Bangla name, and a basis: sum insured, flat, per unit or a percentage of a base.',
        // Gap fix GA-14: cheques in clearing.
        'CHEQUE_NOT_IN_CLEARING' => 'This cheque went straight to the bank when it was recorded, so there is nothing to clear.',
        'ALREADY_CLEARED' => 'This cheque has already cleared. Refresh the page to see it.',
        'CLEARED_BEFORE_RECEIPT' => 'A cheque cannot clear before the day it was received. Choose a later date.',
        // Gap fix GA-27: bank reconciliation actions.
        'OFFSET_NEEDS_TWO_LINES' => 'Choose at least two ledger lines that cancel each other out.',
        'STATEMENT_LINE_NOT_A_CREDIT' => 'Only money paid into the bank can be recorded as a receipt. Post a charge or payment as a journal instead.',
        // Market gap G5: regulatory returns and technical provisions.
        'REGULATORY_PERIOD_INVALID' => 'Choose a quarter such as 2026-Q3 or a year such as 2026.',
        'RETURN_NOT_DRAFT' => 'This return has already been reviewed or filed. Refresh the page to see where it stands.',
        'RETURN_NOT_REVIEWED' => 'Mark the return reviewed before filing it.',
        'RETURN_ALREADY_FILED' => 'This return is already filed. Refresh the page to see its filing.',
        'RETURN_FILING_DATE_INVALID' => 'The filing date must be in or after the return\'s period and not later than today.',
        'RETURN_REFERENCE_REQUIRED' => 'Enter the reference the regulator gave for this filing.',
        'PROVISIONS_QUARTER_REQUIRED' => 'Technical provisions are run for a quarter. Choose a quarter such as 2026-Q3.',
        'PROVISIONS_ALREADY_POSTED' => 'The technical provisions for this quarter are already posted. Next quarter\'s run releases them.',
        'PROVISIONS_NOT_DRAFT' => 'This technical provisions run has already been reviewed or posted. Refresh the page.',
        'PROVISIONS_NOT_REVIEWED' => 'Only a reviewed technical provisions run can be approved. Mark it reviewed first, or refresh the page.',
        'PROVISIONS_NO_BRANCH' => 'Set up a branch for this company before posting technical provisions.',
        // Reinsurance MVP (G4).
        'RI_REINSURER_CODE_TAKEN' => 'Another reinsurer already uses this code. Choose a different code.',
        'RI_STATE_REINSURER_EXISTS' => 'The state reinsurer (Sadharan Bima Corporation) is already set up.',
        'RI_TREATY_TYPE_INVALID' => 'A quota share treaty needs a cession percentage above zero; a surplus treaty needs a retention above zero and at least one line.',
        'RI_TREATY_PERIOD_INVALID' => 'The treaty period must end on or after the day it starts.',
        'RI_TREATY_PARTICIPANTS_INVALID' => 'The treaty reinsurers\' shares must add up to 100%, each above zero. The state reinsurer takes its compulsory share separately.',
        'RI_TREATY_DUPLICATE' => 'Another active treaty already covers this class and underwriting year, or uses this code. Make the other one inactive or choose another code.',
        'RI_POLICY_NOT_IN_FORCE' => 'Facultative reinsurance is placed on an issued or active policy.',
        'RI_REINSURER_INACTIVE' => 'Choose an active reinsurer.',
        'RI_FACULTATIVE_INVALID' => 'Enter a share above 0% and up to 100%, a premium above zero and a commission between 0% and 100%.',
        'RI_SHARE_EXCEEDS_RISK' => 'This placement would cede more than the policy\'s sum insured. Reduce the share or the ceded sum insured.',
        'RI_QUARTER_INVALID' => 'Choose a quarter from 1 to 4 of a calendar year.',
        // Slices 2.3/2.4 accounts payable.
        'SUPPLIER_CODE_TAKEN' => 'Another supplier already uses this code. Choose a different code.',
        'SUPPLIER_CATEGORY_UNKNOWN' => 'Choose one of the listed supplier categories; it decides the VAT and the taxes deducted at source.',
        'SUPPLIER_NAME_REQUIRED' => 'Give the supplier a name, or choose a party that is already set up.',
        'SUPPLIER_EXISTS' => 'This party is already a supplier. Open it from the suppliers list.',
        'INVALID_ACCOUNT' => 'Choose an active account that takes postings and is not a control account.',
        'SUPPLIER_STATUS_INVALID' => 'A supplier is active, on hold or blocked.',
        'SUPPLIER_NOT_PAYABLE' => 'This supplier is on hold or blocked, so its bills cannot be entered or paid. Make the supplier active first.',
        'INVALID_DUE_DATE' => 'The due date cannot be before the bill date.',
        'DUPLICATE_SUPPLIER_BILL' => 'This supplier already has a bill with that invoice number. Check it is not the same bill entered twice.',
        'BILL_LINES_REQUIRED' => 'Add at least one line with an expense account and an amount.',
        'BILL_NOT_CANCELLABLE' => 'Only a draft or a posted bill with nothing paid can be cancelled.',
        'BILL_IN_PAYMENT_RUN' => 'This bill is in a payment run waiting to be released. Cancel that run first, or pay it.',
        'INVALID_BILL_TRANSITION' => 'This bill has already moved on. Refresh the page to see where it stands.',
        'PAYMENT_RUN_EMPTY' => 'Choose at least one bill to pay.',
        'BILL_NOT_PAYABLE' => 'Only posted bills with an amount still to pay can go into a payment run. Refresh the list of due bills.',
        'SUPPLIER_BANK_ACCOUNT_MISSING' => 'A supplier in this run has no bank account to pay into. Add it on the supplier page first.',
        'INVALID_PAYMENT_RUN_TRANSITION' => 'This payment run has already moved on. Refresh the page to see where it stands.',
        'PAYMENT_RUN_NOT_RELEASED' => 'The bank file is available once the payment run is released.',
    ];

    /** @var list<string> reasons worded below from the amounts or dates in the domain message */
    private const COMPUTED = ['ALLOCATION_EXCEEDS_OUTSTANDING', 'ALLOCATION_EXCEEDS_RECEIPT', 'ALLOCATION_EXCEEDS_SUSPENSE', 'APPROVAL_EXCEEDS_RESERVE', 'DEPOSIT_EXCEEDS_UNDEPOSITED_CASH',
        'REFUND_EXCEEDS_DUE', 'RESERVE_UNCHANGED', 'RESERVE_BELOW_APPROVED', 'PREMIUM_CREDIT_EXCEEDS_OUTSTANDING', 'MATCH_AMOUNT_MISMATCH', 'NOTHING_TO_PAY', 'PRODUCT_VERSION_NOT_EFFECTIVE',
        'PRODUCT_VERSION_OVERLAP', 'RATING_EXPRESSION_INVALID', 'RATING_EXPRESSION_NOT_INTEGER', 'RATING_CONDITION_NOT_BOOLEAN'];

    /**
     * @var list<string> follow-up H2: reasons whose domain message is the detail people need (a tariff, schema, target or statement file being set up, a role
     *     conflict) but names keys or permissions by code: kept, with keys in words and permissions as "Context: action".
     */
    private const REWORDED = ['RATING_PLAN_INVALID', 'RISK_SCHEMA_INVALID', 'RATE_TABLE_TYPE', 'STATEMENT_UNREADABLE', 'TARGET_INVALID', 'INCENTIVE_PLAN_INVALID', 'COMPLIANCE_PROFILE_INVALID',
        'ROLE_CONFLICT', 'AUDITOR_WRITE_PERMISSION',
        // UX U2: the context that uses the account (a bank account, …) writes the sentence naming what to change first.
        'ACCOUNT_IN_USE'];

    /** Whether the reason is worded here rather than by its domain message. */
    public static function covers(string $reason): bool
    {
        return isset(self::FIXED[$reason]) || in_array($reason, self::COMPUTED, true) || in_array($reason, self::REWORDED, true);
    }

    /** @return array<string, string> the fixed sentences, by reason */
    public static function fixed(): array
    {
        return self::FIXED;
    }

    public static function forPeople(string $reason, string $message, string $currency = 'BDT'): string
    {
        $amounts = self::integers($message);
        $money = fn (int $minor): string => MinorUnits::format($minor, $currency);
        $over = fn (int $have, int $asked): string => $money(max(0, $asked - $have));
        $dates = self::dates($message);
        $quoted = preg_match("/'([^']+)'/", $message, $found) === 1 ? $found[1] : null;

        return match (true) {
            $reason === 'NOTHING_TO_PAY' && $dates !== [] => "This agent has nothing payable up to {$dates[0]}.",
            $reason === 'PRODUCT_VERSION_NOT_EFFECTIVE' && $dates !== [] => "The product has no version in force on {$dates[0]}. Choose another cover start or product.",
            $reason === 'PRODUCT_VERSION_OVERLAP' && $dates !== [] => "The product already has a version in force from {$dates[0]}".(isset($dates[1]) ? " to {$dates[1]}" : '').'. End that version first or choose other dates.',
            $reason === 'RATING_EXPRESSION_NOT_INTEGER' && $quoted !== null => "The formula '{$quoted}' must give a whole amount. Check it in the tariff editor.",
            $reason === 'RATING_CONDITION_NOT_BOOLEAN' && $quoted !== null => "The condition '{$quoted}' must give yes or no. Check it in the tariff editor.",
            // An expression the evaluator refused carries the evaluator's own error text or a PHP type name: name the formula instead.
            $reason === 'RATING_EXPRESSION_INVALID' && $quoted !== null && preg_match('/is not valid:|wrong kind:/', $message) === 1
                => "The formula '{$quoted}' cannot be worked out. Check it in the tariff editor.",
            $reason === 'RATING_EXPRESSION_INVALID' && preg_match('/^(\w+)\(\) needs whole numbers/', $message, $function) === 1
                => "{$function[1]}() needs whole amounts or basis points. Check the formula in the tariff editor.",
            in_array($reason, ['NOTHING_TO_PAY', 'PRODUCT_VERSION_NOT_EFFECTIVE', 'PRODUCT_VERSION_OVERLAP', 'RATING_EXPRESSION_INVALID', 'RATING_EXPRESSION_NOT_INTEGER', 'RATING_CONDITION_NOT_BOOLEAN'], true),
            in_array($reason, self::REWORDED, true) => self::reworded(self::withoutIds($message)),
            $reason === 'ALLOCATION_EXCEEDS_OUTSTANDING' && count($amounts) >= 2
                => 'The amount exceeds the installment balance of '.$money($amounts[count($amounts) - 2]).' by '.$over($amounts[count($amounts) - 2], $amounts[count($amounts) - 1]).'.',
            $reason === 'ALLOCATION_EXCEEDS_RECEIPT' && count($amounts) >= 2 => 'The allocations exceed the amount received by '.$over($amounts[1], $amounts[0]).'.',
            $reason === 'ALLOCATION_EXCEEDS_SUSPENSE' && count($amounts) >= 2 => 'The amount exceeds what is left in suspense ('.$money($amounts[0]).') by '.$over($amounts[0], $amounts[1]).'.',
            $reason === 'APPROVAL_EXCEEDS_RESERVE' && count($amounts) >= 2 => 'The payment exceeds the reserve left ('.$money($amounts[count($amounts) - 2]).') by '
                .$over($amounts[count($amounts) - 2], $amounts[count($amounts) - 1]).'. Increase the reserve first.',
            $reason === 'DEPOSIT_EXCEEDS_UNDEPOSITED_CASH' && count($amounts) >= 2 => 'The deposit exceeds the cash the agent holds ('.$money($amounts[count($amounts) - 2]).') by '
                .$over($amounts[count($amounts) - 2], $amounts[count($amounts) - 1]).'.',
            $reason === 'REFUND_EXCEEDS_DUE' && count($amounts) >= 2 => 'The refund exceeds what the policy can refund ('.$money($amounts[count($amounts) - 2]).') by '
                .$over($amounts[count($amounts) - 2], $amounts[count($amounts) - 1]).'.',
            $reason === 'RESERVE_UNCHANGED' && $amounts !== [] => 'The reserve is already '.$money($amounts[count($amounts) - 1]).'. Enter a different amount.',
            $reason === 'RESERVE_BELOW_APPROVED' && $amounts !== [] => 'The reserve cannot go below the '.$money($amounts[count($amounts) - 1]).' already approved for payment.',
            $reason === 'PREMIUM_CREDIT_EXCEEDS_OUTSTANDING' && $amounts !== [] => 'The premium decrease of '.$money($amounts[count($amounts) - 1]).' is more than the premium still unpaid.',
            $reason === 'MATCH_AMOUNT_MISMATCH' && count($amounts) >= 2 => 'The ledger lines add up to '.$money($amounts[0]).', not the statement line\'s '.$money($amounts[1]).'. Choose lines that add up.',
            // Gap fix GA-27.
            $reason === 'OFFSET_NOT_ZERO' && $amounts !== [] => 'The chosen ledger lines add up to '.$money($amounts[0]).', not zero, so they do not cancel each other out.',
            isset(self::FIXED[$reason]) => self::FIXED[$reason],
            default => self::withoutIds($message),
        };
    }

    /** @return list<int> the whole numbers in the message that are amounts (not years, dates, versions or document numbers) */
    private static function integers(string $message): array
    {
        $cleaned = preg_replace(['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/\b\d{4}-\d{2}-\d{2}\b/', '/\b[A-Z]{2,4}-\d{4}-\d{6}\b/', '/(?:Installment|version|v)\s*\d+/i'], '', $message) ?? $message;
        preg_match_all('/-?\b\d+\b/', $cleaned, $matches);

        return array_map('intval', $matches[0]);
    }

    /** @return list<string> the dates in the message, as people read them ("30 Sep 2026") */
    private static function dates(string $message): array
    {
        preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $message, $matches);

        return array_map(fn (string $date): string => \Carbon\CarbonImmutable::parse($date)->format('j M Y'), $matches[0]);
    }

    /** Keys and permission codes in words: `order_no` → "order no", `accounting.approve_journal` → "Accounting: approve journal". */
    private static function reworded(string $message): string
    {
        $permissions = (string) preg_replace_callback('/\b([a-z][a-z_]{2,})\.([a-z][a-z_]{2,})\b/', fn (array $m): string => ucfirst(str_replace('_', ' ', $m[1])).': '.str_replace('_', ' ', $m[2]), $message);

        return (string) preg_replace_callback('/\b[a-z]+(?:_[a-z0-9]+)+\b/', fn (array $m): string => str_replace('_', ' ', $m[0]), $permissions);
    }

    private static function withoutIds(string $message): string
    {
        return trim((string) preg_replace(['/\s*[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\s*/i', '/\s+/'], [' ', ' '], $message));
    }
}
