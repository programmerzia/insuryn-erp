<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Must run BEFORE authentication: `users` is itself tenant-scoped (RLS), so the tenant has to be
 * known from the host / OIDC org claim / API token before a user row can be loaded.
 * Resolution order (MVP): X-Tenant header (dev only) → subdomain → session. Zitadel org claim LATER.
 */
final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolve($request);
        if ($tenantId === null) {
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
        // TODO(Phase 0): map subdomain -> tenants.id via a cached lookup that bypasses RLS (tenants table is not RLS-protected).
        return $request->session()->get('tenant_id');
    }
}
