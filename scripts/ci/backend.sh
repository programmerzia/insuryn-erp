#!/usr/bin/env bash
# CI backend gate (design §9.1: blocks merge): Pest on the real Postgres (invariants, golden rules, idempotency, tenancy, SoD, close,
# property and architecture tests) and PHPStan level 8. Expects dependencies installed, a built frontend (pages render through the
# Vite manifest) and the database prepared by prepare-database.sh. DB_HOST / DB_PORT come from the environment.
set -euo pipefail
cd "$(dirname "$0")/../.."

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --no-interaction
php artisan config:clear --no-interaction

php vendor/bin/pest --colors=always
php vendor/bin/phpstan analyse --no-progress --memory-limit=2G
