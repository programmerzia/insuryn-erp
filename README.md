# Insurance ERP — Phase 0 kernel skeleton

This is a **file overlay**, not a runnable app yet. Bootstrap on your PC:

```bash
composer create-project laravel/laravel insurance-erp && cd insurance-erp
# copy everything from this overlay into the project root (overwrite CONTEXT.md, tests/Pest.php, phpstan.neon, docker-compose.yml)
composer require brick/money symfony/expression-language laravel/horizon laravel/socialite
composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan
# .env: DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=erp DB_USERNAME=erp_app DB_PASSWORD=erp
#       MIGRATION_DB_USERNAME=erp_owner MIGRATION_DB_PASSWORD=erp   (see config/database note below)
docker compose up -d
php artisan migrate --seed
./vendor/bin/pest
./vendor/bin/phpstan analyse
```

Notes
- Default Laravel psr-4 `App\` → `app/` already covers `App\Modules\...`; no composer change needed.
- Register `App\Modules\Platform\Http\Middleware\ResolveTenant` in `bootstrap/app.php` (web + api).
- Migrations must run as the DB owner (`erp_owner`); the app runs as `erp_app` so RLS is enforced. Simplest: add a second `pgsql_migrations` connection in `config/database.php` using MIGRATION_DB_* and run `php artisan migrate --database=pgsql_migrations`.
- Read `CONTEXT.md`, then `docs/design-package-v1.md`. Build order: §9.3.
- Nothing here has been executed. Run migrations and `tests/Feature/Accounting/*` first; fix what trips.
