<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Platform\Documents\DocumentStore;
use App\Modules\Accounting\Application\Imports\ChartOfAccountsImport;
use App\Modules\Accounting\Application\Imports\ImportMode;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Reversals\ReversalRequestService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Manual and adjustment journals entered on screen (design §5.2): create and submit, approve or reject by someone else; reversal requests. */
final class ManualJournalPageController
{
    public function create(Request $request, PermissionChecker $permissions): Response
    {
        $entity = PageSupport::entity();

        return Inertia::render('accounting/journals/Create', [
            'entity' => $entity,
            'today' => app(BusinessClock::class)->today()->toDateString(), // flow fix X2: a manual journal is dated today unless changed
            'kinds' => [JournalKind::Manual->value, JournalKind::Adjustment->value],
            'accounts' => DB::table('accounts')->where('entity_id', $entity['id'])->where('is_postable', true)->where('status', 'active')->orderBy('code')
                ->get(['id', 'code', 'name', 'is_control'])->map(fn (object $a): array => ['id' => (string) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'is_control' => (bool) $a->is_control])->values()->all(),
            'branches' => DB::table('branches')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => (array) $b)->values()->all(),
            // Flow fix X10: an account missing from the chart can be created from a line.
            'canCreateAccount' => $permissions->has(PageSupport::actor($request), 'accounting.manage_coa', AuthorizationScope::entity($entity['id'])),
            'prefill' => $this->prefill($request, $entity['id']),
        ]);
    }

    /**
     * Gap fix GA-27 ("Post as bank charge"): a journal the bank screen asks for, as plain values in the query — `date`, `amount` (major units), `memo` (the
     * statement line's text, which later matches the line), `credit_account` (the bank's GL account) and `debit_role` (an account role, bank_charges) — only
     * accounts of this entity that accept postings are used; anything else is left for the person to fill in. The journal still goes for approval.
     * The kernel reads only its own tables here: the bank screen passes the GL account, never a statement line.
     *
     * @return array{transaction_date: string, description: string, reason: string, lines: list<array{account_id: string, side: string, amount: string, memo: string}>}|null
     */
    private function prefill(Request $request, string $entityId): ?array
    {
        $query = $request->query('prefill');
        if (! is_array($query)) {
            return null;
        }
        $text = fn (string $key, int $max): string => is_string($query[$key] ?? null) ? mb_substr(trim((string) $query[$key]), 0, $max) : '';
        $date = $text('date', 10);
        $amount = $text('amount', 32);
        $postable = fn (string $accountId): bool => preg_match('/^[0-9a-f-]{36}$/i', $accountId) === 1
            && DB::table('accounts')->where('id', $accountId)->where('entity_id', $entityId)->where('is_postable', true)->where('status', 'active')->exists();
        $credit = $text('credit_account', 36);
        $role = $text('debit_role', 64);
        $debit = $role === '' ? '' : (string) (DB::table('account_role_mappings as m')->join('books as b', 'b.id', '=', 'm.book_id')->where('b.is_primary', true)->where('m.entity_id', $entityId)
            ->where('m.role_code', $role)->where('m.effective_from', '<=', preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : app(BusinessClock::class)->today()->toDateString())
            ->orderByDesc('m.effective_from')->value('m.account_id') ?? '');
        $memo = $text('memo', 255);
        $amount = preg_match('/^\d[\d,]*(\.\d{1,2})?$/', $amount) === 1 ? $amount : '';

        return ['transaction_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : app(BusinessClock::class)->today()->toDateString(),
            'description' => $text('description', 255), 'reason' => $text('reason', 255),
            'lines' => [['account_id' => $postable($debit) ? $debit : '', 'side' => 'debit', 'amount' => $amount, 'memo' => $memo],
                ['account_id' => $postable($credit) ? $credit : '', 'side' => 'credit', 'amount' => $amount, 'memo' => $memo]]];
    }

    /**
     * Flow fix X10 (Part A step 10): an account created from a manual journal line, as a one-row chart-of-accounts import committed through
     * ChartOfAccountsImport — the import's permission (accounting.manage_coa), rules and audit, nothing new. JSON: 201 {account} or 422 {errors: field → messages}.
     */
    public function createAccount(Request $request, ChartOfAccountsImport $import): JsonResponse
    {
        /** @var array{code?: string|null, name?: string|null, type?: string|null, normal_side?: string|null, is_postable?: bool|string|null} $data */
        $data = $request->validate(['code' => ['nullable', 'string', 'max:32'], 'name' => ['nullable', 'string', 'max:255'], 'type' => ['nullable', 'string', 'max:16'],
            'normal_side' => ['nullable', 'string', 'max:8'], 'is_postable' => ['nullable', 'boolean']]);
        $entity = PageSupport::entity();
        $columns = (array) config('erp.imports.chart_of_accounts');
        $values = ['code' => trim((string) ($data['code'] ?? '')), 'name' => trim((string) ($data['name'] ?? '')), 'type' => (string) ($data['type'] ?? ''),
            'normal_side' => (string) ($data['normal_side'] ?? ''), 'is_postable' => filter_var($data['is_postable'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false'];
        $cell = fn (string $value): string => '"'.str_replace('"', '""', (string) preg_replace('/[\r\n]+/', ' ', $value)).'"';
        $csv = implode(',', array_map(fn (string $field): string => $cell((string) ($columns[$field] ?? $field)), array_keys($values)))."\n".implode(',', array_map($cell, $values))."\n";

        $outcome = $import->run($csv, $entity['id'], ImportMode::Commit, PageSupport::actor($request));
        if ($outcome->hasErrors()) {
            $errors = [];
            foreach ($outcome->toArray()['errors'] as $error) {
                $errors[in_array($error['field'], array_keys($values), true) ? $error['field'] : 'form'][] = $error['message'];
            }

            return response()->json(['message' => 'The account was not created.', 'errors' => $errors], 422);
        }
        $account = DB::table('accounts')->where('entity_id', $entity['id'])->where('code', $values['code'])->first(['id', 'code', 'name', 'is_control', 'is_postable']);

        return response()->json(['account' => ['id' => (string) $account?->id, 'code' => (string) $account?->code, 'name' => (string) $account?->name,
            'is_control' => (bool) $account?->is_control, 'is_postable' => (bool) $account?->is_postable]], 201);
    }

    public function store(Request $request, ManualJournalService $journals): RedirectResponse
    {
        /** @var array{transaction_date: string, description: string, kind: string, reason?: string|null, lines: list<array{account_id: string, side: string, amount: string, branch_id?: string|null, memo?: string|null}>} $data */
        $data = $request->validate(['transaction_date' => ['required', 'date_format:Y-m-d'], 'description' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([JournalKind::Manual->value, JournalKind::Adjustment->value])], 'reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2'], 'lines.*.account_id' => ['required', 'uuid'], 'lines.*.side' => ['required', Rule::enum(Side::class)],
            'lines.*.amount' => ['required', 'string'], 'lines.*.branch_id' => ['nullable', 'uuid'], 'lines.*.memo' => ['nullable', 'string', 'max:255'],
            // GA-31: the scanned voucher, attached as the journal is saved (more can be attached on the journal page).
            'voucher' => ['nullable', ...array_values(array_filter(DocumentStore::uploadRules(), fn (mixed $rule): bool => $rule !== 'required'))]],
            ['voucher.max' => 'The voucher must be '.intdiv(DocumentStore::maxUploadKb(), 1024).' MB or smaller.', 'voucher.extensions' => 'Attach the voucher as a PDF, a JPG or PNG image, or a Word or Excel file.']);
        $entity = PageSupport::entity();
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $lines[] = new ManualJournalLine($line['account_id'], Side::from($line['side']), PageSupport::minor("lines.{$index}.amount", $line['amount'], $entity['currency']),
                ($line['branch_id'] ?? '') === '' ? [] : ['branch' => (string) $line['branch_id']], $line['memo'] ?? null);
        }
        $actor = PageSupport::actor($request);
        $journal = $journals->create(new ManualJournalRequest($entity['id'], CarbonImmutable::parse($data['transaction_date']), $data['description'], JournalKind::from($data['kind']),
            $data['reason'] ?? null, $entity['currency'], $lines), $actor);
        $voucher = $request->file('voucher');
        if ($voucher instanceof \Illuminate\Http\UploadedFile) {
            app(DocumentStore::class)->attach('journal', $journal->id, $voucher, $actor, 'Supporting voucher');
        }
        $approvalId = $journals->submit($journal->id, $actor);

        return redirect("/accounting/journals/{$journal->id}")->with('status', $approvalId === null ? 'Journal submitted; another user must approve it.' : 'Journal submitted for approval under the approval policy.');
    }

    public function approve(Request $request, string $journal, ManualJournalService $journals): RedirectResponse
    {
        $journals->approve($journal, PageSupport::actor($request));

        return redirect("/accounting/journals/{$journal}")->with('status', 'Journal approved.');
    }

    public function reject(Request $request, string $journal, ManualJournalService $journals): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $journals->reject($journal, PageSupport::actor($request), $data['reason']);

        return redirect("/accounting/journals/{$journal}")->with('status', 'Journal rejected.');
    }

    public function requestReversal(Request $request, string $journal, ReversalRequestService $reversals): RedirectResponse
    {
        /** @var array{on: string, reason: string} $data */
        $data = $request->validate(['on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']]);
        $reversals->request($journal, CarbonImmutable::parse($data['on']), $data['reason'], PageSupport::actor($request));

        return redirect("/accounting/journals/{$journal}")->with('status', 'Reversal requested; another user must approve it.');
    }

    public function decideReversal(Request $request, string $reversalRequest, string $decision, ReversalRequestService $reversals): RedirectResponse
    {
        $journalId = (string) DB::table('journal_reversal_requests')->where('id', $reversalRequest)->value('journal_id');
        if ($decision === 'approve') {
            $reversal = $reversals->approve($reversalRequest, PageSupport::actor($request));
            $message = $reversal === null ? 'Approval recorded; further approval steps remain.' : 'Journal reversed.';
        } else {
            /** @var array{reason: string} $data */
            $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
            $reversals->reject($reversalRequest, PageSupport::actor($request), $data['reason']);
            $message = 'Reversal rejected.';
        }

        return redirect("/accounting/journals/{$journalId}")->with('status', $message);
    }
}
