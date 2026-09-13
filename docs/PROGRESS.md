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
Phase 1C ("make Phase 1 ready for Phase 2") closes spec §4/§11 Phase 1 gaps found after 1B: non-negotiable #9 commission payouts,
spec §4 items outside the 1A/1B slice list, review hardening, and screens for daily operations. Same operating rules as 1A/1B.
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
| 0.4 | Permissions + SoD | done | see git log |
| 0.5 | Manual journal + approvals | done | see git log |
| 0.6 | Read side + first UI | done | see git log |
| 0.7 | Import wizard | done | see git log |
| 1A.1 | Party, roles, bank accounts, agents | done | see git log |
| 1A.2 | Product + versions | done | see git log |
| 1A.3 | Policy lifecycle | done | see git log |
| 1A.4 | Installments + earning batch | done | see git log |
| 1A.5 | Receipts, allocations, suspense, refunds | done | see git log |
| 1A.6 | Bank | done | see git log |
| 1A.7 | Commission | done | see git log |
| 1A.8 | Reconcilers | done | see git log |
| 1A.9 | Month-end close | done | see git log |
| 1A.10 | Reports | done | see git log |
| 1B.1 | Claims | done | see git log |
| 1B.2 | Claims reconciler + close task 5 | done | see git log |
| 1B.3 | Claims reports | done | see git log |
| 1C.1 | Commission payouts (approve → pay, SoD) | done | see git log |
| 1C.2 | Cheque register and bounce handling | done | see git log |
| 1C.3 | Agent cash collection and deposit reconciliation | pending | |
| 1C.4 | Dunning, grace and auto-lapse | pending | |
| 1C.5 | Multi-payer policies | pending | |
| 1C.6 | Hardening: posting/lock race, isolation on every tenant table | pending | |
| 1C.7 | Account security page (2FA, password) | pending | |
| 1C.8 | Operations UI: parties, products, policies | pending | |
| 1C.9 | Operations UI: receipts, suspense, refunds, bank | pending | |
| 1C.10 | Operations UI: claims and commission | pending | |
| 1C.11 | Operations UI: month-end close and reports | pending | |
| 1C.12 | Phase 1 exit pack (customer questions, exit checklist, Phase 2 kickoff) | pending | |

## ASSUMPTION register

Each entry is also marked `ASSUMPTION:` in code at the named location and is configurable there.

| # | Slice | Assumption (conservative choice for an OPEN item) | Where / how to change |
|---|---|---|---|
| A-1 | 0.0 (D-06), 1A.2 | VAT on cancelled premium is refunded by default (`refund_tax_on_cancellation = true`); OPEN #2. | Per product version: `product_versions.tax_profile.refund_tax_on_cancellation` (`ProductCatalogue::addVersion`, `ASSUMPTION:`); mapper in 1A.3. |
| A-2 | 0.5 | Approval thresholds and role mapping are unknown (OPEN #3): no approval policies are seeded. Every manual journal and reversal still needs one checker ≠ maker holding `accounting.approve_journal`; thresholds/steps are data in `approval_policies`. | `ApprovalService` docblock (`ASSUMPTION:`); insert rows into `approval_policies` (object_type `journal`, `journal_reversal`, `fiscal_period_reopen`). |
| A-3 | 0.7 | Opening-balance / COA source format is unknown (OPEN #6): CSV with a header row, dot decimal separator, major units; header names per field are configurable. | `config/erp.php` `imports.*` (`ASSUMPTION:` comment). |
| A-4 | 1A.2 | Earning method per product and short-rate table are unknown (OPEN #4): versions choose `daily_365` or `monthly` (what the earning batch implements); `24ths` and `short_rate_table` are refused, so cancellations are pro-rata. | `EarningMethod::supported()`, `StoreProductVersionRequest` (`ASSUMPTION:`). |
| A-5 | 1A.6 | Bank statement file format is not specified (no bank named; like OPEN #6): CSV with a header row, one signed amount column in major units (positive = money in), dates `Y-m-d`. Header names and date format are configurable. | `config/erp.php` `imports.bank_statement`, `imports.bank_statement_date_format` (`ASSUMPTION:` A-3/A-5 comment). |
| A-6 | 1A.7 | Commission rules beyond a flat rate (tiers, term/year rules, hierarchy overrides — spec §4) are not specified: a plan is one `rate_bp` on premium received plus optional withholding (`withholding_jurisdiction` + `withholding_tax_type` → `tax_rates` with `withholding = true`; a missing rate refuses the allocation, never assumes 0). | `commission_plans` rows (`CommissionPlanService`); migration `2026_09_14_000007` (`ASSUMPTION:`). |
| A-7 | 1A.7 | When both the product version and the agent name a commission plan, which wins is not specified: product version first, then agent. | `config/erp.php` `commission.plan_precedence` (`ASSUMPTION:`), `CommissionPlanResolver`. |
| A-8 | 1A.8, 1C.1 | Subledger balances are computed as of the reconciliation date from dated business rows (`policy_transactions.accounting_date`, `receipt_allocations.posted_on`, `suspense_items.aged_since`, `commission_entries.earned_on` / `paid_on` since 1C.1). Control accounts are those mapped to the subledger's `subledger_controls` roles on the date. | `Insurance\Collections\Application\Reconciliation\*Reconciler`, `Insurance\Commission\Application\CommissionReconciler`. |
| A-9 | 1A.10 | Receivable ageing by installment uses each installment's *current* outstanding amount (payments and cancellation credits are not dated per installment); `as_of` sets days past due and buckets only. The premium subledger reconciliation (A-8) is dated, so control totals are unaffected. | `Insurance\Reports\Application\ReceivableAgeingQuery` (`ASSUMPTION:`). |

## Catalogue extensions and interpretations (not OPEN items)

- Permissions added to the §7.1 "MVP subset" for configuration/CRUD the catalogue does not name: `party.manage` (branch_officer+),
  `agent.manage` (branch_manager), `product.manage`, `bank.manage_accounts`, `commission.manage_plans` (finance_manager, cfo),
  `claim.close` (claims_manager). Seeded by `PermissionsSeeder`, inserted by the slice migrations, mapped in `RoleTemplates`.
- HTTP error contract: `PermissionDenied` → 403 `{reason: PERMISSION_DENIED, permission}`, `SodViolation` → 403 `{reason, rule}`,
  business rule exceptions (Accounting, Approval, Numbering, `Platform\Exceptions\BusinessRuleViolation`) → 422 `{reason}`.
- Business contexts (Insurance, Finance, People) may use Platform and `App\Modules\Accounting\Application` only (arch test).
- Kernel extension points (container tags): `SubledgerReconciler` (premium, suspense, commission, claims) and `CloseTaskCheck` (premium earning,
  suspense review, bank reconciliation); `payload.account_overrides` for `erp.posting.overridable_roles` (bank accounts' GL accounts).
- Permission use where §7.1 names none (run 1A.5–1B.3): receipts with allocations also need `receipt.allocate`; refund reject under
  `receipt.refund_release`; suspense ageing API under `receipt.allocate`; commission statement and all `/api/reports` under `reports.financial`;
  close tasks use their owner's working permission (see 1A.9); claim recoveries under `claim.pay_request`; claim reject/reopen under `claim.approve`.
- Approval object types added: `claim_payment` (approve), `claim_payment_release` (pay), `claim_reopen` — configure limits as `approval_policies`
  rows (A-2); refunds use maker ≠ checker through SodGuard only.
- Reports compute P&L/BS income and expense cumulatively (no year-end close into retained earnings yet); loss ratio incurred is net of recoveries.

## Disputed tests

None. (Review pass: `ReversalApprovalTest` reopen case moved from September to August because the fixed lock guard correctly refuses its unreconciled September — see "Review pass". Slice 1B.2 extended — never weakened — three expectations of 1A.8/1A.9 tests because it adds the claims subledger and close task 5; see 1B.2.)

## Blocked slices

None.

## Open questions (carried, never guessed)

From design "OPEN questions": 1 carrier vs broker/MGA; 2 tax on premium and cancellation refunds (see A-1);
3 approval thresholds and role mapping (see A-2; also the CFO approval on the period lock, 1A.9); 4 earning method per product and
short-rate table (see A-4); 5 regulator report formats; 6 opening balance source/import format (see A-3).

Unknowns met in Phase 1A/1B that the design does not tag OPEN but does not settle either (each handled conservatively and registered):
bank statement format (A-5); commission tiers/term-year rules/hierarchy overrides and plan precedence (A-6, A-7); dated history for
commission payouts and per-installment payments/credits (A-8, A-9). For the customer: which commission payout workflow (`commission.approve`
/ `commission.pay`) and claims reopen/limits policies to configure.

State at end of run: all slices 0.0 → 1B.3 done; nothing partial or blocked; 886 tests green on PHP 8.5 and PHP 8.4 (888 after the review pass), PHPStan level 8 clean, vue-tsc and
vite build green.

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

### 0.4 — Permissions + SoD — done
- `Platform\Authorization\PermissionChecker::has(user, permission, ?AuthorizationScope)`: tenant roles apply
  everywhere; entity roles within the entity and its branches; branch roles only in that branch.
  `permissionsOf(user)` for assignment checks.
- Gate integration (`PlatformServiceProvider`): `Gate::before` answers any ability that is a catalogue permission
  code (so `can:accounting.view_journals` middleware and `Gate::allows('policy.issue', AuthorizationScope)` work);
  other abilities fall through to normal policies.
- `SodGuard::assert(actor, permission, AuditSubject)`: looks for the same actor exercising a conflicting
  permission on the same object in `audit_events.permission` — independent of how many roles grant it.
  Block-mode → `SodViolation`; warn-mode → returned warnings + `sod.warning` audit row. Wildcards (`accounting.*`).
- `RoleAssignmentService::assign(user, role, scopeType, scopeId, actor)`: requires `platform.manage_users`;
  blocks user-level conflicts (warn-mode returns warnings); the auditor role never combines with write
  permissions (`AUDITOR_WRITE_PERMISSION`); audited as `user_role.assigned`.
- `RoleTemplates` (design §7.2, "+" = previous role plus): branch_officer, branch_manager, claims_officer,
  claims_manager, accountant, finance_manager, cfo, auditor, tenant_admin. Seeded for the demo tenant by
  `DatabaseSeeder` (tests call `seedRoleTemplates()`; not in `DemoTenantSeeder` so the 0.0 child-table isolation
  test keeps its exact-count assertion).
- SoD rules seed (§7.3) now includes `platform.manage_roles ✕ accounting.*`. Migration `2026_09_13_000004_sod_rule_scope`
  adds `sod_rules.applies_to` (`user` | `object`) + mode/applies_to checks. Interpretation of §7.3 (not an OPEN
  item): rules marked "(same claim)" / "(same journal)" are `object` rules — the design's own Claims Manager and
  Finance Manager templates hold both sides, so they are enforced per object by SodGuard, not at role assignment.
- `Platform\Numbering\VoidDocumentNumber`: `numbering.void` check + audit (`document_number.voided`) around
  `DocumentNumberer::void` (the slice-0.1 domain method and its tests are unchanged).
- Tests: `tests/Feature/Platform/PermissionsTest.php` (scopes, Gate + `can:` middleware, templates, numbering.void),
  `tests/Feature/Platform/SegregationOfDutiesTest.php` (every §7.3 pair per object, both directions, warn mode,
  role-assignment block, object rules allowed in templates, manage_roles ✕ accounting.*, auditor read-only,
  manage_users required).
- Fresh `migrate --seed` verified on a scratch database.
- Result: 146 tests green, PHPStan 0 errors.

### 0.5 — Manual journal + approvals — done
- Approval engine `App\Modules\Platform\Approvals\ApprovalService`: `request(objectType, objectId, ApprovalFacts, requestedBy, on, context)`
  returns an approval id when an effective policy's condition matches (`min_amount_minor`, `max_amount_minor`, `kinds`;
  strictest wins: most steps, then highest threshold), else null. `decide(approvalId, decider, Decision, reason)`:
  step permission, decider ≠ requester (`MAKER_CHECKER`), one decision per person per approval (`APPROVER_ALREADY_DECIDED`),
  `SodGuard` on the object, reason required to reject; audits `approval.decided` with the step permission; on final
  approval/rejection calls the `ApprovalHandler` registered for the object type (`ApprovalHandlerRegistry`, registered in
  `AccountingServiceProvider::boot`). Platform never depends on Accounting.
- Manual journals `App\Modules\Accounting\Application\ManualJournals\ManualJournalService` (kinds manual | adjustment | opening):
  `create` (permission, ≥2 lines, positive amounts, accounts of the entity and postable, balanced via `JournalDraft`, period not
  locked) → `draft`; `submit` (maker only) → `pending_approval` + approval when a `journal` policy matches; `approve` → policy step
  or single checker (`accounting.approve_journal`, checker ≠ maker, SodGuard) → `postApproved` through `JournalWriter::postDraft`
  (soft-lock needs the approver's `accounting.post_in_soft_locked`); `reject` (reason) → `cancelled`. Audited: journal.created,
  journal.submitted, journal.approved, journal.rejected, journal.posted (actor = approver).
- §6.2 control accounts: lines on `is_control` accounts need kind=adjustment + reason + `accounting.post_to_control` for the maker
  AND for the approver who posts (interpretation: the invariant names "the actor"; both actors of a maker-checker journal must qualify).
- Reversal approval (D-11) `Reversals\ReversalRequestService`: `request` (accounting.reverse_journal, reason, posted journal, one pending
  request per journal) → `approve` (policy `journal_reversal` steps or single checker ≠ requester) → `execute` via
  `ReversalService::reverse(..., approvedBy)`; `reject`. New tenant table `journal_reversal_requests` (RLS).
- §5.3 reopen via approval: `FiscalPeriodService::reopen` now returns an approval id when a `fiscal_period_reopen` policy matches
  (completed by `PeriodReopenApprovalHandler`), else reopens immediately and returns null (0.3 behaviour unchanged).
- `JournalWriter` split into `draft()` + `postDraft(journal, approvedBy)`; `post()` = both. Arch test: `JournalWriter` only used by
  PostingEngine, ReversalService and ManualJournals (non-negotiable #4).
- Migration `2026_09_13_000005_approvals_and_reversal_requests`: approvals.context/decided_at, status/decision checks,
  unique(approval_id, step_no), `journal_reversal_requests`.
- Tests: `ManualJournalTest` (maker≠checker, checker permission, invalid drafts, threshold routing through ordered steps with
  distinct approvers, rejection, control-account rule), `ReversalApprovalTest` (checker ≠ requester, permissions, policy steps,
  rejection, reopen via approval), arch rule in `DependencyTest`. Helper `approvalPolicy()` in `tests/Pest.php`.
- Assumption A-2 (OPEN #3): no approval policies seeded.
- Result: 158 tests green, PHPStan 0 errors; fresh migrate --seed verified.

### 0.6 — Read side + first UI — done
- Stack added: `inertiajs/inertia-laravel` 3.3 (server), `@inertiajs/vue3` 3.7 + Vue 3.5 + TypeScript 5.9 (pinned; TS 7 lacks
  the API vue-tsc uses) + `vue-tsc`, `@vitejs/plugin-vue`, Tailwind 4, shadcn-vue style table components
  (`resources/js/components/ui/table/*`, `cn()` in `resources/js/lib/utils.ts`). `npm run typecheck`, `npm run build`.
- Theme: CoreBari tokens (Navy/Brick/Blueprint, IBM Plex Sans/Condensed/Mono) in `resources/css/app.css` — the only file with hex colours.
- Read models: `Accounting\Application\Queries\JournalQuery` (paginated list with debit totals and status filter; typed detail with lines,
  source event, reverses / reversed_by / corrects links and corrections) and the existing `LedgerQuery::trialBalance`.
- HTTP (thin): `Accounting\Http\Controllers\{TrialBalanceController, JournalController, ReportingScope}` — entity from `?entity_id=`
  (default: first entity by code; §9.2 single-entity UI), primary book. Amounts formatted by `Http\Presenters\MinorUnits`
  (integer string arithmetic, no floats). Routes in `routes/web.php` under `auth` + `can:accounting.view_journals`:
  `/accounting/trial-balance`, `/accounting/journals`, `/accounting/journals/{id}`; `/` redirects to journals.
- Pages: `resources/js/pages/accounting/{TrialBalance, journals/Index, journals/Show}.vue`, layout `layouts/AppLayout.vue`,
  `components/StatusBadge.vue`. Root view `resources/views/app.blade.php`; `HandleInertiaRequests` shares the signed-in user.
- No login page exists (Zitadel OIDC sign-in is not in the slice list): guests get HTTP 401 (`bootstrap/app.php`:
  `redirectGuestsTo` null + AuthenticationException render). Pages need an authenticated session.
- Tests: `tests/Feature/Accounting/LedgerPagesTest.php` (TB figures and balance, list order/kind/totals, status filter, detail with
  lines/event/reversal links both ways, drafts listed but not in TB, 401/403, cross-tenant 404), `tests/Unit/Accounting/MinorUnitsTest.php`.
- Result: 172 tests green, PHPStan 0 errors, vue-tsc 0 errors, vite build OK.

### 0.7 — Import wizard — done
- Spec §7 pipeline: `mode` = `validate` (errors only) | `dry_run` (errors + preview, writes nothing) | `commit`. Errors are
  `{row, field, message}` (row = CSV line; row 0 = file-level, listed last); any error → HTTP 422 and nothing is written.
- `Accounting\Application\Imports\ChartOfAccountsImport` (`accounting.manage_coa`): new accounts only (existing code = error),
  type/side/boolean checks, parent in file or entity, control accounts need a subledger, optional ISO currency, optional semantic
  role (mapped in the primary book from today). Commit inserts accounts (+ mappings) in one transaction; audit `chart_of_accounts.imported`.
- `OpeningBalancesImport` (`accounting.create_manual_journal`): account in entity and postable, exactly one positive side, amounts
  parsed with string arithmetic (`Domain\MinorUnits::fromMajor`), optional branch, file must balance, ≥2 lines. Commit creates a
  kind=`opening` journal through `ManualJournalService` and submits it — it posts only after a different user approves (D-11).
  Opening journals may touch control accounts under the adjustment conditions (reason + `accounting.post_to_control`).
- `Platform\Imports\CsvTable`: header-mapped CSV parsing with configurable header names and a row limit.
- HTTP: JSON API `POST /api/accounting/imports/{chart-of-accounts|opening-balances}` (routes/api.php, now enabled) and page
  `GET /accounting/imports` + `POST /accounting/imports/{type}` (Inertia, `resources/js/pages/accounting/Imports.vue`), both via
  `Accounting\Http\Controllers\ImportController`. API authentication is the default guard; token auth (Sanctum) is not built.
- Refactor: `MinorUnits` moved to `App\Modules\Accounting\Domain\MinorUnits` (format + fromMajor) so Application code does not
  depend on Http; the 0.6 unit test only changed its `use` line.
- Assumption A-3 (OPEN #6): import format.
- Tests: `tests/Feature/Accounting/ImportWizardTest.php` (invalid COA rows reported, dry-run writes nothing, commit with parents/controls/audit,
  invalid/unbalanced opening balances, dry-run then commit as pending opening journal, permissions, page round-trip).
- Result: 179 tests green, PHPStan 0 errors, vue-tsc 0 errors, vite build OK; fresh migrate --seed verified.

### 1A.1 — Party, roles, bank accounts, agents — done
- Module `App\Modules\Insurance\Party` (Domain models/enums, Application services, Http controllers + FormRequests).
- Tables (migration `2026_09_14_000001_create_party_tables`, all tenant + RLS): `parties` (kind check), `party_roles`
  (unique party/role, role check), `party_bank_accounts` (`account_no_enc` via Laravel `encrypted` cast, `account_no_masked`,
  partial unique index: one default per party), `agents` (unique party, unique tenant/code, not-own-parent check).
- `PartyService` (`party.manage`): create/update with role sync, `addBankAccount` (mask = last 4 digits, first account becomes
  default, new default clears the old one). `AgentService` (`agent.manage`): create (adds the `agent` role to the party),
  update, `ancestors` (recursive CTE); reparenting that would create a cycle → 422 `AGENT_HIERARCHY_CYCLE`. All changes audited.
- API (routes/api.php, `auth`): `GET|POST /api/insurance/parties`, `GET|PATCH /api/insurance/parties/{id}`,
  `POST /api/insurance/parties/{id}/bank-accounts`, `GET|POST /api/insurance/agents`, `GET|PATCH /api/insurance/agents/{id}`.
  The account number is never returned; responses carry the mask only.
- Global exception → HTTP mapping added in `bootstrap/app.php` (see "Catalogue extensions and interpretations").
- New arch rules: business contexts use only Accounting's Application layer; Insurance and Finance do not use each other's Domain.
- Tests: `tests/Feature/Insurance/PartyApiTest.php` (multi-role party, validation, role add/remove, encrypted + masked bank
  accounts with single default, permission, tenant isolation, agent hierarchy with ancestors, cycle refusal, unique code, unknown party).
- Result: 188 tests green, PHPStan 0 errors, vue-tsc/build OK.

### 1A.2 — Product + versions — done
- Module `App\Modules\Insurance\Product`. Tables (migration `2026_09_14_000002_create_product_tables`, tenant + RLS): `products`
  (unique tenant/code), `product_versions` (unique product/version, earning-method check, range check, and a
  `btree_gist` EXCLUDE constraint so versions of one product can never overlap in time — `CREATE EXTENSION btree_gist`, a trusted
  extension the database owner may create).
- `ProductCatalogue` (`product.manage`): `createProduct`, `addVersion` (auto version number, overlap check → 422
  `PRODUCT_VERSION_OVERLAP`, tax profile normalised with D-06 `refund_tax_on_cancellation` default true), `endVersion` (end-date an
  open version so a successor can start), `versionOn(product, date)` (half-open ranges; none → `PRODUCT_VERSION_NOT_EFFECTIVE`). Audited.
- Version fields per design: term_months, earning_method, tax_profile {tax_type, jurisdiction, inclusive, refund_tax_on_cancellation},
  commission_plan_id (plans arrive in 1A.7), posting_rule_set (informational: rule selection today uses the `product_code`
  dimension against rule `applies_to`), coverages.
- API: `GET|POST /api/insurance/products`, `GET /api/insurance/products/{id}`, `POST .../versions`, `GET .../versions/resolve?date=`,
  `PATCH .../versions/{version}` (effective_to).
- Assumption A-4 (OPEN #4): only daily_365 / monthly; no 24ths, no short-rate.
- Tests: `tests/Feature/Insurance/ProductCatalogueTest.php` (version resolution by date incl. boundary and no-version date, D-06
  default and override, overlap refused until end-dated, validation, permission, tenant isolation).
- Result: 192 tests green, PHPStan 0 errors.

### 1A.3 — Policy lifecycle — done
- Module `App\Modules\Insurance\Policy`. Tables (migration `2026_09_14_000003_create_policy_tables`, tenant + RLS): `policies`
  (status check, gross = net + tax check, unique tenant/number), `policy_transactions` (append-only source of accounting events),
  `installments` (paid + cancelled ≤ amount check).
- `PolicyLifecycle` per §5.4: `quote` (`policy.create`; product version in force on inception; tax split from `tax_rates` via new
  `Platform\Tax\TaxRates` — a missing rate is refused `TAX_RATE_MISSING`, never assumed 0), `issue` (`policy.issue`; number reserved
  via DocumentNumberer and marked used in the transaction; `new` transaction; installments; `POLICY_ISSUED`), `endorse` (`policy.endorse`;
  issued|active; version+1; delta split; increase → new installment, decrease → credit unpaid installments; `POLICY_ENDORSED`),
  `cancel` (`policy.cancel`; §4.4 amounts; `POLICY_CANCELLED`), `lapse`/`reinstate`, `renew` (marks renewed, creates the renewal quote),
  `activateDue(today)`, `expireDue(today)`. Invalid transitions → 422 `INVALID_POLICY_TRANSITION`. All audited; domain events
  `PolicyIssued`, `PolicyEndorsed`, `PolicyCancelled` dispatched inside the transaction.
- Accounting mapper `PolicyAccountingEvents`: dims branch, product, product_code, lob, channel, policy, customer, agent;
  idempotency `<EVENT>:{policy_transaction_id}`, source = policy transaction, source_version = policy version.
- New rule `resources/posting-rules/POLICY_ENDORSED.default.json` + golden fixtures `01b_policy_endorsed.json`,
  `01c_policy_endorsed_decrease.json` (CONTEXT.md: golden fixture per posting-rule change; existing fixtures untouched).
- Pure domain: `PremiumMath` (half-even integer division, tax split inclusive/exclusive), `EarningSchedule` + `EarningLayer`
  (daily_365 over actual cover days; monthly in equal rounded earning months with the last absorbing the residual — §4.3;
  calendar month credited when an earning month ends; `earnedBefore(cutoff)` pro-rates a part month by days).
- Cancellation (§4.4, D-06): earned = schedule before cancel date; unearned = net − earned; tax reversal = tax × unearned / net when
  `refund_tax_on_cancellation`, else 0 (the engine drops the zero line); receivable credit = min(outstanding installments,
  unearned + tax reversal), credited to installments last-first; refund due = the rest. Amounts stored on the cancellation transaction.
- Ordering note: installment rows are created at issue in this slice (cancellation needs the outstanding receivable); slice 1A.4 adds
  the earning batch (and posts the earning catch-up for cancelled policies).
- API: `POST /api/insurance/policies` (quote), `GET /api/insurance/policies/{id}`, `POST .../{issue|endorse|cancel|lapse|reinstate|renew}`.
- Tests: `tests/Feature/Insurance/PolicyLifecycleTest.php` (issue with number/tax/installments/journal and issue-once, activation/expiry/
  lapse/reinstate/renew and invalid transitions, endorsement journal, §4.4 cancellation amounts and journal, D-06 flag off, missing tax
  rate, API + permissions); golden tests for the new rule. Figures cross-checked with an independent exact-fraction calculation.
- Result: 201 tests green, PHPStan 0 errors.

### 1A.4 — Installments + earning batch — done
- `premium_earning_ledger` (migration `2026_09_14_000004`, tenant + RLS): unique (policy, period, kind), kind = `scheduled` |
  `cancellation_catch_up`. Extension of the design's unique(policy, period): a catch-up may land in an already-earned period.
- `Insurance\Policy\Application\PremiumEarning\PremiumEarningRun::run(periodId)`: open periods only (`PERIOD_NOT_OPEN` otherwise, so the
  ledger never records earning the GL would reject); policies issued/active/expired/lapsed/renewed on cover in the period earn the
  schedule amount for that calendar month; ledger row + `PREMIUM_EARNED` event (key `PREMIUM_EARNED:{policy_id}:{period_id}`) in one
  transaction; `insertOrIgnore` makes reruns insert and post nothing. Chunked 500 policies.
- `CatchUpEarningOnCancellation` (listener on `PolicyCancelled`, same transaction): posts earned-to-date − Σ ledger in the cancellation
  period (negative when a month was earned in full but cover stopped inside it). Result: the policy's unearned premium GL balance is 0.
- `PremiumEarningJob` (batch queue, nightly 01:00, per tenant): `activateDue`, `expireDue`, then earns every open period that has ended
  (current period is not earned early). Schedule registered in `routes/console.php` with the outbox relay (every second) and the
  reservation sweeper (every 15 min). New `Insurance\Providers\InsuranceServiceProvider` wires listeners.
- `InstallmentQuery::overdue(entity, asOf)`: unpaid installments past due with outstanding and days overdue.
- `Accounting\Application\Queries\FiscalPeriodQuery` (+ `FiscalPeriodView`): how business modules read periods (arch rule).
- Interpretation: a lapsed policy keeps earning until cancelled (it is still on the books); monthly earning months are credited in the
  calendar month they end; daily_365 uses actual cover days so leap-year terms stay exact.
- Tests: `tests/Unit/Insurance/EarningScheduleTest.php` — property test over 300 seeded random policies (both methods, optional
  endorsement increase/decrease): Σ = net, full earning after expiry, zero before inception, monotonic and bounded; §4.3 worked
  example; month-end crediting. `tests/Feature/Insurance/PremiumEarningTest.php` — full-term Σ ledger = net with 12 events and GL
  unearned 0 (both methods), rerun no-op, no earning before cover/for quotes, cancellation catch-up (positive and negative) leaves GL
  unearned 0, nightly job earns ended periods only and activates policies, overdue installments, locked period refused.
- Result: 811 tests green (600 property cases), PHPStan 0 errors.

### 1A.5 — Receipts, allocations, suspense, refunds — done
- Migration `2026_09_14_000005_create_collections_tables` (all tenant + forced RLS): `receipts` (unique number per tenant, `value_date`
  business date, `bank_account_id` nullable until 1A.6), `receipt_allocations` (+ `policy_id`, `suspense_item_id` for traceability),
  `suspense_items` (+ `allocated_minor` so an item can be allocated in parts), `refunds` (requested → released | rejected).
- Module `app/Modules/Insurance/Collections`:
  - `ReceiptService::record(RecordReceiptRequest, actor)`: `receipt.create` (and `receipt.allocate` when the receipt carries
    allocations), number `RCT-<FY>-nnnnnn` per branch from `DocumentNumberer`; each allocation pays down an installment and posts
    `PREMIUM_RECEIVED` (key `PREMIUM_RECEIVED:{receipt_allocation_id}`, §4.2); any remainder becomes a suspense item and posts
    `RECEIPT_RECORDED` (key `RECEIPT_RECORDED:{receipt_id}`, dims branch + receipt, §4.9). Refusals: `INVALID_AMOUNT`,
    `ALLOCATION_EXCEEDS_RECEIPT`, `ALLOCATION_EXCEEDS_OUTSTANDING`, `CURRENCY_MISMATCH` — all before commit, nothing written.
  - `SuspenseService::allocate(item, installment, amount, actor, on)`: `receipt.allocate`; posts `RECEIPT_ALLOCATED`
    (key `RECEIPT_ALLOCATED:{receipt_allocation_id}`) on the later of `on` and the receipt value date; `SUSPENSE_NOT_OPEN`,
    `ALLOCATION_EXCEEDS_SUSPENSE`. Receipt status follows remaining open suspense.
  - `RefundService::request/release/reject`: `receipt.refund_request` then `receipt.refund_release` by someone else (SodGuard on the
    refund's audit history, §7.3); release posts `REFUND_ISSUED` (key `REFUND_ISSUED:{refund_id}`, §4.4 event B) on the paid date.
    Amount capped at Σ cancellation `refund_due` − refunds requested or released (`REFUND_EXCEEDS_DUE`). Rejection needs a reason and
    frees the amount again.
  - `SuspenseQuery::ageing(?entity, asOf)`: open suspense received on/before asOf, buckets 0-30 / 31-60 / 61-90 / 90+ days.
  - Domain event `ReceiptAllocated` (both allocation paths, same transaction) for commission (1A.7).
  - `CollectionsAccountingEvents` mapper: payloads carry `receipt_id`, `receipt_number`, `reference`, `bank_account_id` for bank matching.
- API: `POST /api/insurance/receipts`, `GET receipts/{id}`, `GET suspense/ageing`, `POST suspense-items/{id}/allocate`,
  `POST policies/{id}/refunds`, `POST refunds/{id}/release|reject`.
- Interpretations: allocating at receipt time needs `receipt.allocate` as well as `receipt.create` (§7.2 branch officers create,
  managers allocate); a refund reject is decided under `receipt.refund_release`; the suspense ageing endpoint needs `receipt.allocate`.
- Tests `tests/Feature/Insurance/CollectionsTest.php`: §4.2 journal + key + installment paid; §4.9 suspense then two part allocations
  (journals, statuses, over-allocation refused); split receipt; refusals write nothing; ageing buckets/days; refund SoD (requester
  holding both permissions is blocked), cap, `REFUND_ISSUED` journal and date; reject frees the due; API permissions.
- Result: 819 tests green, PHPStan 0 errors.

### 1A.6 — Bank — done
- Migration `2026_09_14_000006_create_bank_tables` (tenant + forced RLS): `bank_accounts` (unique entity + GL account, status
  active|closed), `bank_statement_lines` (signed `amount_minor`, positive = money in; `line_hash` unique per bank account;
  `match_status` unmatched|matched|explained with `explanation`), `bank_matches` (`journal_line_id` unique: a ledger line matches
  at most one statement line; method auto|manual).
- Kernel: `Accounting\Application\Posting\AccountOverrides` (used by `PostingEngine::postToBook`): `payload.account_overrides
  {role: account_id}` accepted only for roles in `erp.posting.overridable_roles` (default `['bank_main']`) and an active postable
  account of the event's entity; otherwise the event fails `INVALID_ACCOUNT_OVERRIDE` and nothing posts (§4.2 "bank_accounts.gl_account_id
  overrides role"). Read side for business modules: `Accounting\Application\Queries\AccountLineQuery` (`account()` → `AccountView`,
  `postedLines`, `postedLinesByIds`: lines of standing posted journals, reversed pairs excluded, signed debit-positive, with the event's
  `reference` / `receipt_number`).
- `Platform\Money\MinorUnits` (moved from the kernel so every context can parse money; `Accounting\Domain\MinorUnits` delegates to it)
  gains `fromSignedMajor`.
- Module `app/Modules/Finance/Bank`:
  - `BankAccountService::create` (`bank.manage_accounts`): GL account must be an active, postable, non-control asset account of the
    entity and currency (`INVALID_GL_ACCOUNT`). `BankAccountQuery::glAccountFor` (`INVALID_BANK_ACCOUNT`) is how Collections learns it.
  - `StatementImport::import` (`bank.import`): CSV per A-5; all rows validated first (any error → nothing imported, errors by file
    line); `insertOrIgnore` on `line_hash` = sha256(account, date, amount, reference, description, occurrence number of identical rows
    in the file) — re-imports and overlapping statements add only new lines, repeated identical charges are kept.
  - `BankMatcher::autoMatch(bankAccount)`: same signed amount + posting date within `erp.bank.auto_match_date_window_days` (default 3)
    + the journal's reference or receipt number (≥ 4 alphanumerics, case/punctuation-insensitive) in the statement reference/description;
    only one-to-one unambiguous pairs match. `match(line, journalLineIds, actor)` (`bank.match`): one statement line to one or more
    posted lines on the bank's GL account summing to it (`MATCH_AMOUNT_MISMATCH`, `JOURNAL_LINE_NOT_IN_BANK_ACCOUNT`,
    `JOURNAL_LINE_ALREADY_MATCHED`, `ALREADY_MATCHED`). `explain(line, reason, actor)` for lines with no ledger counterpart.
  - `BankReconciliationQuery::unmatched(bankAccount, asOf)`: unmatched statement lines + unmatched ledger lines (close task 3 input).
- Collections: receipts with `bank_account_id` are validated against the bank account (entity, currency, active) and their events carry
  `account_overrides.bank_main` = the bank's GL account (refunds too, when a bank account is set).
- API (`/api/finance`): `POST bank-accounts`, `POST bank-accounts/{id}/statements` (multipart `file`), `POST bank-accounts/{id}/auto-match`,
  `GET bank-accounts/{id}/unmatched`, `POST bank-statement-lines/{id}/match|explain`.
- Interpretations: auto-match never guesses between several candidates; the matcher (not import) runs auto-match so a user sees the
  import result first; bank statement lines are not journal postings (bank charges still need a manual journal — explaining a line
  records why it has no ledger counterpart).
- Tests: `tests/Feature/Accounting/AccountOverrideTest.php` (override posts; 5 refusals fail the event), `tests/Feature/Finance/BankReconciliationTest.php`
  (GL account rules + permission; receipt posts to the bank's GL account; idempotent import incl. re-import and overlap with repeated
  lines; invalid file imports nothing; auto-match window/reference/idempotent; manual many-to-one + refusals + explain + queue; API permissions).
- Result: 832 tests green, PHPStan 0 errors.

### 1A.7 — Commission — done
- Migration `2026_09_14_000007_create_commission_tables` (tenant + forced RLS): `commission_plans` (unique code, `rate_bp` 0..10000,
  withholding jurisdiction/tax type both or neither) and `commission_entries` per design §2.4 plus `entity_id`, `branch_id`,
  `policy_transaction_id`, `commission_plan_id`, `currency`, `earned_on`. CHECKs: kind/status values, clawback ⇔ negative amount,
  |withholding| ≤ |amount|. Partial unique indexes: one `earned` entry per receipt allocation, one `clawback` per cancellation per agent.
- New posting rule `resources/posting-rules/COMMISSION_CLAWBACK.default.json` (§4.4 event C, the §4.5 mirror): DR commission_payable
  (amount − withholding), DR commission_withholding_payable (withholding), CR commission_expense (amount); key
  `COMMISSION_CLAWBACK:{commission_entry_id}`. New golden fixture `tests/Fixtures/golden/05b_commission_clawback.json` (existing fixtures untouched).
- `Platform\Tax\TaxRates::withholdingRateOn` (rates with `withholding = true`).
- Module `app/Modules/Insurance/Commission`:
  - `CommissionPlanService::create` (`commission.manage_plans`): `INVALID_RATE`, `INVALID_WITHHOLDING`, `DUPLICATE_PLAN_CODE`.
  - `CommissionPlanResolver` (A-7). `EarnCommissionOnAllocation` listens to Collections' `ReceiptAllocated` (direct and suspense
    allocations): base = amount allocated (design §4.5 "10% on 50,000 received"), amount = base × rate half-even, withholding = amount ×
    withholding rate half-even; entry `accrued`; posts `COMMISSION_EARNED` (payload base, rate_bp, withholding_bp; key
    `COMMISSION_EARNED:{commission_entry_id}`) on the allocation's posting date, same transaction. No agent / no plan / zero amount → nothing.
  - `ClawBackCommissionOnCancellation` listens to `PolicyCancelled`: per agent, Σ earned amount (and withholding, base) × unearned
    remaining / net premium, half-even, as one negative `clawback` entry dated the cancellation date, posting `COMMISSION_CLAWBACK`.
  - `CommissionStatementQuery::statement(agent, from, to)`: entries with policy number, totals (earned, clawback, withholding, net), opening
    and closing payable (Σ amount − withholding of unpaid entries).
- API: `POST /api/insurance/commission-plans`, `GET /api/insurance/agents/{agent}/commission-statement?from&to` (`reports.financial`).
- Interpretations: earned entries keep their status when clawed back (the negative entry nets on the next statement, §5.6); approval and
  payout of commission (`commission.approve` / `commission.pay`) are not in this slice's list and are not built.
- Tests `tests/Feature/Insurance/CommissionTest.php`: §4.5 amounts, lines and key; suspense allocation date; direct/no-plan → nothing;
  precedence default and configured; clawback amounts/lines/date and GL commission_payable per agent == Σ entries net; no clawback without
  commission; plan validation and permission; agent statement totals and opening/closing payable; API permissions.
- Result: 842 tests green (incl. new golden fixture), PHPStan 0 errors.

### 1A.8 — Reconcilers — done
- Kernel:
  - `Accounting\Application\Contracts\SubledgerReconciler` gains `itemDimension()` (the journal-line dimension tying control-account lines
    to subledger items). Implementations are container-tagged with the interface; `AccountingServiceProvider` gives the tagged set to
    `ReconciliationService` (the kernel never names a business module).
  - `Accounting\Application\Reconciliation\ReconciliationService::runAll(periodId, ?asOf)` / `run(reconciler, periodId, ?asOf)`: GL =
    normal-side balance of the accounts mapped to the subledger's `subledger_controls` roles, as of the period end (or `asOf`);
    variance = subledger − GL; writes `reconciliation_runs` (clean|variance). Variance → `reconciliation_exceptions` per object whose
    subledger vs GL amounts differ (object_type = the dimension, e.g. `policy`), plus one per journal whose control-account lines lack the
    dimension (object_type `journal`). A clean rerun marks the period's earlier variance runs for that subledger `resolved` with a note.
    `FiscalPeriodService::lock` already refuses a period with a `variance` run (`RECONCILIATION_VARIANCE`).
  - `LedgerQuery::normalBalanceByDimension(accountIds, book, asOf, dimension)`.
  - `Accounting\Infrastructure\Jobs\ReconciliationJob` (queue `recon`, scheduled daily 02:00 in `routes/console.php`): per tenant, every
    started period that is not locked, as of its end or today.
- Insurance reconcilers (A-8), tagged in `InsuranceServiceProvider::register`:
  - `PremiumReconciler` (`premium` → premium_receivable, items per `policy`): issue/endorsement premium deltas − cancellation
    `receivable_outstanding` − premium allocated, by accounting date.
  - `SuspenseReconciler` (`suspense` → suspense_receipts, items per `receipt`): suspense received − suspense allocated, by date.
  - `CommissionReconciler` (`commission` → commission_payable, items per `agent`): Σ amount − withholding of unpaid entries by `earned_on`.
- Migration `2026_09_14_000008_add_subledger_dates`: `policy_transactions.accounting_date` (issue date for `new`, effective date otherwise;
  set by `PolicyLifecycle::record`) and `receipt_allocations.posted_on` (set by `InstallmentAllocator`), both NOT NULL after backfill.
- Tests `tests/Feature/Insurance/SubledgerReconciliationTest.php`: the three reconcilers are registered; a quarter of real activity
  (two policies, partial and suspense allocations across month end, endorsement, cancellation, commission) reconciles clean (variance 0)
  at three month ends with the expected September balances; a manual adjustment on premium_receivable (with and without a policy
  dimension) produces variance −100,000 with a `policy` exception and a `journal` exception, other subledgers clean, period lock refused;
  correcting journals reconcile clean and a clean rerun resolves the variance run; the nightly job runs 4 periods × 3 subledgers clean.
- Result: 846 tests green, PHPStan 0 errors.

### 1A.9 — Month-end close — done
- Kernel (`app/Modules/Accounting/Application/Close`):
  - `CloseTaskCatalogue`: design §5.7 tasks 1 `premium_earning`, 2 `suspense_review`, 3 `bank_reconciliation`, 4 `premium_reconciliation`
    (depends 1), 6 `commission_reconciliation` (depends 1), 8 `accruals`, 13 `trial_balance` (depends every earlier task), 14
    `financial_statements` (13), 15 `sign_off` (13, 14), 16 `period_lock` (15); owner roles from §5.7. Task 5 (claims) is added in 1B.2.
  - `PeriodCloseService`: `start(period)` (`periods.soft_lock`; period open or soft-locked; one running run per period →
    `CLOSE_ALREADY_RUNNING`), `execute(task, actor, ?note)` (the task's permission; run must be `running` → `CLOSE_RUN_NOT_ACTIVE`; not done/
    skipped → `TASK_ALREADY_DONE`; dependencies done or skipped → `DEPENDENCIES_OPEN`; outcome `done` or `blocked` with a JSON result,
    rerunnable; business-rule refusals inside a task become `blocked` with their reason code), `skip(task, reason)` (`REASON_REQUIRED`,
    `TASK_NOT_SKIPPABLE`). Everything audited. Task 16 marks itself done and calls `FiscalPeriodService::lock` in one transaction and
    completes the run — the existing INVARIANT guard (open tasks, reconciliation variance) still decides.
  - `CloseTaskExecutor`: `check` tasks call the tagged `Accounting\Application\Contracts\CloseTaskCheck` for the code (none registered →
    blocked); reconciliation tasks run `ReconciliationService::run` for the subledger (blocked on variance, details with run id and
    variance); task 13 soft-locks the period (§5.7) then checks Σdebit = Σcredit; task 14 stores assets, liabilities, equity, income,
    expense, net profit as of the period end; task 15 requires all other tasks done/skipped and regenerates 13/14 results (so postings made
    under `accounting.post_in_soft_locked` after task 13 are reflected).
  - `CloseRunQuery`, `Http\Controllers\PeriodCloseController`: `POST /api/accounting/periods/{period}/close`, `GET close-runs/{run}`,
    `POST close-tasks/{task}/execute|skip`.
- Business checks (tagged `CloseTaskCheck`): `Insurance\Policy\...\PremiumEarningCloseCheck` (runs `PremiumEarningRun` for the period, then
  blocks on `missingEarning` — policies on cover with a non-zero scheduled amount and no ledger row), `Insurance\Collections\SuspenseReviewCloseCheck`
  (open suspense older than `erp.close.suspense_max_age_days` at period end; waivable by skip with reason), `Finance\Bank\BankReconciliationCloseCheck`
  (statement lines dated ≤ period end neither matched nor explained). New `Finance\Providers\FinanceServiceProvider` (registered in `bootstrap/providers.php`).
- Interpretations: the catalogue has no close permissions, so each task uses its owner's working permission (earning/recon/TB:
  `periods.soft_lock`; suspense: `receipt.allocate`; bank: `bank.match`; accruals: `accounting.create_manual_journal`; statements:
  `reports.financial`; sign-off and lock: `periods.lock`). Only suspense review and accruals are skippable. "Approval by CFO role" on the
  lock is not built (thresholds/role mapping are OPEN #3, A-2; `periods.lock` is required). Automatic re-run of 13/14 on later postings is
  done at sign-off rather than on every posting. Suspense ageing uses the items' current open amount.
- Tests `tests/Feature/Close/MonthEndCloseTest.php`: task list/order/dependencies and single running run; end-to-end clean close (earning
  runs once, soft-lock at 13, TB balances, statements identity, run completed, period locked); suspense blocking, waive by skip, skip
  rules; bank task blocked by an unexplained line up to period end then done after explaining; premium recon variance blocks, trial
  balance waits, lock refused `CLOSE_TASKS_OPEN`; per-task permission, already done, reopened run inactive and a new run can start; API.
- Result: 853 tests green, PHPStan 0 errors.

### 1A.10 — Reports — done
- All read-only JSON under `/api/reports` (`reports.financial`, entity-scoped where an `entity_id` is given). Every row carries drill-down:
  `journals[]` (`journal_id`, number, date, status, `url` = `/accounting/journals/{id}`) or, for account rows, `url` to the account activity
  report whose lines each link to their journal.
- Kernel:
  - `Accounting\Application\Queries\SourceJournalQuery::bySource(sourceType, ids)`: ledger journals (posted/reversed) per business object.
  - `Accounting\Application\Reports\FinancialStatementsQuery` (primary book, normal-side amounts): `profitAndLoss(entity, from, to)`
    (income/expense accounts' movement, totals, net profit), `balanceSheet(entity, asOf)` (assets, liabilities, equity, cumulative current
    earnings; assets = liabilities + equity + current earnings), `accountActivity(entity, account, ?from, to)` (opening, lines, closing).
  - `Accounting\Http\Controllers\FinancialReportController`: `GET profit-and-loss?entity_id&from&to`, `balance-sheet?entity_id&as_of`,
    `accounts/{account}/activity?entity_id&from?&to`.
- Insurance (`app/Modules/Insurance/Reports`):
  - `PremiumRegisterQuery::register(entity, from, to)`: written premium per policy transaction by accounting date (new/endorsement as billed;
    cancellation as return premium −unearned remaining / −tax reversal), product code, branch, agent, customer, totals, journals.
  - `ReceivableAgeingQuery::ageing(entity, asOf)`: unpaid installments bucketed not_due / 1-30 / 31-60 / 61-90 / 90+ days past due (A-9),
    most overdue first, journals of the policy's issue and endorsements.
  - `SuspenseAgeingReport` (wraps `SuspenseQuery`, drills to RECEIPT_RECORDED journals), `CommissionStatementReport` (wraps
    `CommissionStatementQuery`, drills to commission journals).
  - `Http\Controllers\InsuranceReportController`: `GET premium-register`, `receivable-ageing`, `suspense-ageing`, `commission-statement?agent_id&from&to`.
- Tests `tests/Feature/Reports/ReportsTest.php`: register rows/signs/totals/journal drill and date filter; ageing buckets, order, drill and
  an earlier as-of; suspense and commission drill; P&L premium income = earning ledger for the month, account activity reconciles to the
  movement and drills to journals; balance sheet balances and receivable equals GL; every endpoint 403 without and 200 with `reports.financial`.
- Result: 859 tests green, PHPStan 0 errors.

### 1B.1 — Claims — done
- Migration `2026_09_14_000009_create_claims_tables` (all tenant + forced RLS): `claims` (number `CLM-<FY>-nnnnnn` per branch, loss/report
  dates, status per §5.5, current `reserve_minor` + `reserve_version`), `claim_reserves` (append-only history: version, new total, delta,
  kind reserve|adjustment|close_release|reject_release, reason, date — trigger `claim_reserves_append_only` refuses UPDATE/DELETE),
  `claim_payments` (pending_approval → approved → release_requested → release_pending_approval → paid; rejected), `claim_recoveries`.
- New posting rule `resources/posting-rules/CLAIM_CLOSED.default.json` (§4.7: DR claims_outstanding / CR claims_expense `payload.release`,
  key `CLAIM_CLOSED:{claim_id}:{reserve_version}`) and golden fixture `tests/Fixtures/golden/07b_claim_closed.json` (existing fixtures untouched).
- Module `app/Modules/Insurance/Claims`:
  - `ClaimService`: `register(policy, lossDate, description, actor, reportedOn)` (`claim.register`; policy must have been issued and the loss
    fall in cover — inception to expiry, or to the day before a cancellation — `POLICY_NOT_ON_COVER`, `LOSS_OUTSIDE_COVER`,
    `REPORTED_BEFORE_LOSS`); `reserve(claim, newTotal, reason, actor, on)` (`claim.reserve`; first → `CLAIM_RESERVED`
    `{claim_id}:1`, later → `CLAIM_RESERVE_ADJUSTED` delta ±; `RESERVE_UNCHANGED`, `RESERVE_BELOW_APPROVED`); `close` (`claim.close`,
    from approved|paid, `PAYMENTS_OUTSTANDING` while a payment is unsettled; releases reserve − approved as a `close_release` version posting
    `CLAIM_CLOSED` → Σ claims_outstanding per claim = 0); `reject` (from registered|reserved, reason, releases the reserve with
    `CLAIM_RESERVE_ADJUSTED`); `reopen` (closed → reserved; waits for a `claim_reopen` approval policy when one matches); `recover`
    (after payment, `CLAIM_RECOVERED`).
  - `ClaimPaymentService`: `approve(claim, amount, payee, actor, on)` (`claim.approve`; SodGuard claim.reserve ✕ claim.approve on the claim;
    `APPROVAL_EXCEEDS_RESERVE` against reserve − committed; `claim_payment` approval policy by amount → waits, else immediate; posts
    `CLAIM_APPROVED:{claim_payment_id}`), `requestRelease` (`claim.pay_request`, optional bank account), `release(payment, actor, paidOn)`
    (`claim.pay_release`; SodGuard claim.pay_request ✕ claim.pay_release on the payment; `claim_payment_release` approval policy by amount →
    waits, else posts `CLAIM_PAID:{claim_payment_id}` on the paid date). Final approvers pass the claim SoD check too. Rejected approvals:
    payment rejected; rejected release: back to approved.
  - `ClaimReserveBook` (history + event per change), `ClaimAccountingEvents` (dims = policy dims + claim; bank override when a bank account
    is given), approval handlers registered in `InsuranceServiceProvider::boot`.
- API (`/api/insurance`): `POST claims`, `GET claims/{id}` (with reserves and payments), `POST claims/{id}/reserve|close|reject|reopen|recover`,
  `POST claims/{id}/payments`, `POST claim-payments/{id}/request-release|release`.
- Interpretations: `reserve` takes the new total (§4.6 "adjusted to 250,000"); the design's `kind='adjustment', corrects_journal_id=first`
  for reserve adjustments is not applied (events post system journals; the reserve history links versions instead); recoveries use
  `claim.pay_request` (no recovery permission in §7.1); reject and reopen use `claim.approve`; reserve changes are allowed while approved or
  paid (partial payments) but never below the committed amount.
- Tests `tests/Feature/Insurance/ClaimsTest.php`: full §4.6/§4.7 lifecycle with every journal, keys, history and Σ outstanding 0; history
  immutable in the DB; decrease mirror lines and limits; registration cover rules incl. cancellation; SoD on approve and release; approval
  limits at approve and pay via `approval_policies`; recovery rules, close without release, reopen then adjust, reject releases reserve; API.
- Result: 868 tests green (incl. new golden fixture), PHPStan 0 errors.

### 1B.2 — Claims reconciler + close task 5 — done
- `Insurance\Claims\Application\ClaimsReconciler` (subledger `claims`, items per `claim`, tagged in `InsuranceServiceProvider`): per claim
  Σ reserve history deltas recorded on or before the date − Σ payments paid on or before it = open reserve + approved-unpaid (design §6.1),
  against the GL of both control roles of `subledger_controls` `claims` (claims_outstanding + claims_payable, normal side).
- Close: `CloseTaskCatalogue` gains task 5 `claims_reconciliation` (reconciliation kind, owner `claims_accounting`, `periods.soft_lock`,
  no dependencies per §5.7); the trial balance (task 13) now also depends on it.
- Test expectations extended (stricter, not weakened) because this slice adds a subledger and a close task — recorded here as the slice's
  own requirement, not a disputed test: `tests/Feature/Close/MonthEndCloseTest.php` (task list includes `[5, 'claims_reconciliation', []]`
  and task 13's dependency; the end-to-end close executes it; the API test reads accruals at index 6), `tests/Feature/Insurance/SubledgerReconciliationTest.php`
  (registered reconcilers include `claims`; September runs destructure four subledgers and assert claims 0; nightly job 16 runs = 4 periods × 4).
- Tests `tests/Feature/Insurance/ClaimsReconciliationTest.php`: clean as of September and October with open, approved-unpaid, paid-after-month-end
  and closed claims (6,000,000 then 5,000,000); INVARIANT over 12 seeded random claim histories (reserve, adjustments, 1–3 partial payments,
  close): Σ claims_outstanding per closed claim = 0 and the claims subledger reconciles clean at 0; a manual adjustment on claims_payable
  blocks task 5 (order 5) with a `claim` exception (expected 1,000,000, actual 1,050,000) and the task completes once corrected.
- Result: 882 tests green, PHPStan 0 errors.

### 1B.3 — Claims reports — done
- Kernel: `FinancialStatementsQuery::roleMovementByDimension(entity, role, from, to, dimension)` (normal-side movement of the accounts mapped to a
  role, split by a column dimension; `''` = lines without it; unsupported dimension → `InvalidArgumentException`); `accountActivity` gains an
  optional `dimension` + `value` filter (API `…/activity?dimension=agent&value=<id>`; empty value = lines without the dimension).
- Insurance (`app/Modules/Insurance/Reports/Application`):
  - `OutstandingClaimsQuery::outstanding(entity, asOf)`: per claim reported by the date, open reserve (reserve history to the date − approved
    by then) and approved-unpaid (approved − paid by then); claims with neither omitted; totals; journals of the claim's reserve and payment events.
  - `LossRatioQuery::lossRatio(entity, from, to, by product|branch|agent)`: from the GL — incurred = claims_expense movement − claims_recovery_income,
    earned = premium_income; `loss_ratio_bp` half-even (null when nothing earned); totals; per-row drill URLs to each account's activity filtered
    to the row's dimension value. Direct business appears as `dimension_value: null` when grouped by agent.
  - `ClaimsPaidRegisterQuery::register(entity, from, to)`: payments paid in the range with claim, policy, product, branch, payee, amount and
    their CLAIM_APPROVED/CLAIM_PAID journals.
  - `InsuranceReportController`: `GET /api/reports/outstanding-claims?entity_id&as_of`, `loss-ratio?entity_id&from&to&by`, `claims-paid?entity_id&from&to` (`reports.financial`).
- Interpretation: incurred claims are net of recoveries (spec says "loss ratio by any dimension" without a formula); earned premium is the GL
  premium income of the range.
- Tests `tests/Feature/Reports/ClaimsReportsTest.php`: outstanding as of three dates (reserve only; reserve + approved-unpaid; closed claim gone,
  new one listed) with totals and journal drill; loss ratio by agent (agent and direct rows, incurred net of release and recovery, ratio), by
  product and branch, invalid dimension refused, filtered account activity equals the claims expense movement; paid register rows, journals,
  empty range; API 403/200 and 422 for an unsupported dimension.
- Result: 886 tests green, PHPStan 0 errors.

### Review pass (after 1B.3) — tenancy, write paths, floats, rules/fixtures, close variance
Scope: review only; only the critical finding was fixed.
1. Tenant tables: all 53 tables with `tenant_id` have forced RLS and the `tenant_isolation` policy (USING + WITH CHECK). Only 24 are in the
   0003 migration's `TENANT_TABLES` constant; the rest are enabled through `RowLevelSecurity::enable` (D-08 / slice migrations) and asserted
   structurally by `SchemaInvariantsTest`. `TenantIsolationTest` exercises 15 tables behaviourally (medium: extend it to every tenant table).
2. Accounting-table writes: no business module (Insurance, Finance) writes accounting tables; they submit events through
   `SubmitAccountingEvent` only. Journals are written only by `JournalWriter`, used by `PostingEngine`, `ReversalService` and — the documented
   exception (design §5.2, D-11, arch test) — `ManualJournalService`.
3. Floats: none in `app/` or `resources/js` (one `round(` is in a comment in `EarningSchedule`). All money division is `intdiv` half-even.
4. Rules/fixtures: every emitted event type (16) has exactly one rule. `POLICY_ENDORSED` has two fixtures (increase 01b, decrease 01c);
   `PAYROLL_POSTED` has a rule and fixture but is not emitted yet (Phase 2).
5. **Critical, fixed:** the §5.7 close with a variance injected *after* the reconciliation tasks passed (a control-account adjustment posted
   under `accounting.post_in_soft_locked` between tasks 13 and 16) locked the period: `FiscalPeriodService::lock` trusted recorded runs only.
   Fix: `ReconciliationService::currentVariances(period)` recomputes every subledger under the period row lock without recording, and the
   lock refuses `RECONCILIATION_VARIANCE` listing them; the close's task 16 first records a fresh `runAll` so a refusal leaves variance
   runs and exceptions. Tests: `tests/Feature/Close/LockRefusesUnreconciledPeriodTest.php` (close task 16 and a direct lock both refused, then
   lock after correcting). A variance injected *before* the reconciliation tasks was already refused (task blocked → `CLOSE_TASKS_OPEN`).
   Adjusted test (recorded, not weakened): `tests/Feature/Accounting/ReversalApprovalTest.php` "reopens a period through approval…" locked
   September, whose fixture receipt has no subledger counterpart — a real variance the fixed guard now refuses. It now locks and reopens August;
   its assertions are unchanged.
   Residual (medium): a posting committed concurrently with the lock transaction can still slip in (postings do not take the period row lock).
- Result: 888 tests green, PHPStan 0 errors.

### 1C.1 — Commission payouts (approve → pay, SoD) — done
- Why: CONTEXT.md non-negotiable #9 (maker ≠ checker on commission payouts) and design §5.6 `accrued ─▶ approved ─▶ paid` were not built in 1A.7.
- Migration `2026_09_16_000001_create_commission_statements` (tenant + RLS): `commission_statements` (number `CST-<FY>-nnnnnn` per entity, up_to,
  gross/withholding/net, status approved|paid, net > 0) and `commission_entries.paid_on`.
- New rule `COMMISSION_PAID.default` (DR commission_payable / CR bank_main `payload.amount`, dims branch + agent, key
  `COMMISSION_PAID:{commission_statement_id}`) and golden fixture `05c_commission_paid.json`.
- `Insurance\Commission\Application\CommissionPayoutService`: `approve(agent, upTo, actor, on)` (`commission.approve`; gathers accrued entries
  earned ≤ upTo, clawbacks netted; `NOTHING_TO_PAY` when net ≤ 0; entries → approved + statement_id); `pay(statement, ?bankAccount, actor,
  paidOn)` (`commission.pay`; SodGuard commission.approve ✕ commission.pay on the statement; `STATEMENT_NOT_APPROVED`; entries → paid with
  paid_on; posts COMMISSION_PAID for the net, bank override when a bank account is named). Withholding stays in commission_withholding_payable
  (remittance is not part of the payout).
- Commission subledger and agent statement now dated by `paid_on` (A-8 updated): an entry is payable from earned_on until paid_on.
- API: `POST /api/insurance/agents/{agent}/commission-statements {up_to, on}`, `POST /api/insurance/commission-statements/{id}/pay {paid_on, bank_account_id?}`.
- Interpretations: no amount-threshold approval policy on payouts (§5.6 names only approve/pay); a statement covers one entity (the agent's branch's).
- Tests `tests/Feature/Insurance/CommissionPayoutTest.php`: approve/pay amounts, statuses, event key/date, journal lines, remaining GL payable;
  clawback netting and NOTHING_TO_PAY; permission, SoD, pay once; bank override and clean dated reconciliation before/after payment; API.
- Result: 910 tests green, PHPStan 0 errors.

### 1C.2 — Cheque register and bounce handling — done
- Why: spec §4 "cheque (with cheque register and bounce handling) [ADDED]" was outside the 1A slice list.
- Migration `2026_09_16_000002_cheque_register_and_bounce`: `receipts.cheque_no/cheque_bank/cheque_date/bounced_on/bounce_reason` (CHECK: cheque receipts carry
  the details; partial unique index — a cheque is presented once per bank unless it bounced), `receipt_allocations.reversed_on`,
  `suspense_items.bounced_on` + status `bounced`, one bounce clawback per allocation.
- New rules + golden fixtures: `PREMIUM_RECEIPT_REVERSED` (DR premium_receivable / CR bank_main, key {receipt_allocation_id}, `02b`),
  `RECEIPT_ALLOCATION_REVERSED` (DR premium_receivable / CR suspense_receipts, `09b`), `RECEIPT_BOUNCED` (DR suspense_receipts / CR bank_main,
  key {receipt_id}, `09c`).
- `ReceiptService::record` takes optional `ChequeDetails` (`CHEQUE_DETAILS_REQUIRED`, `DUPLICATE_CHEQUE`).
- `ChequeBounceService::bounce(receipt, reason, actor, bouncedOn)` (`receipt.allocate`, interpretation): in one transaction each live allocation is
  reversed (installment unpaid, `reversed_on`, event per origin, domain event `ReceiptAllocationReversed`), the suspense item bounces for its full
  amount (`RECEIPT_BOUNCED`), receipt `bounced`. Refusals: `REASON_REQUIRED`, `NOT_A_CHEQUE`, `ALREADY_BOUNCED`, `BOUNCE_BEFORE_RECEIPT`,
  `BOUNCE_AFTER_CANCELLATION` (conservative: a cancelled policy's refund/credit assumed the money arrived — handle manually).
- Commission: `ClawBackCommissionOnReversal` claws back the full commission earned on a reversed allocation (dated the bounce); the cancellation
  clawback now nets those so commission is never clawed back twice.
- Reconcilers: premium adds reversed allocations back from `reversed_on`; suspense adds reversed suspense allocations and removes a bounced item
  from `bounced_on` — both still reconcile as of any date.
- `ChequeRegisterQuery::register(entity, from, to)` (presented/bounced, totals). API: receipt `cheque_no/cheque_bank/cheque_date`,
  `POST /api/insurance/receipts/{id}/bounce {bounced_on, reason}`, `GET /api/insurance/cheques?entity_id&from&to` (`receipt.create`).
- Tests `tests/Feature/Insurance/ChequeBounceTest.php`: details and duplicate/re-present; full undo (statuses, installments, three journals, bank and
  suspense GL 0, clean reconciliation before and after the bounce date); commission clawback without double count at cancellation; refusals; register + API.
- Result: 918 tests green, PHPStan 0 errors.
