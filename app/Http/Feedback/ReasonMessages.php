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
        'UNKNOWN_PAYER' => 'Every payer must be a customer or company already set up.',
        'COMMISSION_PLAN_MISSING' => 'The commission plan on the product or agent no longer exists. Choose another plan.',
        'SOD_CONFLICT' => 'You took part in an earlier step of this, so someone else has to do this one.',
        'MAKER_CHECKER' => 'You prepared this, so someone else has to approve it.',
        'PERMISSION_DENIED' => 'You do not have permission for this action. Ask an administrator if you need it.',
        'CONTROL_ACCOUNT' => 'Control accounts only take adjustments. Use an adjustment journal with a reason, or post through the business screen.',
        'CLOSE_TASKS_OPEN' => 'Finish or skip every close task before locking the period.',
        'RECONCILIATION_VARIANCE' => 'A subledger does not reconcile to the ledger. Rerun the reconciliation tasks and resolve the difference before locking.',
    ];

    public static function forPeople(string $reason, string $message, string $currency = 'BDT'): string
    {
        $amounts = self::integers($message);
        $money = fn (int $minor): string => MinorUnits::format($minor, $currency);
        $over = fn (int $have, int $asked): string => $money(max(0, $asked - $have));

        return match (true) {
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

    private static function withoutIds(string $message): string
    {
        return trim((string) preg_replace(['/\s*[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\s*/i', '/\s+/'], [' ', ' '], $message));
    }
}
