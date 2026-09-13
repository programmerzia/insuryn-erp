<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

/** Inertia root view and the props every page receives: the signed-in user, the resolved tenant and a status flash. */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => ['user' => $user instanceof User ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
                'permissions' => fn (): array => $user instanceof User ? app(\App\Modules\Platform\Authorization\PermissionChecker::class)->permissionsOf($user->id) : []],
            'tenant' => fn (): ?array => self::tenant(),
            'preferences' => fn (): array => $user instanceof User ? app(\App\Modules\Platform\Preferences\UserPreferences::class)->of($user->id) : \App\Modules\Platform\Preferences\UserPreferences::DEFAULTS,
            'shell' => fn (): ?array => $user instanceof User ? self::shell($user->id) : null,
            'status' => fn (): mixed => $request->hasSession() ? $request->session()->get('status') : null,
            // UX brief §4: a reversible action's confirmation carries its undo (label and the POST that reverses it).
            'undo' => fn (): mixed => $request->hasSession() ? $request->session()->get('undo') : null,
        ];
    }

    /**
     * Application shell data (UX brief §3): the entity and its branches for the switcher, and approvals waiting for this user (notifications).
     * Sidebar badge counts join here in slice U6.
     *
     * @return array{entity: array{code: string, name: string, currency: string}|null, branches: list<array{id: string, code: string, name: string}>, approvals: int, badges: array<string, int>}
     */
    private static function shell(string $userId): array
    {
        $entity = DB::table('legal_entities')->orderBy('code')->first(['code', 'name', 'base_currency']);
        $branches = [];
        foreach (DB::table('branches')->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']) as $branch) {
            $branches[] = ['id' => (string) $branch->id, 'code' => (string) $branch->code, 'name' => (string) $branch->name];
        }

        return [
            'entity' => $entity === null ? null : ['code' => (string) $entity->code, 'name' => (string) $entity->name, 'currency' => (string) $entity->base_currency],
            'branches' => $branches,
            'approvals' => count(app(\App\Modules\Platform\Approvals\ApprovalInboxQuery::class)->decidableBy($userId)),
            'badges' => app(\App\Http\Home\WorkQueues::class)->badges($userId),
        ];
    }

    /** @return array{id: string, name: string, slug: string}|null */
    private static function tenant(): ?array
    {
        if (! TenantContext::has()) {
            return null;
        }
        $tenant = DB::table('tenants')->where('id', TenantContext::id())->first(['id', 'name', 'slug']);

        return $tenant === null ? null : ['id' => (string) $tenant->id, 'name' => (string) $tenant->name, 'slug' => (string) $tenant->slug];
    }
}
