<?php

declare(strict_types=1);

namespace App\Http\Bank;

use App\Http\Pages\PageSupport;
use App\Modules\Finance\Bank\Application\BankMatcher;
use App\Modules\Finance\Bank\Domain\Models\BankAccount;
use App\Modules\Finance\Bank\Domain\Models\BankStatementLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gap fix GA-27: an unknown credit on the bank statement becomes a receipt held in suspense in one step, from the bank screen — a composition over
 * Collections (ReceiptService: receipt.create on the branch, numbering, RECEIPT_RECORDED into suspense_receipts, audit) and Bank (BankMatcher). The receipt
 * takes the line's amount, date, bank account and text as its reference; once its journal is posted (at once when posting runs synchronously) the statement
 * line is matched to it, otherwise "Accept strong matches" matches it by the reference.
 */
final class StatementLineReceiptController
{
    public function __construct(private readonly PermissionChecker $permissions) {}

    public function store(Request $request, string $statementLine, ReceiptService $receipts, BankMatcher $matcher): RedirectResponse
    {
        /** @var array{branch_id: string} $data */
        $data = $request->validate(['branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')]], ['branch_id.required' => 'Choose the branch the money belongs to.']);
        $actor = PageSupport::actor($request);
        $line = BankStatementLine::query()->findOrFail($statementLine);
        $bankAccount = BankAccount::query()->findOrFail($line->bank_account_id);
        if ($line->match_status !== 'unmatched') {
            throw new BusinessRuleViolation('ALREADY_MATCHED', "This statement line is already {$line->match_status}.");
        }
        if ($line->amount_minor <= 0) {
            throw new BusinessRuleViolation('STATEMENT_LINE_NOT_A_CREDIT', 'Only money paid into the bank can be recorded as a receipt.');
        }
        $reference = Str::limit(trim(implode(' ', array_filter([$line->reference, $line->description]))), 255, '');

        $receipt = $receipts->record(new RecordReceiptRequest($bankAccount->entity_id, $data['branch_id'], null, 'bank_transfer', $line->amount_minor, $bankAccount->currency,
            $line->posted_on, $bankAccount->id, $reference === '' ? null : $reference, []), $actor);

        $matched = false;
        if ($this->permissions->has($actor, 'bank.match', AuthorizationScope::entity($bankAccount->entity_id))) {
            $ledger = collect($matcher->unmatchedJournalLines($bankAccount->gl_account_id, $line->posted_on, $line->posted_on))
                ->first(fn (array $l): bool => $l['receipt_number'] === $receipt->number && $l['amount_minor'] === $line->amount_minor);
            if ($ledger !== null) {
                $matcher->match($line->id, [$ledger['journal_line_id']], $actor);
                $matched = true;
            }
        }
        $held = DB::table('suspense_items')->where('receipt_id', $receipt->id)->exists();

        return back()->with('status', "Receipt {$receipt->number} recorded".($held ? ' and held in suspense' : '').($matched ? '; the statement line is matched to it.' : '; the statement line matches it once its journal is posted.'))
            ->with('next', ['label' => "Open {$receipt->number}", 'url' => "/receipts/{$receipt->id}"]);
    }
}
