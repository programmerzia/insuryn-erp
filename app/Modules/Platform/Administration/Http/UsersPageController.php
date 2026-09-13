<?php

declare(strict_types=1);

namespace App\Modules\Platform\Administration\Http;

use App\Http\Pages\ObjectHistory;
use App\Http\Pages\PageSupport;
use App\Models\User;
use App\Modules\Platform\Administration\UserAdministration;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\RoleAssignmentService;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Users screens for the Tenant Admin (phase 2.0): list, invite, roles by scope, deactivate. */
final class UsersPageController
{
    private const PERMISSION = 'platform.manage_users';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly UserAdministration $users,
        private readonly RoleAssignmentService $assignments,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorize(PageSupport::actor($request), self::PERMISSION);
        $roles = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->orderBy('r.name')->get(['ur.user_id', 'r.name'])->groupBy('user_id');

        return Inertia::render('admin/users/Index', [
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'status'])->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'status' => $u->status, 'roles' => array_values(array_unique($roles->get($u->id, collect())->pluck('name')->map(fn ($n): string => (string) $n)->all()))])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->permissions->authorize(PageSupport::actor($request), self::PERMISSION);
        /** @var array{name: string, email: string} $data */
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']]);
        if (User::query()->whereRaw('lower(email) = ?', [Str::lower($data['email'])])->exists()) {
            throw ValidationException::withMessages(['email' => 'A user with this email already exists.']);
        }
        $user = $this->users->invite($data['name'], $data['email'], PageSupport::actor($request));

        return redirect("/admin/users/{$user->id}")->with('status', "Invitation sent to {$user->email}.");
    }

    public function show(Request $request, string $user, ObjectHistory $history): Response
    {
        $this->permissions->authorize(PageSupport::actor($request), self::PERMISSION);
        $model = User::query()->findOrFail($user);

        return Inertia::render('admin/users/Show', [
            'user' => ['id' => $model->id, 'name' => $model->name, 'email' => $model->email, 'status' => $model->status, 'self' => $model->id === PageSupport::actor($request)],
            'assignments' => $this->assignmentsOf($model->id),
            'roles' => DB::table('roles')->orderBy('name')->get(['id', 'name'])->map(fn (object $r): array => ['id' => (string) $r->id, 'name' => (string) $r->name])->values()->all(),
            'entities' => DB::table('legal_entities')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $e): array => (array) $e)->values()->all(),
            'branches' => DB::table('branches')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'timeline' => $history->timeline([['user', $model->id]]),
        ]);
    }

    public function assign(Request $request, string $user): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, self::PERMISSION);
        $model = User::query()->findOrFail($user);
        [$roleId, $scopeType, $scopeId] = $this->validatedAssignment($request);
        $roleName = (string) DB::table('roles')->where('id', $roleId)->value('name');
        if (DB::table('user_roles')->where(['user_id' => $model->id, 'role_id' => $roleId, 'scope_type' => $scopeType, 'scope_id' => $scopeId])->exists()) {
            throw new BusinessRuleViolation('ROLE_ALREADY_HELD', "{$model->name} already has {$roleName} there.");
        }

        try {
            $warnings = $this->assignments->assign($model->id, $roleId, $scopeType, $scopeId, $actor);
        } catch (SodViolation $violation) {
            throw new BusinessRuleViolation(self::reasonFor($violation), self::conflictSentence($violation, $model->name, $roleName));
        }
        $note = $warnings === [] ? '' : ' Note: '.implode(' ', array_map(fn (SodViolation $w): string => "{$w->permission} and {$w->conflictingPermission} are usually kept apart.", $warnings));

        return back()->with('status', "{$roleName} added.{$note}");
    }

    public function revoke(Request $request, string $user): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, self::PERMISSION);
        $model = User::query()->findOrFail($user);
        [$roleId, $scopeType, $scopeId] = $this->validatedAssignment($request);
        $this->assignments->revoke($model->id, $roleId, $scopeType, $scopeId, $actor);

        return back()->with('status', DB::table('roles')->where('id', $roleId)->value('name').' removed.');
    }

    public function deactivate(Request $request, string $user): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, self::PERMISSION);
        $model = User::query()->findOrFail($user);
        $this->users->deactivate($model, $actor);

        return back()->with('status', "{$model->name} can no longer sign in.");
    }

    public function reactivate(Request $request, string $user): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, self::PERMISSION);
        $model = User::query()->findOrFail($user);
        $this->users->reactivate($model, $actor);

        return back()->with('status', "{$model->name} can sign in again.");
    }

    public function resendInvitation(Request $request, string $user): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, self::PERMISSION);
        $model = User::query()->findOrFail($user);
        $this->users->resendInvitation($model, $actor);

        return back()->with('status', "Invitation sent to {$model->email}.");
    }

    /** Holding conflicting permissions is not the action-time SOD_CONFLICT ("you took part in an earlier step"), so it gets its own reason. */
    public static function reasonFor(SodViolation $violation): string
    {
        return $violation->reasonCode === 'SOD_CONFLICT' ? 'ROLE_CONFLICT' : $violation->reasonCode;
    }

    /** "Selim Manager cannot hold platform.manage_roles together with accounting.approve_journal (segregation of duties). Remove one of the roles first." */
    public static function conflictSentence(SodViolation $violation, string $userName, string $roleName): string
    {
        return $violation->reasonCode === 'AUDITOR_WRITE_PERMISSION'
            ? "An auditor stays read-only, so {$userName} cannot hold the Auditor role together with {$violation->permission}."
            : "{$userName} cannot hold {$violation->permission} together with {$violation->conflictingPermission} (segregation of duties). Remove one of the roles first.";
    }

    /** @return array{0: string, 1: string, 2: string} role id, scope type, scope id (the tenant for tenant-wide roles) */
    private function validatedAssignment(Request $request): array
    {
        /** @var array{role_id: string, scope_type: string, scope_id?: string|null} $data */
        $data = $request->validate([
            'role_id' => ['required', 'uuid', Rule::exists('roles', 'id')],
            'scope_type' => ['required', Rule::in(['tenant', 'entity', 'branch'])],
            'scope_id' => ['exclude_if:scope_type,tenant', 'required', 'uuid',
                Rule::when($request->input('scope_type') === 'entity', [Rule::exists('legal_entities', 'id')], [Rule::exists('branches', 'id')])],
        ], ['scope_id.exists' => 'Choose an entity or branch of this organisation.', 'scope_id.required' => 'Choose where the role applies.']);

        return [$data['role_id'], $data['scope_type'], $data['scope_type'] === 'tenant' ? TenantContext::id() : ($data['scope_id'] ?? '')];
    }

    /** @return list<array{role_id: string, role: string, scope_type: string, scope_id: string, scope: string}> */
    private function assignmentsOf(string $userId): array
    {
        $entities = DB::table('legal_entities')->get(['id', 'code', 'name'])->keyBy('id');
        $branches = DB::table('branches')->get(['id', 'code', 'name'])->keyBy('id');

        return array_values(DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $userId)->orderBy('r.name')
            ->get(['ur.role_id', 'r.name', 'ur.scope_type', 'ur.scope_id'])
            ->map(function (object $row) use ($entities, $branches): array {
                $place = match ((string) $row->scope_type) {
                    'entity' => $entities->get($row->scope_id),
                    'branch' => $branches->get($row->scope_id),
                    default => null,
                };
                $scope = $place === null ? 'Whole organisation' : ucfirst((string) $row->scope_type)." {$place->code} · {$place->name}";

                return ['role_id' => (string) $row->role_id, 'role' => (string) $row->name, 'scope_type' => (string) $row->scope_type, 'scope_id' => (string) $row->scope_id, 'scope' => $scope];
            })->all());
    }
}
