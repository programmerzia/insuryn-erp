# Insurance ERP — Phase 0 accounting kernel

Laravel 13 · PHP 8.4 · PostgreSQL 17 with row-level security. Read `CONTEXT.md`, then `docs/design-package-v1.md`. Build order: §9.3.

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
docker compose up -d        # ERP_DB_PORT / ERP_REDIS_PORT override the published ports
php artisan migrate --database=pgsql_migrations --seed
./vendor/bin/pest
./vendor/bin/phpstan analyse
```

## Database roles

- `database/init/01-roles.sql` creates two roles, neither of which is a superuser or can bypass RLS, and the `erp` and `erp_test` databases.
- `erp_owner` owns the schema. It is used only for migrations (`--database=pgsql_migrations`, `MIGRATION_DB_*`) and by the test suite, which also runs migrations.
- `erp_app` is the runtime role (`DB_USERNAME`). RLS is `FORCE`d, so a query without a tenant context returns zero rows.
- `Tests\TestCase` refuses to run as a role that bypasses RLS, because the tenancy invariants would pass vacuously.

## Deployment

Deploy with direct connections or session-mode pooling only; transaction-mode PgBouncer is unsupported until SET LOCAL per request is implemented (LATER). The tenant id is a session-level Postgres setting (docs/DECISIONS.md D-02).

## Notes

- `App\Modules\...` is covered by the default `App\` PSR-4 mapping.
- `ResolveTenant` runs on the `web` and `api` groups, after the session starts and before authentication.
- Tests run against real Postgres and truncate between tests. The invariants are commit-time database behaviour, so they cannot run inside a rolled-back transaction.
