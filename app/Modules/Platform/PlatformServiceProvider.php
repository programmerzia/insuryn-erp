<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Models\User;
use App\Modules\Platform\Approvals\ApprovalHandlerRegistry;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Documents\Generation\DocumentDataProvider;
use App\Modules\Platform\Documents\Generation\DocumentDataProviders;
use App\Modules\Platform\Documents\Rendering\ChromePdfRenderer;
use App\Modules\Platform\Documents\Rendering\PdfRenderer;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Register in bootstrap/providers.php, before modules that depend on Platform. */
final class PlatformServiceProvider extends ServiceProvider
{
    /** @var array<string, bool> permission code => in catalogue, memoised per process */
    private static array $catalogue = [];

    private static function isCataloguePermission(string $ability): bool
    {
        if (! str_contains($ability, '.')) {
            return false;
        }

        return self::$catalogue[$ability] ??= DB::table('permissions')->where('code', $ability)->exists();
    }

    public function register(): void
    {
        $this->app->singleton(ApprovalHandlerRegistry::class);
        // Slice R8: documents. PDFs through headless Chromium (D-34); data providers are tagged by the business contexts.
        $this->app->bind(PdfRenderer::class, ChromePdfRenderer::class);
        $this->app->singleton(DocumentDataProviders::class, fn ($app): DocumentDataProviders => new DocumentDataProviders($app->tagged(DocumentDataProvider::class)));
    }

    public function boot(): void
    {
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            TenantContext::reapplyTo($event->connection);
        });

        // Design §7.1: a catalogue permission code is a Gate ability (`can:policy.issue`, Gate::allows).
        // The optional first argument is an AuthorizationScope. Other abilities fall through to policies.
        Gate::before(static function (mixed $user, string $ability, array $arguments): ?bool {
            if (! $user instanceof User || ! self::isCataloguePermission($ability)) {
                return null;
            }
            $scope = ($arguments[0] ?? null) instanceof AuthorizationScope ? $arguments[0] : null;

            return app(PermissionChecker::class)->has($user->id, $ability, $scope);
        });
    }
}
