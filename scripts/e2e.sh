#!/usr/bin/env bash
# Slice 2.0c: the E2E happy path, end to end (design §9.1, CI-blocking). Locally and in CI:
#   1. migrate:fresh + seed + `php artisan erp:demo` in the database named by DB_DATABASE (it is WIPED, so it must be set explicitly in the
#      environment, never only in .env — ASSUMPTION A-147);
#   2. builds the frontend when public/build/manifest.json is missing;
#   3. starts `php artisan serve` on E2E_PORT (default 8771) and a queue worker, and waits until the demo tenant's sign-in page answers;
#   4. runs tests/e2e/happy-path.mjs against http://<E2E_TENANT>.localhost:<E2E_PORT> (Chrome resolves *.localhost to 127.0.0.1), then
#      tests/e2e/phone-width.mjs (GA-16: the phone-used screens at 400 px);
#   5. stops the server and the worker (by the PIDs it started) and exits with the test's status.
# Failure evidence (screenshots, Playwright traces, console log, server and worker logs) lands in storage/e2e/ (gitignored).
#
#   DB_DATABASE=erp_test_c scripts/e2e.sh          # or: DB_DATABASE=erp_test_c npm run test:e2e
# Environment: DB_DATABASE (required), E2E_PORT, E2E_TENANT (default nonlife), CHROME (default /usr/bin/google-chrome), ERP_ADMIN_PASSWORD,
# E2E_SKIP_SEED=1 to reuse an already seeded database (the test itself is repeatable on the same story).
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -z "${DB_DATABASE:-}" ]; then
    echo "scripts/e2e.sh wipes and re-seeds the database: set DB_DATABASE explicitly (for example DB_DATABASE=erp_test_c)." >&2
    exit 2
fi
PORT="${E2E_PORT:-8771}"
TENANT="${E2E_TENANT:-nonlife}"
OUT=storage/e2e
LOGS="$OUT/logs"

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --no-interaction
php artisan config:clear --no-interaction >/dev/null

if [ -z "${E2E_SKIP_SEED:-}" ]; then
    echo "e2e: fresh database ${DB_DATABASE} with the ${TENANT} demo"
    php artisan migrate:fresh --database=pgsql_migrations --seed --force --no-interaction >/dev/null
    php artisan erp:demo --tenant="$TENANT" --no-interaction
fi

if [ ! -f public/build/manifest.json ]; then
    echo "e2e: building the frontend"
    npm run build >/dev/null
fi

# The test clears storage/e2e when it starts, so the server and worker log to storage/logs/e2e-run and are copied in afterwards.
RUNLOGS=storage/logs/e2e-run
rm -rf "$RUNLOGS" && mkdir -p "$RUNLOGS"
SERVER_PID=""
WORKER_PID=""

# Stops a process we started and every process under it (php artisan serve runs php -S as a child), by PID only.
stop_tree() {
    local pid="$1" child
    [ -n "$pid" ] || return 0
    for child in $(pgrep -P "$pid" 2>/dev/null || true); do stop_tree "$child"; done
    kill "$pid" 2>/dev/null || true
}

cleanup() {
    local status=$?
    stop_tree "$WORKER_PID"
    stop_tree "$SERVER_PID"
    wait 2>/dev/null || true
    mkdir -p "$LOGS"
    cp "$RUNLOGS"/*.log "$LOGS"/ 2>/dev/null || true
    rm -rf "$RUNLOGS"
    if [ "$status" -ne 0 ] && [ -f storage/logs/laravel.log ]; then tail -n 300 storage/logs/laravel.log > "$LOGS/laravel-tail.log" || true; fi
    exit "$status"
}
trap cleanup EXIT INT TERM

echo "e2e: server on 127.0.0.1:${PORT}, queue worker"
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}" php artisan serve --host=127.0.0.1 --port="$PORT" --no-reload >"$RUNLOGS/server.log" 2>&1 &
SERVER_PID=$!
php artisan queue:work --queue=posting,batch,recon,default --sleep=1 --tries=1 >"$RUNLOGS/worker.log" 2>&1 &
WORKER_PID=$!

# Ready when the tenant's sign-in page answers 200: the request goes to 127.0.0.1 with the tenant host, as Chrome sends it.
ready=""
for _ in $(seq 1 60); do
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then echo "e2e: the server exited" >&2; cat "$RUNLOGS/server.log" >&2; exit 1; fi
    code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${TENANT}.localhost:${PORT}" "http://127.0.0.1:${PORT}/login" || true)"
    if [ "$code" = "200" ]; then ready=1; break; fi
    sleep 1
done
if [ -z "$ready" ]; then echo "e2e: the ${TENANT} sign-in page did not answer 200 within 60 seconds (last: ${code:-none})" >&2; exit 1; fi

set +e
node tests/e2e/happy-path.mjs --base "http://${TENANT}.localhost:${PORT}"
status=$?
# Gap audit GA-16: the phone-used screens fit 400 px and the sidebar hides behind the menu button there.
if [ "$status" -eq 0 ]; then
    node tests/e2e/phone-width.mjs --base "http://${TENANT}.localhost:${PORT}"
    status=$?
fi
set -e
exit "$status"
