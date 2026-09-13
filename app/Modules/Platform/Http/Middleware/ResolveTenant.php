<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Must run BEFORE authentication: `users` is itself tenant-scoped (RLS), so the tenant has to be
 * known from the host / OIDC org claim / API token before a user row can be loaded.
 * Resolution order: X-Tenant header (local/testing only) → session (web) → subdomain matching tenants.slug →
 * config erp.tenancy.default_slug (single-install deployments and local browsing). Zitadel org claim LATER.
 * Slugs resolve through the platform `tenants` table, which is not RLS-protected.
 */
final class ResolveTenant
{
    private const SLUG_CACHE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolve($request);
        if ($tenantId === null || ! Str::isUuid($tenantId)) {
            abort(400, 'Tenant could not be resolved.');
        }
        TenantContext::set($tenantId);
        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }

    private function resolve(Request $request): ?string
    {
        if (app()->environment(['local', 'testing']) && $request->hasHeader('X-Tenant')) {
            return $request->header('X-Tenant');
        }
        $tenantId = $request->hasSession() ? $request->session()->get('tenant_id') : null;
        if (is_string($tenantId)) {
            return $tenantId;
        }
        $subdomain = self::subdomain($request->getHost());
        $fromSubdomain = $subdomain === null ? null : $this->tenantIdForSlug($subdomain);
        if ($fromSubdomain !== null) {
            return $fromSubdomain;
        }
        $defaultSlug = config('erp.tenancy.default_slug');

        return is_string($defaultSlug) && $defaultSlug !== '' ? $this->tenantIdForSlug($defaultSlug) : null;
    }

    /** The first label of a host with at least two labels (acme.insuryn.test → acme); none for IP addresses or bare hosts. */
    private static function subdomain(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || substr_count($host, '.') < 1) {
            return null;
        }

        return strtolower(explode('.', $host)[0]);
    }

    /** Found slugs are cached for five minutes; misses are not cached, so a new tenant resolves immediately. */
    private function tenantIdForSlug(string $slug): ?string
    {
        $key = 'tenancy.slug.'.$slug;
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }
        $tenantId = DB::table('tenants')->where('slug', $slug)->value('id');
        if (! is_string($tenantId)) {
            return null;
        }
        Cache::put($key, $tenantId, self::SLUG_CACHE_SECONDS);

        return $tenantId;
    }
}
