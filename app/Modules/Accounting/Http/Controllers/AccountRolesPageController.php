<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Application\AccountRoles\AccountRoleMappingService;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Accounting → Account roles (fix F4): per entity and book, the account each role posts to and from when, with the roles the posting rules need but nobody mapped. */
final class AccountRolesPageController
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly AccountRoleMappingService $mappings,
    ) {}

    public function index(Request $request): Response
    {
        $entities = array_values(DB::table('legal_entities')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $e): array => ['id' => (string) $e->id, 'code' => (string) $e->code, 'name' => (string) $e->name])->all());
        $books = array_values(DB::table('books')->orderByDesc('is_primary')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => ['id' => (string) $b->id, 'code' => (string) $b->code, 'name' => (string) $b->name])->all());
        abort_if($entities === [] || $books === [], 404, 'Set up the company and fiscal year first.');
        $entityId = self::pick($entities, $request->query('entity'));
        $bookId = self::pick($books, $request->query('book'));
        $this->permissions->authorize(PageSupport::actor($request), AccountRoleMappingService::PERMISSION, AuthorizationScope::entity($entityId));
        $today = app(BusinessClock::class)->today();

        return Inertia::render('accounting/AccountRoles', [
            'entities' => $entities, 'books' => $books, 'entityId' => $entityId, 'bookId' => $bookId, 'today' => $today->toDateString(),
            'roles' => $this->mappings->roles($entityId, $bookId, $today),
            'unmapped' => $this->mappings->unmappedRoles($entityId, $bookId, $today),
            'accounts' => DB::table('accounts')->where('entity_id', $entityId)->where('status', 'active')->where('is_postable', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'is_control'])->map(fn (object $a): array => ['id' => (string) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'is_control' => (bool) $a->is_control])->values()->all(),
        ]);
    }

    public function map(Request $request): RedirectResponse
    {
        /** @var array{entity_id: string, book_id: string, role: string, account_id: string, effective_from: string} $data */
        $data = $request->validate([
            'entity_id' => ['required', 'uuid', Rule::exists('legal_entities', 'id')], 'book_id' => ['required', 'uuid', Rule::exists('books', 'id')],
            'role' => ['required', 'string', Rule::exists('account_roles', 'code')], 'account_id' => ['required', 'uuid'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ], ['account_id.required' => 'Choose the account.']);
        $this->mappings->map($data['entity_id'], $data['book_id'], $data['role'], $data['account_id'], CarbonImmutable::parse($data['effective_from']), PageSupport::actor($request));

        return redirect('/accounting/account-roles?'.http_build_query(['entity' => $data['entity_id'], 'book' => $data['book_id']]))
            ->with('status', "Role {$data['role']} mapped from ".CarbonImmutable::parse($data['effective_from'])->format('j M Y').'.');
    }

    /** @param list<array{id: string, code: string, name: string}> $options */
    private static function pick(array $options, mixed $wanted): string
    {
        foreach ($options as $option) {
            if ($option['id'] === $wanted) {
                return $option['id'];
            }
        }

        return $options[0]['id'];
    }
}
