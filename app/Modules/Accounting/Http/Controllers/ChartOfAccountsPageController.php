<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Application\ChartOfAccounts\AccountInvalid;
use App\Modules\Accounting\Application\ChartOfAccounts\ChartOfAccounts;
use App\Modules\Accounting\Application\ChartOfAccounts\ChartOfAccountsQuery;
use App\Modules\Accounting\Application\ChartOfAccounts\ChartOfAccountsRules;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounting → Chart of accounts (UX U2): the accounts as an indented tree with type, normal side, postable/control, roles, currency, status and balance.
 * Opens for accounting.view_journals or accounting.manage_coa; adding and changing accounts need accounting.manage_coa (ChartOfAccounts authorizes).
 */
final class ChartOfAccountsPageController
{
    private const VIEW = ['accounting.view_journals', ChartOfAccounts::PERMISSION];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ChartOfAccounts $accounts,
    ) {}

    public function index(Request $request, ChartOfAccountsQuery $query): Response
    {
        $entity = PageSupport::entity();
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::VIEW, AuthorizationScope::entity($entity['id']));
        $bookId = (string) DB::table('books')->where('is_primary', true)->value('id');

        return Inertia::render('accounting/ChartOfAccounts', [
            'entity' => $entity,
            'accounts' => $bookId === '' ? [] : $query->tree($entity['id'], $bookId, app(BusinessClock::class)->today($entity['id'])),
            'canManage' => $this->permissions->has($actor, ChartOfAccounts::PERMISSION, AuthorizationScope::entity($entity['id'])),
            'types' => array_column(AccountType::cases(), 'value'),
            'subledgers' => ChartOfAccountsRules::SUBLEDGERS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{code: string, name: string, type: string, normal_side: string, parent_id?: string|null, is_postable?: bool, is_control?: bool, control_subledger?: string|null, currency?: string|null} $data */
        $data = $request->validate(self::rules() + ['code' => ['required', 'string', 'max:32'], 'is_control' => ['sometimes', 'boolean'],
            'control_subledger' => ['nullable', 'string', 'max:32'], 'currency' => ['nullable', 'string', 'max:3']],
            ['code.required' => 'Account code is required.', 'name.required' => 'Account name is required.']);
        $entity = PageSupport::entity();
        try {
            $this->accounts->create($entity['id'], ['code' => $data['code'], 'name' => $data['name'], 'type' => $data['type'], 'normal_side' => $data['normal_side'],
                'parent_id' => ($data['parent_id'] ?? '') === '' ? null : $data['parent_id'], 'is_postable' => (bool) ($data['is_postable'] ?? true),
                'is_control' => (bool) ($data['is_control'] ?? false), 'control_subledger' => ($data['control_subledger'] ?? '') === '' ? null : $data['control_subledger'],
                'currency' => ($data['currency'] ?? '') === '' ? null : $data['currency']], PageSupport::actor($request));
        } catch (AccountInvalid $e) {
            throw ValidationException::withMessages($e->errors);
        }

        return redirect('/accounting/chart-of-accounts')->with('status', "Account {$data['code']} {$data['name']} added.");
    }

    public function update(Request $request, string $account): RedirectResponse
    {
        /** @var array{name: string, type: string, normal_side: string, parent_id?: string|null, is_postable?: bool} $data */
        $data = $request->validate(self::rules(), ['name.required' => 'Account name is required.']);
        try {
            $this->accounts->update($account, ['name' => $data['name'], 'parent_id' => ($data['parent_id'] ?? '') === '' ? null : $data['parent_id'],
                'is_postable' => (bool) ($data['is_postable'] ?? true), 'type' => $data['type'], 'normal_side' => $data['normal_side']], PageSupport::actor($request));
        } catch (AccountInvalid $e) {
            throw ValidationException::withMessages($e->errors);
        }

        return redirect('/accounting/chart-of-accounts')->with('status', 'Account saved.');
    }

    public function deactivate(Request $request, string $account): RedirectResponse
    {
        $this->accounts->deactivate($account, PageSupport::actor($request), app(BusinessClock::class)->today());

        return redirect('/accounting/chart-of-accounts')->with('status', 'Account deactivated. Journals can no longer post to it.');
    }

    public function reactivate(Request $request, string $account): RedirectResponse
    {
        $this->accounts->reactivate($account, PageSupport::actor($request));

        return redirect('/accounting/chart-of-accounts')->with('status', 'Account reactivated.');
    }

    /** @return array<string, list<mixed>> */
    private static function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'type' => ['required', 'string', 'max:16'], 'normal_side' => ['required', 'string', 'max:8'],
            'parent_id' => ['nullable', 'uuid'], 'is_postable' => ['sometimes', 'boolean']];
    }
}
