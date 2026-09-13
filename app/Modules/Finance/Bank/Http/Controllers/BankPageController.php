<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Finance\Bank\Application\BankMatcher;
use App\Modules\Finance\Bank\Application\BankReconciliationQuery;
use App\Modules\Finance\Bank\Application\StatementImport;
use App\Modules\Finance\Bank\Domain\Models\BankAccount;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Bank screens (spec §5 Bank): bank accounts, statement import, the unmatched queue with automatic and manual matching and explanations. */
final class BankPageController
{
    public const AREA = ['bank.import', 'bank.match', 'bank.manage_accounts', 'reports.financial'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();

        return Inertia::render('bank/Index', [
            'entity' => $entity,
            'accounts' => DB::table('bank_accounts as b')->join('accounts as a', 'a.id', '=', 'b.gl_account_id')->where('b.entity_id', $entity['id'])->orderBy('b.bank_name')
                ->get(['b.id', 'b.bank_name', 'b.account_no_masked', 'b.currency', 'b.status', 'a.code as gl_code', 'a.name as gl_name'])
                ->map(fn (object $b): array => (array) $b + ['unmatched_lines' => (int) DB::table('bank_statement_lines')->where('bank_account_id', $b->id)->where('match_status', 'unmatched')->count()])
                ->values()->all(),
            'glAccounts' => DB::table('accounts')->where('entity_id', $entity['id'])->where('type', 'asset')->where('is_postable', true)->where('is_control', false)->where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $a): array => (array) $a)->values()->all(),
        ]);
    }

    public function store(Request $request, BankAccountService $accounts): RedirectResponse
    {
        /** @var array{gl_account_id: string, bank_name: string, account_no_masked: string, currency: string} $data */
        $data = $request->validate(['gl_account_id' => ['required', 'uuid'], 'bank_name' => ['required', 'string', 'max:255'], 'account_no_masked' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3']]);
        $accounts->create(PageSupport::entity()['id'], $data['gl_account_id'], $data['bank_name'], $data['account_no_masked'], $data['currency'], PageSupport::actor($request));

        return redirect('/bank')->with('status', 'Bank account added.');
    }

    public function show(Request $request, string $bankAccount, BankReconciliationQuery $reconciliation): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $account = BankAccount::query()->findOrFail($bankAccount);
        $asOfValue = $request->query('as_of');
        $asOf = is_string($asOfValue) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfValue) === 1 ? CarbonImmutable::parse($asOfValue) : CarbonImmutable::today();
        $queue = $reconciliation->unmatched($account->id, $asOf);
        $money = fn (int $minor): string => PageSupport::money($minor, $account->currency);

        return Inertia::render('bank/Show', [
            'account' => ['id' => $account->id, 'bank_name' => $account->bank_name, 'account_no_masked' => $account->account_no_masked, 'currency' => $account->currency],
            'asOf' => $asOf->toDateString(),
            'unmatched' => [
                'statement_lines' => array_map(fn (array $l): array => $l + ['amount' => $money($l['amount_minor'])], $queue['statement_lines']),
                'journal_lines' => array_map(fn (array $l): array => $l + ['amount' => $money($l['amount_minor'])], $queue['journal_lines']),
            ],
        ]);
    }

    public function import(Request $request, string $bankAccount, StatementImport $statements): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $result = $statements->import($bankAccount, (string) $file->get(), $file->getClientOriginalName(), PageSupport::actor($request));
        if ($result->errors !== []) {
            return back()->withErrors(['form' => 'The statement was not imported: '.implode(' ', array_map(fn (int $row, string $error): string => "Line {$row}: {$error}", array_keys($result->errors), $result->errors))]);
        }

        return back()->with('status', "{$result->imported} statement line(s) imported, {$result->duplicates} already present.");
    }

    public function autoMatch(Request $request, string $bankAccount, BankMatcher $matcher): RedirectResponse
    {
        $this->permissions->authorize(PageSupport::actor($request), 'bank.match', \App\Modules\Platform\Authorization\AuthorizationScope::entity(BankAccount::query()->findOrFail($bankAccount)->entity_id));
        $matched = $matcher->autoMatch($bankAccount);

        return back()->with('status', "{$matched} statement line".($matched === 1 ? '' : 's').' matched automatically.');
    }

    public function match(Request $request, string $statementLine, BankMatcher $matcher): RedirectResponse
    {
        /** @var array{journal_line_ids: list<string>} $data */
        $data = $request->validate(['journal_line_ids' => ['required', 'array', 'min:1'], 'journal_line_ids.*' => ['required', 'uuid']]);
        $matcher->match($statementLine, $data['journal_line_ids'], PageSupport::actor($request));

        return back()->with('status', 'Statement line matched.');
    }

    public function explain(Request $request, string $statementLine, BankMatcher $matcher): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $matcher->explain($statementLine, $data['reason'], PageSupport::actor($request));

        return back()->with('status', 'Statement line explained.');
    }
}
