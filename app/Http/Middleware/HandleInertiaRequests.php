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
            'auth' => ['user' => $user instanceof User ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null],
            'tenant' => fn (): ?array => self::tenant(),
            'status' => fn (): mixed => $request->hasSession() ? $request->session()->get('status') : null,
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
