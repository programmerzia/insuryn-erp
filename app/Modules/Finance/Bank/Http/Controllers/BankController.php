<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Http\Controllers;

use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Finance\Bank\Application\BankMatcher;
use App\Modules\Finance\Bank\Application\BankReconciliationQuery;
use App\Modules\Finance\Bank\Application\StatementImport;
use App\Modules\Finance\Bank\Domain\Models\BankAccount;
use App\Modules\Finance\Bank\Domain\Models\BankStatementLine;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class BankController
{
    public function __construct(
        private readonly BankAccountService $accounts,
        private readonly StatementImport $statements,
        private readonly BankMatcher $matcher,
        private readonly BankReconciliationQuery $reconciliation,
        private readonly PermissionChecker $permissions,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var array{entity_id: string, gl_account_id: string, bank_name: string, account_no_masked: string, currency: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'gl_account_id' => ['required', 'uuid'], 'bank_name' => ['required', 'string', 'max:255'],
            'account_no_masked' => ['required', 'string', 'max:64'], 'currency' => ['required', 'string', 'size:3']]);
        $account = $this->accounts->create($data['entity_id'], $data['gl_account_id'], $data['bank_name'], $data['account_no_masked'], $data['currency'], self::actor($request));

        return response()->json(['data' => $account->only(['id', 'entity_id', 'gl_account_id', 'bank_name', 'account_no_masked', 'currency', 'status'])], 201);
    }

    public function importStatement(Request $request, string $bankAccount): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $result = $this->statements->import($bankAccount, (string) $file->get(), $file->getClientOriginalName(), self::actor($request));

        return response()->json(['data' => ['imported' => $result->imported, 'duplicates' => $result->duplicates, 'errors' => $result->errors]],
            $result->errors === [] ? 201 : 422);
    }

    public function autoMatch(Request $request, string $bankAccount): JsonResponse
    {
        $this->authorizeFor($request, 'bank.match', $bankAccount);

        return response()->json(['data' => ['matched' => $this->matcher->autoMatch($bankAccount)]]);
    }

    public function unmatched(Request $request, string $bankAccount): JsonResponse
    {
        /** @var array{as_of?: string} $data */
        $data = $request->validate(['as_of' => ['sometimes', 'date_format:Y-m-d']]);
        $this->authorizeFor($request, 'bank.match', $bankAccount);

        return response()->json(['data' => $this->reconciliation->unmatched($bankAccount, isset($data['as_of']) ? CarbonImmutable::parse($data['as_of']) : app(BusinessClock::class)->today())]);
    }

    public function match(Request $request, string $statementLine): JsonResponse
    {
        /** @var array{journal_line_ids: list<string>} $data */
        $data = $request->validate(['journal_line_ids' => ['required', 'array', 'min:1'], 'journal_line_ids.*' => ['required', 'uuid']]);
        $this->matcher->match($statementLine, $data['journal_line_ids'], self::actor($request));

        return response()->json(['data' => self::presentLine($statementLine)]);
    }

    public function explain(Request $request, string $statementLine): JsonResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->matcher->explain($statementLine, $data['reason'], self::actor($request));

        return response()->json(['data' => self::presentLine($statementLine)]);
    }

    private function authorizeFor(Request $request, string $permission, string $bankAccountId): void
    {
        $this->permissions->authorize(self::actor($request), $permission, AuthorizationScope::entity(BankAccount::query()->findOrFail($bankAccountId)->entity_id));
    }

    /** @return array<string, mixed> */
    private static function presentLine(string $statementLineId): array
    {
        $line = BankStatementLine::query()->findOrFail($statementLineId);

        return ['id' => $line->id, 'posted_on' => $line->posted_on->toDateString(), 'amount_minor' => $line->amount_minor, 'match_status' => $line->match_status, 'explanation' => $line->explanation];
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
