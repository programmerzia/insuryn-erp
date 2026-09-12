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
| 0.1 | DocumentNumberer | pending | |
| 0.2 | Audit service | pending | |
| 0.3 | Fiscal period service | pending | |
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
