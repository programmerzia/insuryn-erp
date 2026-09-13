#!/usr/bin/env bash
# CI frontend gate: type check, Vitest (components, table and form logic, theme contrast) and the production build.
set -euo pipefail
cd "$(dirname "$0")/../.."

npm run typecheck
npm test
npm run build
