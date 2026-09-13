#!/usr/bin/env bash
# CI: create the application roles and databases on a fresh Postgres 17, exactly as docker compose does on a new volume
# (database/init/01-roles.sql). Connects as the image superuser: PGHOST, PGPORT, PGUSER, PGPASSWORD.
set -euo pipefail

until pg_isready --quiet; do sleep 1; done
psql --no-psqlrc --set ON_ERROR_STOP=1 --dbname postgres --file "$(dirname "$0")/../../database/init/01-roles.sql"
