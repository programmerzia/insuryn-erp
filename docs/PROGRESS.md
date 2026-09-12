# Progress log

Written for a reader with no memory of earlier sessions. Read `CONTEXT.md`, `docs/design-package-v1.md`,
`docs/spec-v2.md`, `docs/DECISIONS.md`, then this file.

## How to resume

```bash
# Postgres 17 at 127.0.0.1:5440 (local dev container `prep-postgres`), roles erp_owner / erp_app (password erp),
# databases erp and erp_test. With the repo's docker-compose instead: docker compose up -d and DB_PORT=5432.
composer install && cp .env.example .env && php artisan key:generate   # set DB_PORT in .env
php artisan migrate --database=pgsql_migrations --seed
./vendor/bin/pest                    # PHP 8.5 default; also run: php8.4 vendor/bin/pest
./vendor/bin/phpstan analyse         # level 8, 0 errors expected
git log --oneline | head             # one commit per green slice: feat(<area>): slice N – <name>
```

Next slice to pick up: the first row below whose status is not `done`, in table order.
Rules for every slice: tests first; pest + phpstan green before commit; never weaken/skip/delete a test
(record disputes below and stop the slice); OPEN questions → most conservative option, `ASSUMPTION:` in
code and in the register below, configurable.

## Slice status

| Slice | Name | Status | Commit |
|---|---|---|---|
| 0.0 | Apply review decisions D-01..D-11 | done | see git log |
| 0.1 | DocumentNumberer | done | see git log |
| 0.2 | Audit service | done | see git log |
| 0.3 | Fiscal period service | done | see git log |
| 0.4 | Permissions + SoD | pending | |
| 0.5 | Manual journal + approvals | pending | |
| 0.6 | Read side + first UI | pending | |
| 0.7 | Import wizard | pending | |
| 1A.1 | Party, roles, bank accounts, agents | pending | |
| 1A.2 | Product + versions | pending | |
| 1A.3 | Policy lifecycle | pending | |
| 1A.4 | Installments + earning batch | pending | |
| 1A.5 | Receipts, allocations, suspense, refunds | pending | |
| 1A.6 | Bank | pending | |
| 1A.7 | Commission | pending | |
| 1A.8 | Reconcilers | pending | |
| 1A.9 | Month-end close | pending | |
| 1A.10 | Reports | pending | |
| 1B.1 | Claims | pending | |
| 1B.2 | Claims reconciler + close task 5 | pending | |
| 1B.3 | Claims reports | pending | |

## ASSUMPTION register

Each entry is also marked `ASSUMPTION:` in code at the named location and is configurable there.

| # | Slice | Assumption (conservative choice for an OPEN item) | Where / how to change |
|---|---|---|---|
| A-1 | 0.0 (D-06) | VAT on cancelled premium is refunded by default (`refund_tax_on_cancellation = true`); OPEN #2. | Lands with product versions (1A.2) and the cancellation mapper (1A.3). |

## Disputed tests

None.

## Blocked slices

None.

## Open questions (carried, never guessed)

From design "OPEN questions": 1 carrier vs broker/MGA; 2 tax on premium and cancellation refunds (see A-1);
3 approval thresholds and role mapping; 4 earning method per product and short-rate table; 5 regulator
report formats; 6 opening balance source/import format.

## Slice details

### Previous session (before 0.0) — bring-up, no slice number
Laravel 13 skeleton wired to the overlay; migrations + seed green; test harness truncates between tests
and refuses RLS-bypassing roles; PHPStan level 8 clean; posting engine deepened (claim inside the
transaction, §8.4 failure taxonomy, pure `JournalDraftBuilder`, bulk `JournalWriter`); fixes for ledger
balances with reversed journals, double reversal, numbering race, tenant resolution. 89 tests at hand-off.

### 0.0 — Apply review decisions — done
- Decisions D-01..D-11 recorded in `docs/DECISIONS.md` with their code and test locations.
- Files: `database/migrations/2026_09_13_000001_add_tenant_to_child_tables.php`,
  `app/Modules/Platform/Database/RowLevelSecurity.php`, `app/Models/User.php`, `database/factories/UserFactory.php`,
  `app/Modules/Accounting/Application/Posting/{DatabaseRuleViolation,EventPayloadValidator}.php`,
  `app/Modules/Accounting/Application/PostingEngine.php`, `app/Modules/Accounting/Infrastructure/Jobs/{OutboxRelayJob,PostAccountingEventJob}.php`,
  `resources/posting-rules/{CLAIM_RESERVE_ADJUSTED.default,PAYROLL_POSTED.default}.json`,
  `tests/Fixtures/golden/06b_claim_reserve_adjusted_decrease.json` (authorised change), `CONTEXT.md`, `README.md`,
  `app/Modules/Accounting/Domain/Enums/EventStatus.php`, migration comment for D-01.
- Tests added: `SchemaInvariantsTest`, `OutboxRelayTest`, `UserModelTest`, `DatabaseRuleViolationTest` (feature + unit),
  child-table case in `TenantIsolationTest` (existing cases untouched).
- Found and fixed: `PostAccountingEventJob` used `ShouldBeUnique`, whose lock is taken at dispatch with no
  expiry, so a lost dispatch blocked relay re-delivery forever. Now `WithoutOverlapping` keyed by event id.
- D-06 and D-11 are accepted here and implemented in the slices that own the tables/workflows (1A.2/1A.3, 0.4/0.5).
- Result: 108 tests green, PHPStan 0 errors.

### 0.1 — DocumentNumberer — done
- `App\Modules\Platform\Numbering\DocumentNumberer`: `reserve(scope, reservedBy)` (row-locked atomic
  `UPDATE … RETURNING` on `number_sequences`, own transaction so the number survives a business rollback),
  `markUsed(id, objectType, objectId)` (must run inside the business transaction; reserved → used CAS),
  `void(id, reason, voidedBy)` (reason required; reserved numbers only), `voidExpiredReservations(ttl)`,
  `voidedNumbers(sequenceId)` report, `unexplainedGaps(sequenceId)`.
- Scope = entity, optional branch (null = entity-level), doc_type, fiscal year of the business date
  (`Platform\Tenancy\FiscalCalendar`, now also used by `JournalNumberer`). Format `<PREFIX>-<FY>-<000001>`.
- `ReservationSweeperJob` (design §8.5, every 15 min): per-tenant loop, voids reservations older than
  `erp.numbering.reservation_ttl_minutes` (15) with `void_reason = reservation_expired`.
- Migration `2026_09_13_000002_document_number_integrity`: `sequence_no` + unique(sequence_id, sequence_no),
  status check, `voided_by`, trigger `protect_document_numbers` (no delete, no renumbering, only
  reserved → used | voided) — the §2.1 "no unexplained gap" invariant is enforced by the database.
- Tests: `tests/Feature/Platform/DocumentNumbererTest.php` (sequence per scope + FY reset, used once,
  used only inside a transaction, void with reason, no void of used numbers, expiry sweep across tenants,
  no gap + immutability).
- Interpretation (not an OPEN item): used numbers cannot be voided — cancel the business document instead.
- The `numbering.void` permission check is wired in slice 0.4 (authorization layer did not exist yet).
- Result: 115 tests green, PHPStan 0 errors.

### 0.2 — Audit service — done
- `App\Modules\Platform\Audit\Audit::record(action, AuditSubject, before, after, reason, permission, actor)`:
  writes `audit_events` in the caller's transaction. Actor = explicit `Actor`, else the authenticated user,
  else `system`. On HTTP requests it records `request_id` (X-Request-Id if a UUID, else a UUIDv7 per request),
  `ip`, `user_agent`.
- Migration `2026_09_13_000003_audit_trail_append_only`: `audit_events.permission` (the permission exercised;
  read by SoD in 0.4), index (object_type, object_id, actor_user_id), trigger `audit_events_append_only`
  (UPDATE/DELETE raise `AUDIT_APPEND_ONLY`).
- Wired: `JournalWriter::post` audits `journal.posted` for every posted journal (system, manual, reversal,
  opening — all journal paths go through it; actor = `created_by` when present); `ReversalService` audits
  `journal.reversed` on the original with reason, actor and permission `accounting.reverse_journal`.
  Period changes are audited by the fiscal period service in slice 0.3 (the service did not exist yet).
- Tests: `tests/Feature/Platform/AuditTest.php` (fields, authenticated HTTP actor + request details, system
  actor, append-only, every posted/reversed journal audited, failed posting leaves no audit row).
- Result: 121 tests green, PHPStan 0 errors.

### 0.3 — Fiscal period service — done
- `App\Modules\Accounting\Application\Periods\FiscalPeriodService`: `softLock`, `lock`, `reopen(reason)` per §5.3.
  Each transition: permission (`periods.soft_lock` / `periods.lock` / `periods.reopen`), period row lock,
  allowed-from check (`INVALID_PERIOD_TRANSITION`), audit row with before/after/permission/actor, outbox message
  (`PeriodSoftLocked`, `PeriodLocked`, `PeriodReopened`). `lock` sets `locked_by`/`locked_at`; reopen clears them.
- §5.7 INVARIANT enforced now: `lock` refuses with `CLOSE_TASKS_OPEN` (tasks not done/skipped on a live close run)
  or `RECONCILIATION_VARIANCE`. Reopen marks the period's close runs `reopened` so the close must be re-run.
- New Platform pieces: `Authorization\PermissionChecker` (user_roles → role_permissions; scopes, Gate and SoD come
  in 0.4), `Authorization\PermissionDenied`, `Messaging\Outbox` (transactional outbox writer).
- Tests: `tests/Feature/Accounting/FiscalPeriodServiceTest.php` (happy path with audit + outbox, permission per
  transition, invalid transitions, reopen reason + close-run invalidation, lock blockers, posting after reopen);
  test helper `userWithPermissions()` in `tests/Pest.php`.
- Deferred by design of the slice order: §5.3 says reopen needs *approval*; the approval engine arrives in 0.5,
  which routes reopen through it when an approval policy matches.
- Result: 130 tests green, PHPStan 0 errors.
