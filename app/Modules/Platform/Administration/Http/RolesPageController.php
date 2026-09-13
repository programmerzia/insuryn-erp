<?php

declare(strict_types=1);

namespace App\Modules\Platform\Administration\Http;

use App\Http\Pages\PageSupport;
use App\Modules\Platform\Administration\RoleAdministration;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Roles screens for the Tenant Admin (phase 2.0): list, create, choose permissions, delete an unused role. */
final class RolesPageController
{
    private const PERMISSION = 'platform.manage_roles';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly RoleAdministration $roles,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorize(PageSupport::actor($request), self::PERMISSION);
        $permissionCounts = DB::table('role_permissions')->groupBy('role_id')->selectRaw('role_id, count(*) as n')->pluck('n', 'role_id');
        $holderCounts = DB::table('user_roles')->groupBy('role_id')->selectRaw('role_id, count(distinct user_id) as n')->pluck('n', 'role_id');

        return Inertia::render('admin/roles/Index', [
            'roles' => DB::table('roles')->orderBy('name')->get(['id', 'code', 'name'])->map(fn (object $r): array => ['id' => (string) $r->id, 'code' => (string) $r->code,
                'name' => (string) $r->name, 'permissions' => (int) ($permissionCounts[$r->id] ?? 0), 'holders' => (int) ($holderCounts[$r->id] ?? 0)])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, self::PERMISSION);
        /** @var array{name: string} $data */
        $data = $request->validate(['name' => ['required', 'string', 'max:80', 'regex:/[\pL\pN]/u']]);
        if (DB::table('roles')->where('code', RoleAdministration::codeFor($data['name']))->exists()) {
            throw ValidationException::withMessages(['name' => 'A role with this name already exists.']);
        }
        $id = $this->roles->create(trim($data['name']), $actor);

        return redirect("/admin/roles/{$id}")->with('status', 'Role created. Choose its permissions.');
    }

    public function show(Request $request, string $role): Response
    {
        $this->permissions->authorize(PageSupport::actor($request), self::PERMISSION);
        $model = DB::table('roles')->where('id', $role)->first(['id', 'code', 'name']);
        abort_if($model === null, 404);

        return Inertia::render('admin/roles/Show', [
            'role' => ['id' => (string) $model->id, 'code' => (string) $model->code, 'name' => (string) $model->name],
            'granted' => DB::table('role_permissions')->where('role_id', $model->id)->orderBy('permission_code')->pluck('permission_code')->all(),
            'catalogue' => self::catalogue(),
            'holders' => DB::table('user_roles as ur')->join('users as u', 'u.id', '=', 'ur.user_id')->where('ur.role_id', $model->id)->distinct()->orderBy('u.name')
                ->get(['u.id', 'u.name', 'u.email', 'u.status'])->map(fn (object $u): array => (array) $u)->values()->all(),
        ]);
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, self::PERMISSION);
        $name = DB::table('roles')->where('id', $role)->value('name');
        abort_if(! is_string($name), 404);
        /** @var array{permissions?: list<string>} $data */
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', Rule::exists('permissions', 'code')]],
            ['permissions.*.exists' => 'That permission does not exist.']);

        try {
            $change = $this->roles->updatePermissions($role, $data['permissions'] ?? [], $actor);
        } catch (SodViolation $violation) {
            $holder = (string) DB::table('users')->where('id', $violation->userId)->value('name');
            throw new BusinessRuleViolation(UsersPageController::reasonFor($violation), $violation->reasonCode === 'AUDITOR_WRITE_PERMISSION' && DB::table('roles')->where('id', $role)->value('code') === 'auditor'
                ? "Auditors stay read-only, so the Auditor role cannot include {$violation->permission}."
                : "{$holder} holds this role and cannot hold {$violation->permission} together with {$violation->conflictingPermission} (segregation of duties).");
        }

        return back()->with('status', self::changeSentence($name, $change));
    }

    public function destroy(Request $request, string $role): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, self::PERMISSION);
        $name = DB::table('roles')->where('id', $role)->value('name');
        abort_if(! is_string($name), 404);
        $this->roles->delete($role, $actor);

        return redirect('/admin/roles')->with('status', "{$name} deleted.");
    }

    /** @param array{added: list<string>, removed: list<string>} $change */
    private static function changeSentence(string $name, array $change): string
    {
        $parts = array_filter([
            $change['added'] === [] ? null : count($change['added']).' '.Str::plural('permission', count($change['added'])).' added',
            $change['removed'] === [] ? null : count($change['removed']).' '.Str::plural('permission', count($change['removed'])).' removed',
        ]);

        return $parts === [] ? "{$name}: nothing changed." : "{$name} updated: ".implode(', ', $parts).'.';
    }

    /** @return list<array{label: string, permissions: list<array{code: string, label: string}>}> the catalogue grouped by context ("Accounting", "Claim") */
    private static function catalogue(): array
    {
        $groups = [];
        foreach (DB::table('permissions')->orderBy('code')->pluck('code') as $code) {
            [$context, $action] = array_pad(explode('.', (string) $code, 2), 2, '');
            $groups[$context][] = ['code' => (string) $code, 'label' => ucfirst(str_replace(['_', 'coa'], [' ', 'chart of accounts'], $action))];
        }

        return array_map(fn (string $context, array $permissions): array => ['label' => ucfirst($context), 'permissions' => $permissions], array_keys($groups), $groups);
    }
}
