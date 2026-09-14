<?php

declare(strict_types=1);

namespace App\Http\Feedback;

use App\Modules\Platform\Authorization\PermissionCatalogue;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Gap fix GA-07: a refused, missing, expired or broken page stays inside the application (UX brief §4) — the shell, a title, the language, what
 * happened, "Back" and, when access is missing, who can give it. Browser requests only; JSON and API responses keep their machine-readable bodies.
 */
final class ErrorPage
{
    /** Marks a request whose error response is already this page, so the final response hook leaves it alone. */
    public const RENDERED = 'erp.error_page';

    public const STATUSES = [403, 404, 419, 500, 503];

    private const WORDS = [
        403 => ['You do not have access to this page', 'Your roles do not include what this page needs.'],
        404 => ['Page not found', 'The page or record does not exist, or it was removed. Check the link or search for the record.'],
        419 => ['This page expired', 'You were away for a while, so the form was not sent. Go back, refresh the page and try again.'],
        500 => ['Something went wrong', 'The page could not be shown. Nothing you entered was lost on our side; try again in a moment, and tell your administrator if it keeps happening.'],
        503 => ['Down for maintenance', 'The application is being updated. Try again in a few minutes.'],
    ];

    public static function render(Request $request, int $status, ?string $permission = null, ?Throwable $e = null): Response
    {
        $request->attributes->set(self::RENDERED, true);
        $codes = $permission === null || $permission === '' ? [] : array_values(array_filter(explode('|', $permission), fn (string $code): bool => $code !== ''));
        [$title, $message] = self::WORDS[$status] ?? self::WORDS[500];
        $referer = $request->headers->get('referer');
        $back = is_string($referer) && parse_url($referer, PHP_URL_HOST) === $request->getHost() && $referer !== $request->fullUrl() ? $referer : null;

        return Inertia::render('errors/Error', [
            'status' => $status,
            'title' => $title,
            'message' => $message,
            // PermissionChecker::authorizeAny names every permission that would open the page, joined by "|".
            'permissions' => array_map(fn (string $code): array => ['code' => $code, 'label' => PermissionCatalogue::label($code), 'help' => PermissionCatalogue::help($code)], $codes),
            'access' => $status === 403 && $request->user() !== null ? self::whoGrantsAccess($codes, $request) : null,
            'back' => $back,
        ])->toResponse($request)->setStatusCode($status);
    }

    /**
     * Who to ask: the active users who manage users or roles in this organisation, and the roles that already hold the missing permission.
     *
     * @param list<string> $permissions any of which would open the page
     * @return array{admins: list<array{name: string, email: string}>, roles: list<string>, subject: string}|null
     */
    private static function whoGrantsAccess(array $permissions, Request $request): ?array
    {
        if (! TenantContext::has()) {
            return null;
        }
        $admins = [];
        foreach (DB::table('users as u')->join('user_roles as ur', 'ur.user_id', '=', 'u.id')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->whereIn('rp.permission_code', ['platform.manage_users', 'platform.manage_roles'])->where('u.status', 'active')->where('u.kind', '<>', 'portal')
            ->distinct()->orderBy('u.name')->limit(3)->get(['u.name', 'u.email']) as $admin) {
            $admins[] = ['name' => (string) $admin->name, 'email' => (string) $admin->email];
        }
        $roles = $permissions === [] ? [] : array_values(DB::table('roles as r')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->whereIn('rp.permission_code', $permissions)
            ->where('r.code', 'not like', 'test-%')->distinct()->orderBy('r.name')->pluck('r.name')->map(fn (mixed $name): string => (string) $name)->all());
        $what = count($permissions) === 1 ? PermissionCatalogue::label($permissions[0]) : '/'.ltrim($request->path(), '/');

        return ['admins' => $admins, 'roles' => $roles, 'subject' => "Access request: {$what}"];
    }
}
