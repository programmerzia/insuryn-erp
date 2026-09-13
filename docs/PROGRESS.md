# Progress log

Written for a reader with no memory of earlier sessions. Read `CONTEXT.md`, `docs/design-package-v1.md`,
`docs/spec-v2.md`, `docs/DECISIONS.md`, then this file.

## How to resume

```bash
# Postgres 17 + Redis from the repo's docker-compose; host ports from ERP_DB_PORT / ERP_REDIS_PORT in .env (this machine: 5441 / 6383,
# DB_PORT and REDIS_PORT equal to them). database/init creates roles erp_owner / erp_app (password erp) and databases erp and erp_test.
composer install && cp .env.example .env && php artisan key:generate
docker compose up -d
composer db:fresh                    # migrate:fresh as erp_owner + seed + DemoBusinessSeeder (local demo users <role>@demo.local)
composer test                        # Pest, serially (erp_owner cannot create databases, so no --parallel); also: php8.4 vendor/bin/pest
./vendor/bin/phpstan analyse         # level 8, 0 errors expected
git log --oneline | head             # one commit per green slice: feat(<area>): slice N – <name>
```

Next slice to pick up: the first Distribution row (D1–D9, docs/distribution-module-design.md) whose status is not `done`; the pending
2.0c, 2.0d and 2.1 rows follow once D9 is done. Otherwise the first row below whose status is not `done`, in table order. All Phase 1 rows are done;
Phase 2 starts from `docs/phase-2/kickoff.md` (slice 2.0 carry-over, then 2.1 design addendum).
UX rebuild U1–U10 (docs/ux-design-brief.md is the authority for everything visual and interactive): frontend only, backend added thinly
where a screen needs it; existing tests unchanged. Per slice: screenshots with `node scripts/ux-shots.mjs <slice> name=/path …` at 1366×768
and 1920×1080, light and dark, into `storage/ux-screenshots/<slice>/` (gitignored), against a local database seeded with
`php artisan migrate:fresh --database=pgsql_migrations --seed && php artisan db:seed --class=DemoBusinessSeeder`. Frontend tests: `npm test` (Vitest).
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
| 1C.3 | Agent cash collection and deposit reconciliation | done | see git log |
| 1C.4 | Dunning, grace and auto-lapse | done | see git log |
| 1C.5 | Multi-payer policies | done | see git log |
| 1C.6 | Hardening: posting/lock race, isolation on every tenant table | done | see git log |
| 1C.7 | Account security page (2FA, password) | done | see git log |
| 1C.8 | Operations UI: parties, products, policies | done | see git log |
| 1C.9 | Operations UI: receipts, suspense, refunds, bank | done | see git log |
| 1C.10 | Operations UI: claims and commission | done | see git log |
| 1C.11 | Operations UI: month-end close and reports | done | see git log |
| 1C.12 | Phase 1 exit pack (customer questions, exit checklist, Phase 2 kickoff) | done | see git log |
| U1 | UX: theme tokens and design system | done | see git log |
| U2 | UX: application shell | done | see git log |
| U3 | UX: command palette and shortcuts registry | done | see git log |
| U4 | UX: data table | done | see git log |
| U5 | UX: form system | done | see git log |
| U6 | UX: role home queues and badges | done | see git log |
| U7 | UX: rebuild existing screens | done | see git log |
| U8 | UX: object pages with timeline | done | see git log |
| U9 | UX: feedback, states, accessibility | done | see git log |
| U10 | UX: performance | done | see git log |
| 2.0a | Phase 1 carry-over: user and role administration screens | done | see git log |
| 2.0b | Phase 1 carry-over: CI pipeline | done | see git log |
| 2.0c | Phase 1 carry-over: Playwright E2E happy path | todo (pending, after Distribution D1–D9) | |
| 2.0d | Phase 1 carry-over: claim reserve property test | todo (pending, after Distribution D1–D9) | |
| 2.1 | Design addendum v2 and Phase 2 customer questions | todo (pending, after Distribution D1–D9) | |
| D1 | Distribution: agents → producers with channels | done | see git log |
| D2 | Distribution: licences with blocking rules, expiry alerts, IDRA register export | done | see git log |
| D3 | Distribution: effective-dated hierarchy, levels per scheme, `hierarchyAt` | done | see git log |
| D4 | Distribution: compensation schemes, rules, compliance profile | done | see git log |
| D5 | Distribution: calculation engine replacing the Phase 1A calculator, golden fixtures | done | see git log |
| D6 | Distribution: advances and monthly statement run, SoD, payout to payroll or AP | done | see git log |
| D7 | Distribution: targets, incentive plans, bonus, persistency and leaderboard | done | see git log |
| D8 | Distribution: screens (producers queue, producer page, hierarchy tree, scheme editor, statement workbench, targets grid) | done | see git log |
| D9 | Distribution: producer portal REST with Sanctum and OpenAPI | done | see git log |

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
| A-11 | 2.0a | Who may lock administration out is not specified: the tenant always keeps an active user holding `platform.manage_users` and one holding `platform.manage_roles` (removing a role, deactivating a user or editing a role's permissions that would leave none is refused), and nobody deactivates their own account. | `Platform\Authorization\AdministratorsRemain`, `UserAdministration::deactivate` (`ASSUMPTION:`). |
| A-12 | 2.0a | Invitation flow is not specified: an invited user is active with an unknown random password and receives a password-set link (Fortify reset token, tenant-keyed, standard 60-minute expiry; the admin can resend). Roles are given after inviting. | `Platform\Administration\UserAdministration::invite`, `config/auth.php` `passwords.users.expire`. |
| A-13 | 2.0b | The CI host is not named (the repo has no remote yet): GitHub Actions. Every step lives in `scripts/ci/*.sh`, so another CI runs the same gate by calling the scripts. "Blocks merge" needs the `backend` and `frontend` checks made required in the branch protection of `main` once the repo is hosted. | `.github/workflows/ci.yml`, `scripts/ci/`. |
| A-14 | D2 | Which producer types need a licence to write new business is not specified: every type (agent, agency_org, bdo, broker, partner); a producer that is not active writes no new business. Checked when a quote is issued, on the issue date; renewals are not checked (not new business). | `config/erp.php` `distribution.licence_required_types` (`ASSUMPTION:`), `LicenceRegistry`. |
| A-16 | D2 | IDRA's register file format is not specified: CSV with a header row, one row per licence, status as of the chosen date (valid, expired, not_yet_valid, suspended, revoked). | `config/erp.php` `distribution.idra_register_columns` (`ASSUMPTION:`), `IdraRegisterExport`. |
| A-17 | D2 | Products had no life / non-life class: `products.insurance_class`; a product whose line of business is listed as life is life, everything else non-life, unless given when the product is created. Existing products backfilled the same way. | `config/erp.php` `products.life_lobs` (`ASSUMPTION:`), `ProductCatalogue::createProduct`, migration `2026_09_18_000002`. |
| A-18 | D4 | Design note OPEN 1 (IDRA caps and the non-life zero-commission circular) is unanswered: commission on non-life products is disabled until a scheme's compliance profile sets `non_life_commission_allowed`; caps are whatever the profile lists (none by default). | `ComplianceProfile` (`ASSUMPTION:`), `compensation_schemes.compliance_profile`. |
| A-19 | D4 | Design note OPEN 3 (renewal commission after termination) is unanswered: rule flag `pays_after_termination`, default false. Rule flag `renewal_requires_valid_licence` defaults to true (design note §3). Both applied in D5. | `compensation_rules` columns, `CompensationRuleRequest`. |
| A-20 | D5 | Phase 1 commission plans predate compensation schemes: a product version without a scheme falls back to its plan (A-7 precedence), treated as a flat scheme (one direct rate on premium received, every product, type and year, no overrides or caps); non-life commission is allowed for plan terms because attaching a plan was an explicit configuration. Producers still need to be active and licensed. | `FlatCommissionTerms`, `CommissionAccrual` (`ASSUMPTION:`). |
| A-21 | D5 | Policy year is not defined beyond "1..1 = first year, 2..99 = renewal": 1 + renewals in the policy's renewal chain + whole years from inception to the premium's installment due date (inception for written premium). | `Insurance\Policy\Application\PolicyYear`. |
| A-22 | D6 | Payout route is not specified beyond "payroll (BDO/agent on payroll) or AP (agencies, brokers)": a producer with an employee record is paid through payroll; otherwise by type, default accounts payable (BDOs payroll). Payroll and AP modules are Phase 2, so the payout moves the net to salary_payable or accounts_payable and queues `CommissionPayrollEarning` / `CommissionPayableToAp` outbox messages for them. | `config/erp.php` `distribution.payout_route_by_type` (`ASSUMPTION:`), `CommissionStatementRun::route`. |
| A-23 | D6 | Persistency is not defined: the 13th-month persistency on a date is the share of the producer's new policies with inception 25 to 13 months before that date that are not cancelled or lapsed; with no such policies it is not measurable, and a minimum-persistency condition stays unmet (commission stays conditional). | `Insurance\Policy\Application\PersistencyQuery` (`ASSUMPTION:`). |
| A-24 | D7 | Target and incentive periods are not specified: calendar months, quarters (from January, April, July, October) and years; fiscal periods are LATER. | `Distribution\Domain\Incentives\IncentivePeriod` (`ASSUMPTION:`). |
| A-25 | D7 | A producer without a target for the plan's period and metric earns no incentive; the highest tier reached pays; a bonus carries the plan's withholding tax, none when the plan names none (payroll handles tax for salaried producers). Production: gross written premium dated in the period (new, renewal, endorsement and cancellation transactions), new and renewal policies, collections by value date net of reversed allocations. | `IncentiveRun`, `ProductionQuery` (`ASSUMPTION:`). |
| A-26 | D9 | Producer portal access is not specified beyond "read-only in MVP except collection recording": one portal user per producer, of user kind `portal` (never signs in to the staff web app), whose `producer_portal` role holds receipt.create and receipt.allocate scoped to the producer's branch; every portal read and the collection endpoint are limited to the producer's own policies; only active producers use the portal. Tokens carry abilities `portal:read` and `portal:collect`. | `ProducerPortalAccess` (`ASSUMPTION:`), `EnsureProducerPortal`, Fortify `authenticateUsing`. |
| A-27 | S1 | Who does each setup step is not specified: the permission that owns the data decides — company and branches `platform.manage_roles` (Tenant Admin), fiscal year `periods.lock` and chart of accounts `accounting.manage_coa` (Finance Manager), first product `product.manage`, users `platform.manage_users`. A step the user cannot do says who can and may be skipped. The fiscal year is twelve monthly periods from the chosen month, opened once; the base currency changes only while nothing is posted. Chart-of-accounts template accounts that carry an account role cannot be removed. A first product earns monthly (Part A: 1/12 each month); its VAT rate is recorded under jurisdiction `erp.setup.tax_jurisdiction` (BD) unless one is already in force. | `SetupWizard`, `CompanySetup`, `FiscalYearSetup`, `ChartOfAccountsSetup`, `TaxRateSetup`, `config/erp.php` `setup.*`. |
| A-28 | S1 | No §7.2 role template held `accounting.manage_coa`, so nobody could import a chart of accounts: the Finance Manager (and CFO) now hold it. | `RoleTemplates` (interpretation comment). |
| A-10 | 1C.4 | Dunning schedule and grace period are not specified (spec §4 names dunning, grace and auto-lapse only): reminders at 7 and 21 days overdue, automatic lapse of an active policy after 30 days unpaid (counted from the later of due date and reinstatement), auto-lapse on. Notices are recorded and queued; delivery channels are LATER. | `config/erp.php` `collections.*` (`ASSUMPTION:`), `DunningRun`. |
| A-52 | F2 | Upload limit and accepted document types are not specified: 10 MB per file; PDF, JPG/JPEG, PNG, DOC/DOCX, XLS/XLSX. The file type is judged by its extension and served with that extension's content type, always as a download. PHP `upload_max_filesize` / `post_max_size` must be at least the limit. | `config/erp.php` `documents.max_upload_kb`, `documents.allowed_extensions` (`ASSUMPTION:`), `DocumentStore`; hint text in `DocumentList.vue`. |
| A-53 | F2 | Who may attach documents is not specified: claims `claim.register`, `claim.reserve` or `claim.approve`; receipts `receipt.create` or `receipt.allocate`; policies `policy.create`, `policy.issue` or `policy.endorse` — for the object's branch. Listing and downloading follow the page's own area permissions (whoever can open the object page). No one can remove or replace a document. | `ATTACH_DOCUMENTS` in `ClaimPageController`, `CollectionsPageController`, `PolicyPageController` (`ASSUMPTION:`). |

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

State after Phase 1C: all slices 0.0 → 1C.12 done; nothing partial or blocked; 956 tests green on PHP 8.5 and PHP 8.4, PHPStan level 8
clean, vue-tsc and vite build green. Go-live gaps and customer questions: `docs/phase-1/exit-checklist.md`, `docs/phase-1/customer-questions.md`.

State at end of the overnight run: all slices 0.0 → 1B.3 done; nothing partial or blocked; 886 tests green on PHP 8.5 and PHP 8.4 (888 after the review pass), PHPStan level 8 clean, vue-tsc and
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

### 1C.3 — Agent cash collection and deposit reconciliation — done
- Why: spec §4 "Agent cash collection with deposit reconciliation" was outside the 1A slice list.
- Migration `2026_09_16_000003_create_agent_cash_tables`: `receipts.collected_by_agent_id` (CHECK: cash only) and `agent_deposits` (tenant + RLS,
  number `ADP-<FY>-nnnnnn` per branch, amount > 0).
- New rules + golden fixtures: `AGENT_CASH_COLLECTED` (DR agent_receivable / CR premium_receivable, dims incl. agent = collecting agent, key
  {receipt_allocation_id}, `02c`), `AGENT_DEPOSIT_RECORDED` (DR bank_main / CR agent_receivable, dims branch + agent, key {agent_deposit_id}, `02d`).
- `ReceiptService::record` with `collectedByAgentId`: `AGENT_COLLECTION_CASH_ONLY`, `AGENT_COLLECTION_UNALLOCATED` (must be fully allocated — agents
  collect for known policies, so agent cash never sits in suspense), `UNKNOWN_AGENT`; allocations post AGENT_CASH_COLLECTED instead of PREMIUM_RECEIVED
  (commission still earned on the allocation; premium subledger unchanged).
- `AgentDepositService::record(agent, amount, ?bankAccount, ?reference, actor, depositedOn)` (`receipt.create`, interpretation): serialised per agent,
  `DEPOSIT_EXCEEDS_UNDEPOSITED_CASH`, bank override when named.
- `AgentCashPositionQuery::position(entity, asOf)`: per agent collected, deposited, undeposited, agent_receivable GL balance for the agent, difference,
  oldest undeposited collection (deposits settle oldest first) and days held; totals. No `agent` reconciler: design §6.1 classes agent as a
  subsidiary view — the position's `difference_minor` is the deposit reconciliation.
- API: receipts `collected_by_agent_id`, `POST /api/insurance/agents/{agent}/deposits`, `GET /api/reports/agent-cash?entity_id&as_of` (`reports.financial`).
- Tests `tests/Feature/Insurance/AgentCashTest.php`: collection journal (no bank line), refusals, deposit limit and journal with bank override, dated
  position and totals, clean reconciliations; API permissions.
- Result: 924 tests green, PHPStan 0 errors.

### 1C.4 — Dunning, grace and auto-lapse — done
- Why: spec §4 "Installments, dunning, grace, auto-lapse" was outside the 1A slice list.
- Migration `2026_09_16_000004_create_dunning_notices`: `dunning_notices` (tenant + RLS, unique installment + level) and `policies.reinstated_on`.
- `Insurance\Policy\Application\Dunning\DunningRun::run(entity, asOf)` (A-10, `config/erp.php` `collections.*`): for unpaid installments of issued/active
  policies past due, records each reminder level whose day threshold is reached (once; queued as outbox `DunningNoticeDue`), and lapses active
  policies with an installment unpaid beyond the grace period when auto-lapse is on. Returns notices issued and policies lapsed. Reruns are no-ops.
- `PolicyLifecycle::lapseForNonPayment(policy, reason)`: system lapse (no user permission; audited with `Actor::system()` and the reason);
  `reinstate` now stamps `reinstated_on`, which restarts the grace period.
- `DunningJob` (queue `batch`, daily 01:30 in `routes/console.php`, after the 01:00 earning job activates due policies): per tenant and entity.
- API: `GET /api/insurance/dunning-notices?entity_id&from&to` (`receipt.allocate`, interpretation).
- Tests `tests/Feature/Insurance/DunningTest.php`: levels and idempotency with outbox messages; paid installments; lapse only beyond grace, only with
  auto-lapse, as the system with reason; fresh grace after reinstatement; nightly job; API.
- Result: 930 tests green, PHPStan 0 errors.

### 1C.5 — Multi-payer policies — done
- Why: spec §4 "Multi-payer" was outside the 1A slice list.
- Migration `2026_09_16_000005_multi_payer_policies`: `policy_payers` (tenant + RLS, share 1..10000 bp, unique policy + party) and
  `installments.payer_party_id` (backfilled from the policyholder, NOT NULL; unique now policy + no + payer).
- `QuoteRequest::$payers` (list of `PayerShare`); `PolicyLifecycle::quote` validates (`PAYER_SHARES_INVALID`: distinct, positive, total 10000;
  `UNKNOWN_PAYER`) and stores them; `renew` carries them to the renewal. No payers = the policyholder pays 100% (unchanged behaviour).
- `InstallmentPlanner`: every installment (plan and endorsement increase) is split per payer by share, half-even, the last payer absorbing rounding;
  `credit` (decrease, cancellation) is shared by payer the same way — each payer's unpaid installments from the last backwards, any payer's shortfall
  passed to the others — so totals are unchanged and the premium subledger still reconciles.
- `PayerStatementQuery::forPolicy(policy)`: per payer share, billed, paid, credited, outstanding. API: quote `payers[]`, installments show
  `payer_party_id`, `GET /api/insurance/policies/{id}/payers`.
- Interpretations: the general ledger's customer dimension stays the policyholder (events unchanged; per-payer balances come from installments);
  refunds on cancellation still go to the policyholder (open question for the customer: refund split between payers).
- Tests `tests/Feature/Insurance/MultiPayerTest.php`: share validation; split with rounding and single-payer default; increase split, shared credit on
  cancellation, payer statement totals and clean reconciliation over three month ends; API.
- Result: 934 tests green, PHPStan 0 errors.

### 1C.6 — Hardening: posting/lock race, isolation on every tenant table — done
- Why: the two medium findings of the review pass.
- Posting/lock race: `PostingContextLoader::period` now reads the fiscal period `FOR SHARE` (`sharedLock()`), held for the posting transaction
  (engine, reversal, manual journal posting). A period lock holds the row `FOR UPDATE`, so a lock waits for postings in flight and postings wait for a
  lock in progress; no posting can commit between the lock's reconciliation recompute and its commit. A posting that waits past `lock_timeout`
  fails with SQLSTATE 55P03, which `TransientFailureDetector` already treats as retryable (the event stays queued).
  Test `tests/Feature/Accounting/PostingLockRaceTest.php` holds the period row from a second connection: posting times out (55P03, event queued,
  no journal), then posts once the row is released — it failed before the fix.
- Isolation: `tests/Feature/Platform/TenantIsolationEveryTableTest.php` runs Phase 1 business through the application in two tenants (policy with
  payers, earning, dunning, cheque and agent receipts, deposit, bank import and match, commission payout, claim with approval policy, payment and
  recovery, manual journal, reversal request, reconciliation, close start, cancellation and refund) so every one of the 57 tenant tables holds rows;
  as `erp_app`, tenant A sees no row of tenant B (and B none of A) in any of them and nothing without a tenant. A new tenant table fails the test
  until it is populated there or listed in `TABLES_WITHOUT_SCENARIO_ROWS` with a reason (currently empty).
- Result: 936 tests green, PHPStan 0 errors.

### 1C.7 — Account security page (2FA, password) — done
- Why: Fortify's two-factor endpoints were enabled but there was no screen to turn two-factor on, and no password change.
- `config/fortify.php` adds `Features::updatePasswords()`; `Platform\Authentication\Actions\UpdateUserPassword` (current password required, error bag
  `updatePassword`), registered in `AuthenticationServiceProvider`.
- `GET /account/security` (`auth`) → `Platform\Authentication\Http\SecurityPageController` → Inertia `account/Security`: change password; two-factor
  turn on (Fortify asks for password confirmation first), QR code, confirm with a 6-digit code, recovery codes, turn off. The user name in the top bar
  links to it.
- Tests `tests/Feature/Platform/AccountSecurityTest.php`: page for signed-in users only with 2FA state; password change refused with a wrong current
  password and applied with the right one; 2FA requires password confirmation, a wrong code is refused, a valid TOTP confirms, 8 recovery codes, turn off.
- Result: 939 tests green, PHPStan 0 errors, vue-tsc and build green.

### 1C.8 — Operations UI: parties, products, policies — done
- Why: daily operations were API-only; spec §11 Phase 1 outcome is "customer can run daily operations".
- Shared screen foundation:
  - `PermissionChecker::authorizeAny` — a page is readable by users holding any permission of its area (interpretation: §7.1 has no read
    permissions for insurance areas; `reports.financial` lets auditors read). Each controller names its `AREA`; `resources/js/lib/navigation.ts`
    mirrors them so the top bar only shows reachable pages (`auth.permissions` is shared with every page).
  - `bootstrap/app.php`: for browser (Inertia) requests, business-rule and SoD refusals return to the form with `errors.form` (+ `errors.reason`)
    and the input; permission refusals are a plain 403. API/JSON responses are unchanged (422/403 JSON with reason codes).
  - `App\Http\Pages\PageSupport`: actor, single entity (design §9.2 MVP single-entity UI), money typed in major units parsed with string
    arithmetic (`Platform\Money\MinorUnits`, never a float), formatted money, pagination props.
  - Vue: `PageHeader`, `Pagination`, `forms/Field`, `forms/SelectInput`, `forms/FormBanner`; `AppLayout` groups navigation (Operations,
    Accounting) and shows the flash status.
- Screens (each action calls the same application service as the API):
  - `/parties` (search, create with roles), `/parties/{id}` (bank accounts, policies held), `/agents` (list, create with branch, plan, parent).
  - `/products` (products with versions; create product; add version with term, earning method, tax profile, commission plan).
  - `/policies` (filter by status, search number/policyholder), `/policies/create` (quote with installments and payers in percent),
    `/policies/{id}` (premium, installments per payer, payers, transactions; issue, endorse ±, cancel, lapse, reinstate, renew — buttons only
    for allowed transitions the user may perform).
- Tests `tests/Feature/Pages/PartiesProductsPoliciesPagesTest.php`: area access (guest, wrong area, right area); parties search/create/bank
  account/agents; products and versions; quote with major-unit premium → issue → endorsement refused back to the form, then accepted →
  detail props and allowed actions → cancel → status filter; the JSON API still answers 422 with the reason.
- Result: 944 tests green, PHPStan 0 errors, vue-tsc and build green.

### 1C.9 — Operations UI: receipts, suspense, refunds, bank — done
- `Insurance\Collections\Http\Controllers\CollectionsPageController` (area: receipt.create, receipt.allocate, receipt.refund_request,
  receipt.refund_release, reports.financial):
  - `/receipts` (list), `/receipts/create` (branch, channel, amount, value date, reference, bank account; cheque details for cheques; collecting
    agent for cash; allocations against outstanding installments), `/receipts/{id}` (allocations with reversal dates, suspense, "cheque bounced").
  - `/suspense` (ageing buckets and items as of a date; allocate an item to an installment), `/refunds` (refundable cancelled policies from the new
    `RefundableQuery`, request, release with paid date or reject — the SoD refusal returns to the form), `/agent-cash` (position as of a date,
    record a deposit), `/cheques` (register for a range), `/dunning` (reminders issued in a range).
- `Finance\Bank\Http\Controllers\BankPageController` (area: bank.import, bank.match, bank.manage_accounts, reports.financial): `/bank` (accounts
  with their ledger account and unmatched line count; add account), `/bank/{id}` (unmatched statement and ledger lines as of a date; CSV import
  with row errors shown; automatic match; manual match of one statement line to selected ledger lines; explain).
- Navigation gains the Collections group (Receipts, Suspense, Refunds, Agent cash, Dunning, Bank).
- Tests `tests/Feature/Pages/CollectionsBankPagesTest.php`: area access; receipt with cheque and allocation → suspense → allocate (and refused
  over-allocation) → bounce → register and list; refund request, SoD refusal, release; agent collection, deposit and position; dunning list;
  bank account, import, auto-match message, manual match and explanation empty the queue.
- Result: 949 tests green, PHPStan 0 errors, vue-tsc and build green.

### 1C.10 — Operations UI: claims and commission — done
- `Insurance\Claims\Http\Controllers\ClaimPageController` (area: claim.* and reports.financial): `/claims` (status filter), `/claims/create` (policies
  that were issued), `/claims/{id}` (case reserve and history, payments with request-release / release, recoveries; set reserve, approve payment,
  record recovery, close, reject, reopen — shown only when allowed). Messages say when a payment or release went for approval (above a limit).
- `Insurance\Commission\Http\Controllers\CommissionPageController` (area: commission.manage_plans, commission.approve, commission.pay,
  reports.financial): `/commission` (payout statements with pay, plans, approve a payout, new plan with the rate in percent → basis points
  with string arithmetic), `/commission/agents/{agent}` (agent statement for a range with totals and opening/closing payable).
- Approvals inbox: `Platform\Approvals\ApprovalInboxQuery::decidableBy(user)` (pending approvals whose current step the user may decide — holds
  the step permission, is not the requester, has not decided a step), `Platform\Approvals\DescribesApprovalSubject` (optional handler interface:
  title, amount, link — implemented by the claim payment, claim payment release, claim reopen, manual journal, reversal and period reopen
  handlers, so Platform never depends on modules), `Platform\Approvals\Http\ApprovalsPageController` (`/approvals`, decide approve/reject).
  The top bar links to Approvals.
- `bootstrap/app.php`: a permission refusal on a form submit (non-GET browser request) now returns to the form with the reason; page visits
  still get 403.
- Tests `tests/Feature/Pages/ClaimsCommissionApprovalsPagesTest.php`: area access and the inbox for anyone; full claim flow through the screens
  (permission refusal back to the form, release by someone else, recovery, close, detail props, filter); approval above a limit decided from the
  inbox by the right person only (requester sees nothing); plan in percent, statement approve, SoD refusal on pay, pay by someone else, statement page.
- Result: 953 tests green, PHPStan 0 errors, vue-tsc and build green.

### 1C.11 — Operations UI: month-end close and reports — done
- `Accounting\Http\Controllers\ClosePageController` (area: periods.soft_lock, periods.lock, periods.reopen, reports.financial): `/close` (periods of the
  primary book with status and close run; start close; reopen with reason), `/close/runs/{run}` (tasks in order with owner, dependencies, status and
  result summary; run a task with a note, or skip with a reason). Task permissions and dependencies are enforced by `PeriodCloseService`.
- `Accounting\Http\Controllers\ManualJournalPageController` (inside the `accounting.view_journals` group): `/accounting/journals/create` (manual or
  adjustment journal, lines with account, side, amount in major units, branch, memo) → create and submit; approve and reject on the journal page
  (maker ≠ checker enforced by the service; journals under an approval policy go through the inbox); request a reversal and approve/reject it.
  `JournalController::show` now also shares `actions` and the latest `reversalRequest`.
- `Insurance\Reports\Http\Controllers\ReportsPageController` (`reports.financial`): `/reports` catalogue and `/reports/{report}` for premium register,
  receivable ageing, outstanding claims, claims paid, loss ratio (by product/branch/agent), profit and loss, balance sheet and account activity (with
  dimension filter) — one generic `reports/Show` table page (columns, rows with a drill link, totals). Account rows drill to account activity, which
  drills to journals; policy and claim rows open their pages.
- Navigation: Accounting group gains Close and Reports; the journal list links to "New manual journal".
- Tests `tests/Feature/Pages/CloseReportsJournalsPagesTest.php`: full close through the screens (dependency refusal back to the form, skip, lock,
  run detail, reopen); every report 403/200 with drill links from balance sheet to account activity to journals and register totals; manual journal
  created from the form, maker cannot approve, checker approves, reversal requested and approved → journal reversed.
- Result: 956 tests green, PHPStan 0 errors, vue-tsc and build green.

### 1C.12 — Phase 1 exit pack — done
- Visual check of the 1C screens in headless Chrome against the local database (login, policy quote, receipt, close, balance sheet, manual
  journal, claims, security): all rendered without console errors. Fix: date filters in page headers stacked the "Show" button under a
  full-width date field; they now have a fixed width (commit `fix(ui): keep report date filters on one line`).
- `docs/phase-1/customer-questions.md` (for the customer): 17 questions in business terms, each with what the system does today — design OPEN
  #1–#6, assumptions A-1..A-10, payer refund split, commission payout route, month lock approval, role mapping, cheque bounce after cancellation,
  claim reopen, facultative reinsurance. Notes that limits on refunds and commission payouts, and commission tiers/overrides, need new work.
- `docs/phase-1/exit-checklist.md`: spec §11/§4/§5 Phase 1 scope, design §9.1 test layers and the ten non-negotiables mapped to slices and tests,
  with status. Gaps found: no CI pipeline, no Playwright happy path (§9.1, CI-blocking), no generator-based reserve property test, no k6 smoke,
  **no user/role administration screen or API** (users only from seeders), claims deductibles/co-insurance/batch payments/SLA timers,
  development triangles; cross-cutting items outside §11 Phase 1 (attachments, notification delivery, Bangla, global search, API keys, flags).
- `docs/phase-2/kickoff.md`: entry conditions, what Phase 1 gives Phase 2, design addendum outline (spec §12 nine deliverables for Finance +
  People), Phase 2 open questions, draft slice list 2.0–2.17 (2.0 carries the Phase 1 engineering gaps).
- No code or test changes beyond the UI fix. Result: 956 tests green on PHP 8.5 and 8.4, PHPStan 0 errors, vue-tsc and build green.

### U1 — UX: theme tokens and design system — done
- Brief §2 tokens (`surface`, `surface-2`, `line`, `ink`, `ink-2`, `accent`, `accent-soft`, `ok`, `warn`, `danger`) plus six companions needed for AA
  (`accent-text`, `accent-hover`, `accent-ink`, `line-control`, `focus`, `shadow-float`/`scrim`) in `resources/css/theme/corebari.css`, light on
  `:root`, dark under both the system media query and `data-theme="dark"`. Tailwind utilities map to them in `resources/css/app.css`.
  Mapping, ratios and how to add a customer theme: `docs/theme.md`.
- Deviations from the brief, by user instruction or for AA (recorded in docs/theme.md): accent is CoreBari Brick, not `#1F5F8B` (user: keep
  the CoreBari accent); dark mode uses CoreBari navy; light `warn` is `#94600F` because the brief's `#B7791F` is 3.64:1 on white; IBM Plex
  Sans Condensed and Plex Mono dropped (brief: no second face, no monospace body).
- Fonts self-hosted and subset (`@fontsource` IBM Plex Sans Latin 400/500/600, Noto Sans Bengali Bengali 400/500/600); the Google Fonts link is
  gone. Plex Sans figures are tabular by default (measured in Chrome: "1111" and "0000" have equal width), so no separate numeric face.
- Type scale utilities `text-dense/ui/body/section/title` (12/13/14/16/20), weights 400/500/600, radius `rounded-control` 4px / `rounded-panel` 6px,
  0 on tables, row height `--row-h` 32/40 by `data-density`. Reduced motion respected globally.
- Components on tokens: Button (primary/secondary/ghost/danger, 32px), Input, Select, Label, Card (panel, no shadow), Table (square, sticky
  surface-2 header, row height), StatusBadge now a dot and a sentence-case word (`lib/status.ts`: danger only for failed/unbalanced/variance/
  bounced), PageHeader (20px title, eyebrow no longer shown), CoreBari mark (`components/Logo.vue`) in both layouts. Every existing class
  migrated from the retired palette (ivory, blueprint, brick, amber, green) and eyebrow/all-caps labels removed.
- Tests first (Vitest, new): `resources/js/tests/theme.test.ts` — all ten brief tokens in both modes; system and explicit dark blocks identical;
  21 text/control/focus pairings clear AA in light and dark; every `.vue/.ts/.css` file free of colour literals, retired palette names, all-caps,
  monospace, off-scale text sizes, off-rule radii and extra shadows. `resources/js/tests/status.test.ts`.
- Tooling: `database/seeders/DemoBusinessSeeder.php` (local only; users per §7.2 role `<role>@demo.local`, 3 products, 16 customers, 3 agents,
  36 policies, receipts by channel, suspense, a bank statement, 6 claims at different stages, July/August earning, a manual journal awaiting
  approval — all through the application services); `scripts/ux-shots.mjs`; devDependencies vitest, @vue/test-utils, happy-dom, playwright-core;
  dependencies @tanstack/vue-table, @tanstack/vue-virtual, lucide-vue-next, reka-ui, @fontsource fonts.
- Screenshots: `storage/ux-screenshots/U1/{policies,journals,receipt-create,tb}-{1366,1920}-{light,dark}.png`. Self-critique: tokens and contrast hold in
  both modes; the old top-bar shell, ISO dates, pill-less but unsorted tables and card-wrapped forms remain until U2, U4, U5 and U7.
- Result: 956 Pest tests, 121 Vitest tests green, PHPStan 0 errors, vue-tsc and build green (JS 96.9 KB gzip).

### U2 — UX: application shell — done
- Backend (thin): migration `2026_09_17_000001_create_user_preferences` (tenant + RLS, one JSON document per user);
  `Platform\Preferences\UserPreferences` (`of`, `set`): keys `theme` (system|light|dark), `density` (compact|comfortable), `sidebar_collapsed`,
  `branch_id`, `splits.<id>` (240–1400px), `tabs` (≤ 8, in-app paths), `tables.<id>`, `views.<id>`, `recents` (≤ 20), `drafts.<id>`; unknown keys
  and invalid values are refused with 422. `PUT /preferences/{key} {value}` (auth). Shared props `preferences` and `shell` (entity, active
  branches, approvals waiting for the user; `badges` filled in U6). `app.blade.php` stamps `data-theme` (explicit choice only) and `data-density`
  on `<html>` server-side, so there is no flash of the wrong theme.
- Shell (`layouts/AppLayout.vue`, `components/shell/*`): 44px top bar (sidebar toggle, CoreBari mark, entity/branch switcher, "Search or run
  a command Ctrl+K" field, approvals bell, display settings menu with theme and density, user menu); pinned tab strip (Ctrl+click through
  `PinLink`, max 8, persisted, closable); sidebar ordered by frequency with Lucide 16px/1.5 icons, badge dots with counts, collapsible to
  icons with tooltips (Ctrl+B, persisted); status bar (row count, selection count, Σ of selected amounts, pagination, entity · branch ·
  currency); `SplitPane` (draggable divider, arrow keys, width persisted per list); `Inspector` (Details · Accounting · History · Files tabs
  from slots, Esc closes, Ctrl+Enter primary action with its shortcut shown); toasts bottom-left, 4s, with optional undo (`lib/toasts.ts`,
  flash `status` now arrives as a toast).
- Libraries: `lib/preferences.ts` (hydrate once, optimistic, debounced save), `lib/shortcuts.ts` (registry + `useShortcut`; Ctrl also matches
  ⌘; single-key shortcuts ignored while typing), `lib/tabs.ts`, `lib/statusbar.ts`, `lib/http.ts` (JSON with XSRF), `lib/palette.ts` (open state
  for U3), reka-ui menus with shortcut hints (`components/ui/menu`), `Kbd`.
- Journals list uses the split pane, inspector, pinned-tab links and status bar as the first queue on the shell (full table rebuild in U4/U7).
- Tests first: `tests/Feature/Platform/UserPreferencesTest.php` (guests refused; per-user merge; shared prop; `data-theme`/`data-density` on
  `<html>`; eight invalid inputs refused with nothing stored; eight tabs kept); `TenantIsolationEveryTableTest` scenario now writes a preference
  so the new table is covered (extended, nothing relaxed); `resources/js/tests/shell.test.ts` (shortcut matching incl. ⌘, registry keys,
  grouped preference keys, tab pin/dedupe/refuse ninth/unpin); theme test now also refuses arbitrary pixel font sizes.
- Checked in the browser: collapse persists across reload (sidebar 48px after reload); two layout defects found in screenshots and fixed
  (status bar pushed off-screen when no tabs were pinned; sidebar placed right of the content when tabs were pinned, because `TooltipProvider`
  renders no element) — grid rows and columns now explicit.
- Screenshots: `storage/ux-screenshots/U2/{journals-inspector,tabs,policies,settings-menu,switcher,collapsed}-{1366,1920}-{light,dark}.png`,
  `sidebar-collapsed-1366-light.png`. `scripts/ux-shots.mjs` gained interaction steps and signs in once (Fortify's login limiter).
- Not yet: the branch choice is stored and shown but lists do not filter by it until their queries take a branch (U6/U7); sidebar badges are
  zero until U6; the command field opens nothing until U3.
- Result: 967 Pest tests, 146 Vitest tests green, PHPStan 0 errors, vue-tsc and build green (JS 147.9 KB gzip, before route splitting in U10).

### U3 — UX: command palette and shortcuts registry — done
- Backend (thin): `GET /search?q=` → `App\Http\Search\GlobalSearchQuery` (app-level composition, read-only): policies by number or policyholder,
  claims by number or description, receipts by number, reference or cheque number, customers by name or TIN, journals by number, each limited
  to five and only from areas the user may open (the page controllers' `AREA` permissions). Short document numbers work: "POL-1042" finds
  POL-2026-001042. Period actions: "lock period sep 2026", "close aug", "reopen jul 2026" return the period's next close action the user may
  take ("Lock period Sep 2026", with status and whether a close is running; locked periods offer reopen only to `periods.reopen`).
- Palette (`components/shell/CommandPalette.vue`, Ctrl+K anywhere, also the top bar field): groups Recent · Actions · Go to · Settings ·
  Records; fuzzy match (`lib/fuzzy.ts`: in-order characters, word starts and prefixes rank higher), recent-first (`lib/commands.ts`, recents
  saved per user, 20, newest first, once each); "cheque 88231", "policy …" prefixes search the reference itself; ↑↓ move, Enter runs, Esc
  closes; results fetched 150ms after typing stops with the previous request aborted; empty result says what can be searched. Commands:
  navigation from `lib/navigation.ts` (permission-filtered), actions (new quote, record a receipt, register a claim, new manual journal,
  import a bank statement, import chart of accounts, start month-end close, approve or pay commission), settings (theme, density, sidebar,
  keyboard shortcuts list).
- Shortcuts registry (`lib/shortcuts.ts`) is the single source for keys: menu items (`MenuItem shortcut=`), the palette's settings commands,
  the inspector's primary action, tooltips and the "Keyboard shortcuts" list all read from it. New handlers: Alt+T theme, Alt+D density.
- Tests first: `tests/Feature/Pages/GlobalSearchTest.php` (guests, minimum length; policy by number, short number, customer; claims hidden
  without claim permissions; cheque found by a cashier, not by a claims officer; period action only for `periods.lock`);
  `resources/js/tests/palette.test.ts` (fuzzy ranking; commands by permission with registry shortcuts; recent-first ordering; recents cap).
  An initial expectation that "POL-1042" should not fuzzy-match "POL-2026-001042" was my own mistake (the brief wants it to match); corrected
  and the server now supports the short form too.
- Screenshots: `storage/ux-screenshots/U3/{palette,find-policy,find-cheque,lock-period,go-claims,shortcuts}-{1366,1920}-{light,dark}.png`.
  Self-critique fixes: stray focus outline on the dialog container removed; empty palette lists actions before navigation.
- Not achievable now: customer search by phone (parties have no phone column); fuzzy matching of records is server-side substring/number
  matching, not fuzzy.
- Result: 970 Pest tests, 153 Vitest tests green, PHPStan 0 errors, vue-tsc and build green.

### U4 — UX: data table — done
- `components/table/DataTable.vue` (+ `useDataTable.ts`, `DataTableToolbar.vue`, `types.ts`), on TanStack Table v8 (`@tanstack/vue-table` 8.21, pinned:
  9.x changed the API) and `@tanstack/vue-virtual`:
  - sticky header, column resize (drag the header edge, double-click resets), reorder (drag a header onto another), hide (Columns menu, reset),
    multi-sort (click, Shift+click adds; sort order numbers shown), inline filter row (text contains; money `>1000`, `<=500`, `1000..5000`,
    exact; dates by their shown form; statuses from a list), footer totals for money columns over the filtered rows;
  - virtual scrolling above 200 rows (spacer rows keep native table layout); columns have fixed widths and the table does not stretch
    numbers (`table-layout: fixed`, a filler column takes spare width);
  - saved views per user (Views menu: save the current filters, sort and columns under a name, apply, delete), layout (order, hidden,
    widths, sort) persisted per user in `tables.<id>`; filters and sort in the URL (`f.<column>=…`, `sort=-date,amount`, replace-state so Back
    goes to the previous page and a reload restores them);
  - selection with checkboxes, Space, Shift+↑↓ ranges; bulk-action toolbar replaces the view toolbar while rows are selected (count, Σ,
    page-provided actions, clear); the status bar shows rows, selection count and the selection's Σ, and server pagination when present;
  - keyboard: ↑↓ move (active row outlined), Enter opens (inspector), Space selects, Shift+↑↓ range, Home/End, `/` opens and focuses the
    filter row, Esc closes the inspector then clears the selection, Ctrl+Enter left to the inspector; right-click menu (open, open as pinned
    tab, select, copy the number) with shortcuts shown; CSV export of the filtered rows and visible columns;
  - cells: money right-aligned tabular with negatives in parentheses and the currency once in the header ("Amount (BDT)"), dates `12 Sep 2026`,
    status dot + word, links pin as tabs on Ctrl+click; loading shows skeleton rows; empty state with one sentence (and "Clear filters" when
    filters hide everything).
- `lib/money.ts` (BigInt minor units: parse "1,234.56", "(1,234.56)", "-…"; format with parentheses; sum), `lib/format.ts` (dates from the string,
  no timezone drift), `lib/table-state.ts` (filter semantics, URL codec).
- Backend (thin): list page size is now 5,000 (`PageSupport::LIST_PAGE_SIZE`, `JournalQuery::PAGE_SIZE`) for receipts and journals, per brief §7
  (virtualise above 200, paginate on the server above 5,000); other lists follow in U7.
- Journals and receipts lists run on the DataTable with the inspector (receipts selectable).
- Tests first: `resources/js/tests/table-logic.test.ts` (money beyond Number precision, parentheses, dates, filter expressions, URL round trip);
  `resources/js/tests/data-table.test.ts` (currency once, date and negative formatting, footer total; sort + Shift multi-sort in the URL; money
  filter updates rows, totals and URL; ↓↓ Enter opens the right row, Space + Shift+↓ select a range with the Σ in the status bar and the bulk
  bar, Esc clears; 1,000 rows render fewer than 200 rows with correct totals). A first run failed because the test file shared the preferences
  store between tests (a saved sort leaked); the tests now reset it — the component was right.
- Screenshots: `storage/ux-screenshots/U4/{receipts,receipts-filtered,receipts-selected,receipts-sorted,journals-columns,journals-inspector,
  receipts-context}-{1366,1920}-{light,dark}.png`. Self-critique fixes: the whole-grid focus ring doubled the active-row outline (ring now only
  when no row is active); date filter placeholder read like a value; inspector default width 400px so 1366 screens keep the amount column.
- Result: 970 Pest tests, 172 Vitest tests green, PHPStan 0 errors, vue-tsc and build green (JS 183.7 KB gzip before route splitting).

### U5 — UX: form system — done
- Journal preview before money moves (brief §1.6, §4). Backend: `App\Http\Preview\PreviewJournal` (web middleware) and the `moves-money` route
  marker. A POST to a marked route with `X-Journal-Preview` runs the real controller inside a transaction with
  `RecordingPostingDispatcher` bound in place of the queue dispatcher, posts each submitted event through the real `PostingEngine`, reads the
  journal lines (account, name, debit, credit, totals), then rolls back and restores the session. Refusals come back as JSON (validation
  errors, `errors.form` for business rules and permissions). Unmarked routes answer 400, so a preview can never create data. Marked: policy
  issue/endorse/cancel, record receipt, bounce, suspense allocation, refund release, agent deposit, claim reserve/payment approval/recovery/
  close, claim payment release, commission pay, approval decisions, close tasks, manual journal approval, reversal decisions.
- Lookups (brief §4): `GET /lookup/{customer|agent|policy|installment}?q=` (`App\Http\Search\LookupController`; area permissions; dates as
  `12 Sep 2026`) and `POST /lookup/customer` for inline creation (PartyService, `party.manage`, customer + policyholder roles).
- Components (`components/forms`): `Field` (label above, helper below, specific inline error, accessible ids via `lib/field.ts`),
  `MoneyInput` (right-aligned tabular, formats on blur, ↑/↓ ±1,000 with BigInt), `DateInput` (`t`, `+3`, `-1`, "12 Sep 2026", "12 sep",
  "12/09/2026", "1.1.27", ISO; shows 12 Sep 2026; unreadable input explains the accepted forms), `LookupInput` (typeahead with ↑↓ Enter, recent
  picks per lookup, Ctrl+N inline customer in a `Drawer`), `FormLayout` (single column 560px, Ctrl+Enter submit, Ctrl+S save draft, Esc cancel
  through the unsaved-changes guard, server and client business errors above the fields), `Stepper` (numbered steps with a sticky summary
  rail), `JournalPreviewDialog` (DR/CR lines per journal with plain-language event names, totals, failures, confirm button naming the amount,
  Ctrl+Enter / Esc). `lib/unsaved.ts` guards Inertia GET visits and tab close (never the form's own submit); `lib/confirm.ts` + `ConfirmHost`.
- Screens on the form system: record a receipt (money, keyboard dates, installment lookups filling the outstanding amount, running allocated /
  held-in-suspense balance with a specific over-allocation message, review-and-post with the journal preview); register a claim (three-step
  stepper: policy lookup → the loss with cover-date checks → review, summary rail, drafts saved per user and restored with a toast).
- Tests first: `tests/Feature/Pages/JournalPreviewTest.php` (policy issue preview equals the golden lines and changes nothing — events,
  journals, receipts, number sequences, status; receipt split previews both events; business-rule and validation refusals as JSON with no
  flash left; unmarked routes 400; normal post still posts); `tests/Feature/Pages/LookupTest.php`; `resources/js/tests/forms.test.ts` (date
  entry forms, money stepping and blur formatting); `resources/js/tests/unsaved.test.ts` (dirty GET visit blocked until confirmed, POST never
  blocked, clean form free).
- Found while checking in the browser: (1) errors set on the client form did not show (FormLayout read only server errors) — fixed;
  (2) Fortify's home `/accounting/journals` is a 403 for roles without journal access, so a branch manager signing in lands nowhere — fixed in
  U6 with role home queues; the screenshot script now waits for the sign-in response instead.
- Screenshots: `storage/ux-screenshots/U5/{receipt-form,receipt-preview,receipt-refused,claim-step1,claim-step2,date-error}-{1366,1920}-{light,dark}.png`
  (`receipt-preview` as branch.manager@demo.local; the admin demo user holds no receipt permission, and the preview correctly refuses).
- Not done: inline create exists for customers only (agents need a party and branch, policies need the quote flow).
- Result: 977 Pest tests, 191 Vitest tests green, PHPStan 0 errors, vue-tsc and build green.

### U6 — UX: role home queues and badges — done
- `App\Http\Home\WorkQueues` (read-only, app-level) defines the brief §5 queues per seeded role template and serves both `GET /home`
  (`home/Index`: blocks top to bottom — title, count, top five rows with visible column headers, "Open queue", "Showing 5 of N") and the
  sidebar badges in the shared `shell.badges` (count-only queries for the same queues). A user with several roles sees each queue once; a role
  without queues (tenant admin) gets an empty home that points to Ctrl+K.
  - Branch officer / manager: installments due this week; lapsing policies (oldest unpaid due date within 14 days of the grace period); receipts
    to record (unmatched credit lines on bank statements); quotes to follow up.
  - Accountant: unallocated receipts (open suspense, aged); unmatched bank lines; journals awaiting my approval (pending, made by someone else,
    only if the user holds `accounting.approve_journal`); failed accounting events with the failure reason.
  - Claims officer / manager: claims awaiting reserve; awaiting my approval (claim approvals this user may decide; the approval step is shown as
    "Over limit" because approvals exist only above a policy's limit); payments to release (approved or release requested).
  - Finance manager / CFO: close progress (running close: open tasks with owners and status, "n of 11 tasks done"); reconciliation variances;
    approvals over threshold (all approvals this user may decide); cash position (balance of the bank ledger accounts and 30 daily net-movement
    bars on one scale computed from BigInt minor units).
  - Auditor: recent reversals and adjustments (30 days); period reopen events (audit trail); control-account manual postings.
- Badges: receipts ← installments due; policies ← lapsing; bank ← receipts to record / unmatched lines; suspense ← unallocated receipts;
  journals ← journals awaiting my approval; claims ← awaiting reserve + payments to release; close ← variances; approvals ← the inbox count (U2).
- Home: `/` redirects to `/home`; Fortify's home is `/home` (a branch manager used to land on a 403 journal page); Alt+H goes home; Home is first
  in the sidebar. `DemoBusinessSeeder` now starts the August close and runs its first task.
- Tests first: `tests/Feature/Pages/HomeQueuesTest.php` — queue titles for all nine seeded roles (tenant admin none); counts and top rows for
  branch officer, accountant, claims manager and finance manager against a built scenario; badges equal the queue counts; accountant cannot
  see journals to approve until combined with finance manager; multi-role dedupe; sign-in home and `/` redirect.
- Interpretations and gaps: "SLA breaches" (claims) is not shown — claim SLA timers do not exist (exit checklist gap); "Failed accounting events"
  has no queue page, so that block has no "Open queue" link; the accountant template lacks `accounting.approve_journal`, so its "Journals awaiting
  my approval" is always empty unless the customer maps approval rights to accountants (customer question Q7).
- Screenshots: `storage/ux-screenshots/U6/home-{branch.officer,accountant,claims.manager,finance.manager,auditor}-{1366,1920}-{light,dark}.png`.
  Self-critique fixes: centred column left a gap at 1920 (now left-aligned like every page); close progress listed all eleven tasks (now the
  first five open ones); greeting replaced by a plain "Home" title; column headers made visible so dates are not ambiguous.
- Result: 988 Pest tests, 192 Vitest tests green, PHPStan 0 errors, vue-tsc and build green.

### U7 — UX: rebuild existing screens — done
- Shared: `components/table/QueueView.vue` (brief §6.1: toolbar with title and primary action → DataTable → status bar, inspector on the right),
  `DetailList`, `DateRangeFilter` (keyboard dates in the toolbar, kept in the URL), `TextInput`, `lib/permissions.ts` (actions shown only to people who
  may take them), `lib/journalConfirm.ts` (money actions from lists and inspectors go through the journal preview), `lib/drill.ts` + `Breadcrumb`
  (drill path), shell toast for server business-rule refusals. DataTable gained `openOnClick` (click selects, Enter acts) and an icon-only toolbar
  for screens with two tables.
- Lists on the queue view with inspectors and drawers for create forms: policies, claims, parties, agents, products (versions in the inspector,
  new version in a drawer), approvals (approve with journal preview, reject with reason), refunds (request drawer; pay with preview; reject),
  suspense (ageing buckets in the toolbar, rows open the workbench), agent cash (deposit drawer with preview), cheque register, payment reminders,
  bank accounts, month-end close periods (start, open checklist, reopen with reason and a confirmation), commission (statements and plans tabs,
  approve and new-plan drawers, pay with preview), agent commission statement, journals, receipts. Server list page size 5,000 for policies,
  parties and claims (brief §7).
- Allocation workbench §6.3 (`/receipts/{receipt}/allocate`, `receipts/Allocate`): receipt facts and lines on the left with a running remaining
  balance and allocation date; candidate installments on the right (the receipt's payer first); ↑↓ + Enter adds the active installment for what it
  needs up to what is left; amounts editable; one commit `POST /suspense/{item}/allocations` (all lines in one transaction, first refusal rolls
  back all) after the journal preview.
- Bank matching §6.4 (`bank/Show`): statement lines beside ledger lines; each statement line shows its best suggestion as "Strong match" (amount,
  date window and reference, 100) or "Possible match" (amount and date window, 60) with the reason (`BankMatcher::suggestions`, recorded nowhere);
  Enter accepts; clicking only selects; ledger lines sort the chosen line's suggestions first; several ledger lines selected with Space and
  Ctrl+Enter match one statement line when they add up (merge); explain a line with no ledger entry; import statement and "Accept strong matches".
- Month-end close §6.5 (`close/Run`): checklist in order with owners, status, results and "Waits for …" from unfinished dependencies (`blocked_by`),
  progress bar, a link from each task to its exception queue (suspense, bank matching, ageing, outstanding claims, commission, manual journal,
  trial balance, balance sheet), "Work on it" to run or skip with a reason, and the lock disabled with the server's reason until the close is clean
  (`lock.ready/reason`) and confirmed before locking.
- Trial balance §6.6: collapsible tree by account type with group subtotals, balance at the date and at the previous month end with the change
  (`compare` prop), every figure drills to account activity; reports catalogue as a list (no card grid); reports on the DataTable with a breadcrumb,
  event codes shown as words, row links drilling to journals.
- Journal viewer §6.7 (`App\Http\Pages\JournalPageController` adds `dimensions` as labels — branch code, product, agent, policy and claim numbers,
  customer name — and `sourceLink` to the policy, receipt, claim, refund, deposit or commission page): header strip with status, total and actions;
  lines with account codes, dimension labels and memo; where it came from (event in words, posting rule, source document link, reason); reversal
  and correction chain; approve-and-post and reversal approval confirm with the journal's own lines (manual journals and reversals do not post
  through accounting events, so the server preview would show nothing); reversal requested in a drawer.
- Forms: new quote as a four-step stepper (customer lookup with inline create, product, agent lookup, branch → cover date, premium, installments →
  payers with a 100% check → review; summary rail; drafts); manual journal with lines, live debit/credit difference and a specific imbalance message;
  imports as a stepper (file → check → dry run → commit with a confirmation).
- Tests first: `tests/Feature/Pages/WorkbenchScreensTest.php` — workbench props (open amount, candidates, payer first); multi-line allocation commits
  all or nothing with a specific status message; bank suggestions with confidence and reasons; close run lock readiness and `blocked_by`; trial
  balance comparison with the previous month end; journal dimension labels and source link. Two of my assertions first cast Inertia's Collection
  with `(array)` (always false) — corrected to decode the values; the code was right.
- Found and fixed while reviewing screenshots: clicking a statement line accepted its suggested match (now click selects, Enter accepts); a
  successful match showed the server toast and a client toast (client one removed); "As of" labels wrapped; two tables' toolbars crowded at 1366
  (icon-only with tooltips); trial balance figures were all accent-coloured (now ink, accent on hover); raw event codes as descriptions; account
  activity showed a start date that the report had not applied; demo statement lines now sit near real receipts so suggestions appear.
- Not achieved: splitting one ledger line across several statement lines (BankMatcher matches one statement line to many ledger lines only);
  undo on a bank match (there is no unmatch operation); toasts are bottom-left per the brief and can briefly cover the workbench's commit button.
- Screenshots: `storage/ux-screenshots/U7/{allocate,bank-matching,close-run,close-periods,trial-balance,account-activity,journal,journals,commission,
  policies,claims,suspense,reports,imports,policy-create}-{1366,1920}-{light,dark}.png` (finance.manager@demo.local).
- Result: 994 Pest tests, 201 Vitest tests green, PHPStan 0 errors, vue-tsc and build green (JS 206.8 KB gzip before route splitting).

### U8 — UX: object pages with timeline — done
- `App\Http\Pages\ObjectHistory` (read-only): `timeline` turns audit events of the object and its children (claim payments; the receipt's suspense
  item) into plain sentences, newest first — "Quoted at 120,000.00 by …", "Endorsed: premium up by 1,000.00 (Extra driver) by …", "Reserve increased to
  350,000.00 (Surveyor report) by …", "Payment of 100,000.00 approved by …", "Recorded 130,000.00 by …: 120,000.00 allocated, 10,000.00 held in suspense",
  "Cheque bounced on …"; system actions say "by the system"; a payment below every approval limit reads as approved once (its internal "approval
  requested" step is not shown). `accounting` lists the journals touching the object (policy and claim by their dimension, a receipt by its own and
  its allocations' events) with lines; `audit` gives who/what/when/why and every field before and after.
- `App\Http\Pages\ObjectPageController` composes `policies/Show`, `claims/Show`, `receipts/Show` from the module pages plus `timeline` and, as
  Inertia deferred props (group `history`), `accounting` and `audit`, so the page paints first and those tabs fill in with skeleton rows.
- `components/object/ObjectPage.vue` (brief §6.2): header strip (breadcrumb, number, status dot + word, key amounts, "View accounting" and the actions
  the user may take), tabs Overview · Transactions (policy transactions; claim reserve history) · Timeline · Accounting · Documents · Audit, open tab
  in the URL; "View accounting" opens the journals in a side panel (brief §1.5); `Timeline`, `AccountingList` (journal links drill), `AuditList`,
  `SkeletonRows`.
- Actions moved into drawers on the form system; money actions use the journal preview (`lib/moneyForm.ts`): policy issue, endorse (negative
  allowed), cancel; claim reserve, payment approval, recovery, payment release, close; cheque bounce. Lapse, reinstate, reject and reopen take a reason;
  renew asks first. The receipt page links to the allocation workbench while money is in suspense.
- Tests first: `tests/Feature/Pages/ObjectPagesTest.php` — policy, claim and receipt timelines as exact sentences; accounting and audit absent on
  first load and present after loading the deferred group (issued journal lines, endorsement audit row with actor and reason). My first expectations
  were wrong twice (policy accounting also holds the receipt and claim journals on its dimension; the policy audit tab lists only policy events) —
  corrected; while running it I found two code bugs and fixed them: the quoted premium was read as an amount when the audit stores gross/net/tax,
  and every claim payment read as both "sent for approval" and "approved".
- Not achieved: the Documents tab has nothing to show — attachments are not built (exit checklist §4); it says so and what to do instead.
- Screenshots: `storage/ux-screenshots/U8/{claim,claim-timeline,claim-accounting-panel,claim-audit}-{1366,1920}-{light,dark}.png` (claims.manager),
  `{policy,policy-timeline,policy-accounting,receipt}-…png` (finance.manager). Self-critique fix: the accounting side panel was 440px and truncated account
  names (now 680px; the drawer takes a width).
- Result: 997 Pest tests, 208 Vitest tests green, PHPStan 0 errors, vue-tsc and build green.

### U9 — UX: feedback, states, accessibility — done
- Undo (brief §4 "undo where the action is reversible"): `BankMatcher::unmatch` (Finance module) undoes a match or an explanation, audited as
  `bank_line.unmatched`, refused with a plain reason once the statement line's month is locked; `POST /bank/lines/{line}/unmatch`. Match and explain
  flash an `undo` (label and URL) with their status; the shell shows it as a 6-second toast with an Undo button. Unallocating a receipt is not
  offered: an allocation posts journals, so undoing it needs a compensating event that does not exist yet.
- Specific errors (brief §4 "Errors say what happened and what to do"; "minor units never shown"): `App\Http\Feedback\ReasonMessages` rewrites
  business-rule refusals for browser forms and journal previews — amounts in major units computed from the domain message ("The amount exceeds the
  installment balance of 60,000.00 by 1,200.00.", "The amount exceeds what is left in suspense (20,000.00) by 5,000.00.", reserve, deposit, refund,
  match totals), fixed sentences for twenty reasons (SoD, maker-checker, control accounts, close readiness, stale records), and record ids stripped
  from anything else. The JSON API keeps the domain's message (contract unchanged, tested). Field validation says what to enter and names fields the
  way screens do (`lang/en/validation.php`: "Enter the value date.", "Enter the cheque date as a date, like 12 Sep 2026.", "Choose the policy from the list.").
- States: tables show skeleton rows in place during same-page reloads that take longer than 250ms (`lib/loading.ts`) and while deferred props
  load (U8); empty tables give one sentence and one primary action (receipts, suspense "Import a bank statement", policies, claims, journals), and
  "No rows match these filters · Clear filters" when filters hide everything. No full-screen spinner anywhere (the thin Inertia progress bar remains).
- Accessibility pass: automated WCAG 2.1 A/AA audit with axe-core (`scripts/ux-axe.mjs`, new devDependency) on 32 screens plus sign-in, light and
  dark. It found two critical issues, both fixed: the data table put `role="grid"` on its scroll wrapper instead of the table (grid without rows) — the
  table is now the focusable grid with `aria-activedescendant`, row ids and row indexes; selects did not receive their Field's id, so their labels did
  not name them. Final run: no violations on any audited screen in either theme. Also: "Skip to the main content" link, `main` focus target, visible
  `:focus-visible` outline from the theme token (3:1 checked in U1), contrast AA on every token pairing (U1 test), reduced motion honoured globally (U1).
- Tests first: `tests/Feature/Pages/FeedbackTest.php` (match flashes undo; unmatch restores unmatched with audit; locked month refuses with the sentence;
  over-allocation and over-suspense messages in major units; API message unchanged); `resources/js/tests/data-table.test.ts` gains empty action,
  skeleton rows and filtered-empty behaviour.
- Screenshots: `storage/ux-screenshots/U9/{undo-toast,specific-error,empty-filtered,skip-link}-{1366,1920}-{light,dark}.png`.
- Result: 999 Pest tests, 210 Vitest tests green, PHPStan 0 errors, vue-tsc and build green.

### U10 — UX: performance — done
- Code splitting: every page is its own chunk (`import.meta.glob` without `eager`); framework code in long-lived vendor chunks through Rolldown
  `advancedChunks` groups (`vendor-vue`: Vue + Inertia; `vendor-ui`: reka-ui, floating-ui, VueUse; `vendor-table`: TanStack); icons stay with the code
  that uses them (forcing them into one chunk defeated tree-shaking: 47 KB of icons); the command palette loads when first opened.
- Bundle (gzip, measured from `public/build`):
  - total JS gzip KB 256.3 | CSS gzip KB 11.0
  - home/Index first-load JS gzip KB 122.3
  - receipts/Index first-load JS gzip KB 152.2
  - policies/Show first-load JS gzip KB 134.6
  - accounting/TrialBalance first-load JS gzip KB 123.2
  - receipts/Create first-load JS gzip KB 140.8
  - bank/Show first-load JS gzip KB 154.1
  - close/Run first-load JS gzip KB 122.6
  - largest: [('vendor-vue-C7i0L2Ub.js', 73.9), ('vendor-ui-DVKHvQRl.js', 29.3), ('vendor-table-BglFa6Zq.js', 19.1), ('AppLayout-DPdgpRRb.js', 12.5), ('DataTable-B7jdwf2K.js', 9.7), ('utils-BuYa9y6l.js', 8.4)]
  Brief §7 budget "total JS < 350KB gzipped": met with every page chunk included.
- Fonts: self-hosted, subset (Latin IBM Plex Sans 400/500/600 at 22–24 KB each; Bengali Noto Sans only downloaded when Bengali text appears,
  through its `unicode-range`); U1.
- First paint: the HTML carries a skeleton of the shell (top bar, sidebar, content lines) drawn with the theme tokens from a 0.4 KB render-blocking
  stylesheet (`corebari.css` as its own Vite entry); the app stylesheet no longer blocks rendering (`Vite::useStyleTagAttributes`, media swap) and
  `app.ts` mounts once it has loaded, then removes the skeleton — no unstyled flash (checked in the browser under Fast 3G emulation).
- Navigation: sidebar links and object links prefetch on hover (Inertia prefetch, cached 30s fresh / 1m stale); page code for the sidebar's lists and the
  records they open, and the palette, are fetched when the browser is idle (`lib/warmup.ts`); the progress bar waits 250ms before showing.
- Measurement set-up (local, not production): `php artisan serve` with `APP_DEBUG=false`, OPcache, 4 workers, behind a gzip proxy standing in for the
  production web server (artisan serve does not compress); Chrome headless 1366×768 desktop, finance.manager@demo.local, demo data from
  DemoBusinessSeeder. "3G-fast" is Chrome DevTools' Fast 3G: 562.5 ms request latency, 1.44 Mbps down, 675 kbps up; Lighthouse's simulated
  equivalent is 150 ms RTT at 1,638 kbps. CPU slowed ×2.
- Lighthouse 12 (performance category, after sign-in; "applied" = DevTools throttling in the browser, "simulated" = Lighthouse's model):

| Profile | Page | Score | FCP ms | LCP ms | TBT ms | CLS | TTI ms | Transfer KB |
|---|---|---|---|---|---|---|---|---|
| simulated | /home | 84 | 1509 | 2119 | 20 | 0.001 | 2185 | 317 |
| simulated | /receipts | 77 | 1506 | 3020 | 44 | 0.001 | 3020 | 317 |
| simulated | /policies/{policy} | 77 | 1506 | 3022 | 40 | 0.001 | 3255 | 319 |
| simulated | /accounting/trial-balance | 76 | 1886 | 2565 | 9 | 0.006 | 2565 | 317 |
| applied | /home | 79 | 1165 | 2907 | 13 | 0.001 | 2876 | 317 |
| applied | /receipts | 75 | 1155 | 3250 | 62 | 0.001 | 3214 | 317 |
| applied | /policies/{policy} | 72 | 1168 | 4047 | 40 | 0.001 | 4021 | 319 |
| applied | /accounting/trial-balance | 78 | 1155 | 2919 | 14 | 0.004 | 2880 | 317 |

  First paint target (< 1.5 s on 3G-fast after sign-in): met with applied throttling (FCP 1.16 s, the skeleton frame); Lighthouse's simulation, which
  cannot credit a frame painted before the scripts, puts FCP at 1.5–1.9 s. The full working screen (LCP) arrives at 2.9–4.0 s on Fast 3G.
- Subsequent navigations (`node scripts/ux-perf.mjs`, click to Inertia `navigate`, same server set-up, CDP network emulation):

| Step | Fast 3G | 4G | No throttling |
|---|---|---|---|
| Home → Receipts (sidebar, hover prefetch) | 48 | 54 | 46 |
| Receipts → Policies (sidebar, no hover) | 608 | 175 | 73 |
| Policies → policy page (hover prefetch, then click) | 33 | 35 | 32 |
| Policy page → Accounting tab (deferred props already loaded) | 9 | 6 | 6 |
| Trial balance → account activity (drill) | 599 | 175 | 72 |

  Target (< 300 ms): met for every hover-prefetched navigation and in-page tab (6–54 ms on any network) and for all navigations on 4G and unthrottled;
  a navigation that was not prefetched costs one request, about 600 ms on Fast 3G's 562.5 ms latency, so it cannot meet 300 ms there.
- Tables virtualise above 200 rows and the server paginates above 5,000 (U4); offline reads and queued writes are LATER in the brief and not built.
- Production notes: serve `/build/assets` with gzip or brotli (`gzip_static`) and `Cache-Control: immutable` (hashed names), HTTP/2 so the parallel
  chunk requests share one connection.
- Screenshots: `storage/ux-screenshots/U10/{boot-skeleton,after-boot}-1366-light.png` (skeleton under Fast 3G, then the app), `{home,palette}-{1366,1920}-{light,dark}.png`
  (palette loaded lazily). Raw Lighthouse reports were kept in the session scratchpad only.
- Found while measuring: my first Lighthouse profile put 562.5 ms in as the simulated RTT (3.75× too slow) and my first navigation timer measured the
  hover prefetch instead of the click — both corrected before recording numbers; inlining the theme CSS into the HTML broke `UserPreferencesTest`
  (it rightly checks no `data-theme=` is present for a user on the system theme), so the tokens became a separate stylesheet instead.
- Result: 999 Pest tests, 211 Vitest tests green, PHPStan 0 errors, vue-tsc and build green.

### UX rebuild (U1–U10) — end state
- All ten slices done, one commit each; backend changes thin and tested (preferences, search, lookups, journal preview, work queues, batch
  allocation, bank suggestions and unmatch, close readiness, trial balance comparison, journal and object page composition, reason messages).
  Existing tests unchanged except extending `TenantIsolationEveryTableTest` to populate the new `user_preferences` table.
- Final counts: 999 Pest tests, 211 Vitest tests, PHPStan level 8 with 0 errors, vue-tsc and vite build green; axe-core WCAG 2.1 AA: no violations on
  32 audited screens plus sign-in in both themes.
- Screenshots per slice: `storage/ux-screenshots/U1` … `U10` (1366×768 and 1920×1080, light and dark), taken with `scripts/ux-shots.mjs`.
- Brief items not achieved, and why:
  - Accent `#1F5F8B` and neutral dark greys (§2): replaced by CoreBari Brick and Navy on the user's instruction; light `warn` darkened for AA (docs/theme.md).
  - Search by phone (§4 lookups): parties have no phone number.
  - Split one ledger line across several statement lines (§6.4): BankMatcher matches one statement line to many ledger lines only.
  - Undo for unallocate (§4): an allocation posts journals; undo needs a compensating event that does not exist. Bank match undo is built.
  - "SLA breaches" queue (§5) and claim SLA timers: not built in the domain; the claims queue omits the block.
  - "Failed accounting events" block has no queue page to open.
  - Documents tab (§6.2): attachments are not built; the tab says so.
  - English / Bangla switching and Bengali digits (§8): not built — English only; the Bengali font is in the stack for Bengali names.
  - Branch switcher (§3): chosen branch is stored and shown but lists do not filter by it yet.
  - Right-click menus exist on tables only; "keyboard-first" coverage is tables, palette, forms, inspector and shell shortcuts.
  - Navigations that were not prefetched take one round trip (~600 ms) on Fast 3G; the full screen (LCP) on Fast 3G is 2.9–4.0 s.
  - Offline-tolerant reads and queued writes (§7) are LATER in the brief.
  - Toasts sit bottom-left per the brief and can briefly cover the allocation workbench's commit button.

### 2.0a — User and role administration — done
- Closes the exit checklist's go-live gap "users come only from seeders". Screens under `/admin` for the Tenant Admin, sidebar items Users and Roles.
- Users (`platform.manage_users`):
  - list, and invite with an emailed password-set link (`UserInvitation`);
  - on the user page: roles by scope (whole organisation, one legal entity, one branch), remove a role, deactivate (signs the user out by deleting their
    sessions), reactivate, resend the invitation, and a plain-language timeline ("Given Branch Officer for branch HO by Nadia Admin").
- Roles (`platform.manage_roles`): list with permission and holder counts, create, choose permissions grouped by area, delete a role nobody holds.
- Design §7.3 at every change of what a user holds: `HeldPermissionsPolicy` (extracted unchanged from `RoleAssignmentService`) runs for assignment
  and, per holder, for the permissions a role edit adds; refusals name the person and both permissions. Warn-mode conflicts are shown with the success message.
- `RoleAssignmentService::revoke` added (audited `user_role.revoked`). New audit actions: `user.invited`, `user.invitation_sent`, `user.deactivated`,
  `user.reactivated`, `role.created`, `role.permissions_changed` (added/removed), `role.deleted`.
- Assignment-time conflicts use reason `ROLE_CONFLICT`, so browser forms do not show the action-time SoD sentence ("you took part in an earlier step").
- ASSUMPTIONS A-11 (administrators always remain, no self-deactivation) and A-12 (invitation flow).
- Also in this stretch: local-only demo accounts dialog on the sign-in page (`DemoAccounts`), project docker compose on its own ports, composer
  shortcuts `db:migrate`, `db:fresh`, `worker`, `scheduler`, and `composer test` fixed for Composer 2.2.
- Tests: `UserAdministrationTest` (7), `RoleAdministrationTest` (5), `DemoAccountsTest` (2), `demo-accounts.test.ts` (2); existing tests unchanged.
- Screenshots: `storage/ux-screenshots/admin/{users,user,roles,role}-{1366,1920}-{light,dark}.png`, `storage/ux-screenshots/demo-accounts/`.
- Result: 1,013 Pest tests, 218 Vitest tests green, PHPStan 0 errors, vue-tsc and build green.

### 2.0b — CI pipeline — done
- Design §9.1 DECISION "CI blocks merge on all but performance smoke". `.github/workflows/ci.yml` on push to `main` and every pull request:
  - `frontend`: `scripts/ci/frontend.sh`, which runs vue-tsc, Vitest and the production build;
  - `backend`: Postgres 17 service, PHP 8.4 (the design's version), `scripts/ci/prepare-database.sh` (runs `database/init/01-roles.sql`, the
    same roles and databases as docker compose), then `scripts/ci/backend.sh`, which runs Pest (all layers in the suite, including architecture tests)
    and PHPStan level 8.
- The backend job builds the frontend first because pages render through the Vite manifest.
- Verified by running the scripts in a clean clone with PHP 8.4.25 and a new Postgres 17 container: frontend green (218 Vitest tests, build), backend
  green (1,013 Pest tests, PHPStan 0 errors). A deliberately failing test made `backend.sh` exit 1 before PHPStan.
- Not in this slice: the Playwright E2E job (2.0c adds it to the same workflow); the k6 performance smoke is not CI-blocking by design.
- To do once the repo has a GitHub remote: branch protection on `main` requiring `backend` and `frontend` (A-13). The workflow has not run on GitHub yet.

### D1 — Distribution: agents → producers with channels — done
- New business context `App\Modules\Distribution` (D-12). Tables `channels` (agency, bdo, broker, bancassurance, partner, direct) and `producers`
  (agent, agency_org, bdo, broker, partner; applicant → active → suspended → terminated; employee_id, joined_on, terminated_on, termination_reason),
  both tenant tables with forced RLS and CHECK constraints on type and status.
- Migration `2026_09_18_000001_create_distribution_producers`: Phase 1 `agents` rows copied into `producers` per tenant with their ids
  (`LegacyAgentBackfill`), type agent, standard AGENCY channel, `inactive` → `suspended`, joined_on = creation date; then `agents` dropped. `down()` restores `agents`.
  Checked on the local demo database: 3 agents in, 3 producers out with an identical hash over id, code, party, parent and plan; no policy or journal line
  points at a missing producer; rollback and re-migrate both work.
- `agent_id` columns (policies, receipts `collected_by_agent_id`, agent deposits, commission entries and statements) and the `dim_agent` dimension keep their names
  and now hold producer ids (D-13). The journal page labels the dimension "Producer".
- Insurance no longer has an Agent model: it reads producers through `Distribution\Application\ProducerDirectory` (a read-only contract returning
  `ProducerSummary`). `Insurance\Party\Application\AgentService` stays as the agent-flavoured façade over `ProducerService` and still adds the party's
  agent role, so the agent API (`/api/insurance/agents`, Phase 1 `inactive` accepted as `suspended`), screens and seeders are unchanged.
- Each producer type joins its standard channel when none is chosen (`ChannelDirectory::standard`, created on first use): agent and agency_org → AGENCY, bdo → BDO, broker → BROKER, partner → PARTNER.
- Architecture tests: Distribution uses no Insurance, Finance or People code and only the accounting application layer; no other context uses the Distribution domain, infrastructure or HTTP layers.
- Tests: `tests/Feature/Distribution/ProducersTest.php` (4). One existing test changed mechanically, not weakened: `CommissionTest` updated `commission_plan_id` through
  `DB::table('agents')`, now `DB::table('producers')` (the table was renamed; assertions unchanged).
- Result: 1,019 Pest tests green, PHPStan 0 errors.

### D2 — Distribution: licences, blocking, expiry alerts, IDRA register — done
- Tables `producer_licences` (authority default IDRA, licence number unique per authority, class life | non_life | both, issued/expires dates, status active |
  suspended | revoked with reason, optional document id) and `producer_licence_alerts` (one row per licence and threshold); `products.insurance_class`. All RLS.
- Blocking (design note §3): `PolicyLifecycle::issue` asks `Distribution\Application\Licences\LicenceRegistry` before issuing a policy that has a
  producer and is not a renewal. Refusals: `PRODUCER_NOT_ACTIVE`, `LICENCE_REQUIRED` ("AG-001 has no valid non-life licence on 2026-09-01, so it cannot write new
  business."). A licence is valid from its issue date to its expiry date inclusive while active; `both` covers either class; direct business needs none.
- The design's `renewal_requires_valid_licence` is a compensation-rule flag (design note §3 "→ rule flag"): renewals are never blocked here, and D4
  adds the flag to compensation rules and D5 applies it with `LicenceRegistry::validLicenceId`.
- Expiry alerts: `LicenceExpiryAlerts` (nightly `LicenceExpiryAlertJob`, 01:45, batch queue) raises every crossed threshold of `erp.distribution.licence_alert_days`
  (60, 30, 7) once, catching up missed days, skips licences already followed by a covering licence, and queues `ProducerLicenceExpiring` outbox messages (delivery LATER).
- IDRA register export: `GET /api/distribution/licences/register?as_of=` (CSV, `reports.regulatory`). Licence API: `GET|POST /api/distribution/producers/{id}/licences`,
  `POST /api/distribution/licences/{id}/{suspend|revoke|reinstate}` (`agent.manage`; a revoked licence stays revoked).
- ASSUMPTIONS A-14, A-16, A-17.
- Test fixture changes (the new invariant needs licensed producers; no assertion changed): `seedInsuranceWorld` records a both-class licence 2020–2030 for AG-001;
  `DemoBusinessSeeder` licences its three agents for 2026; `TenantIsolationEveryTableTest` runs the licence alerts once so `producer_licence_alerts` has rows.
- Tests: `tests/Feature/Distribution/LicencesTest.php` (7). Local demo database rebuilt with `composer db:fresh`: 3 licences, 32 policies issued.
- Result: 1,026 Pest tests green, PHPStan 0 errors.

### D3 — Distribution: effective-dated hierarchy — done
- Tables `hierarchy_levels` (per scheme: level code, rank, label; codes and ranks unique within a scheme) and `producer_hierarchy` (producer, parent, level,
  `[effective_from, effective_to)`), both RLS. The scheme foreign key arrives with `compensation_schemes` in D4.
- INVARIANT one active parent: exclusion constraint `producer_hierarchy_one_parent_at_a_time` (btree_gist) rejects overlapping positions per producer.
- INVARIANT no cycles: `HierarchyService::place` writes the change, then walks the tree on the change date and on every later date where the tenant's hierarchy
  changes; a loop rolls the change back (`AGENT_HIERARCHY_CYCLE`, the Phase 1 code). Changes are serialised per tenant with an advisory lock.
- A change closes the position in force and opens a new one (transfers keep history); a second change on the same day corrects that day's position;
  a change dated before an existing later change is refused (`HIERARCHY_LATER_CHANGE`). Levels must be defined (`HIERARCHY_LEVEL_UNKNOWN`), and a parent must
  outrank its children wherever one scheme defines both levels (`HIERARCHY_LEVEL_ORDER`). `defineLevels` refuses duplicate codes or ranks and removing a
  level that open positions hold (unless another scheme defines it).
- `HierarchyQuery::hierarchyAt(producer, date)` returns the producer and everyone above it on that date (`HierarchyNode`: producer, code, level, depth);
  `toArray()` is the stored snapshot form D5 puts on commission entries.
- Migration `2026_09_18_000003`: every producer gets a position from the day it joined with its Phase 1 parent, then `producers.parent_agent_id` is dropped.
  The agent API still shows and accepts `parent_agent_id`, now meaning today's parent (`ProducerDirectory` reads it from the hierarchy).
- Test changes, not weakened: `ProducersTest` (D1) reads the copied parent from the hierarchy instead of the dropped column (same facts asserted);
  `TenantIsolationEveryTableTest` defines one level set so `hierarchy_levels` has rows.
- Tests: `tests/Feature/Distribution/HierarchyTest.php` (6). Local database migrated: 3 positions, no parents (the demo agents had none).
- Result: 1,032 Pest tests green, PHPStan 0 errors.

### D4 — Distribution: compensation schemes, rules, compliance profile — done
- Tables `compensation_schemes` (code, name, mode commission | salary_incentive | hybrid | none, effective dates, `compliance_profile` jsonb, withholding
  jurisdiction and tax type) and `compensation_rules` (product or every product, producer type or every type, level, basis premium_received | premium_written |
  net_premium, policy years 1–99, rate and override rate in basis points, rule cap, minimum persistency, `renewal_requires_valid_licence` default true,
  `pays_after_termination` default false, effective dates). RLS; CHECK constraints on modes, bases, years and rates. `hierarchy_levels.scheme_id` now references a
  scheme; `product_versions.compensation_scheme_id` names the scheme a version's policies are paid under.
- `ComplianceProfile` (domain value object): allowed producer types (null = all), `non_life_commission_allowed` (A-18, default false), caps
  (product or all, policy years, `max_total_bp` = Σ direct and overrides); the lowest matching cap applies.
- `CompensationSchemeService` checks every rule when written, in this order:
  - basis, years and rates are valid;
  - the mode pays commission (`COMMISSION_NOT_ALLOWED_BY_MODE`);
  - the producer type is allowed;
  - an override names a level, and the level is defined in the scheme;
  - rates stay within the rule's own cap;
  - non-life products need the profile switch (`NON_LIFE_COMMISSION_DISABLED`);
  - worst case per policy year (highest direct rate plus the highest override of each level among overlapping rules) stays within the cap (`COMPLIANCE_CAP_EXCEEDED`).
- A compliance profile change re-checks every rule and is refused when one breaks. Rules are ended, never edited (`endRule`).
- API under `/api/distribution/schemes`: list, create, describe, `PUT compliance-profile`, `PUT levels`, `POST rules` (`commission.manage_plans`; reads also
  `commission.approve`, `reports.financial`).
- ASSUMPTIONS A-18, A-19.
- Test setup changes: `HierarchyTest` and `TenantIsolationEveryTableTest` create real schemes for their levels (the new foreign key); the isolation scenario also adds a rule.
- Tests: `tests/Feature/Distribution/CompensationSchemesTest.php` (6).
- Result: 1,038 Pest tests green, PHPStan 0 errors.

### D5 — Distribution: compensation calculation engine — done
- The Phase 1A calculator is replaced by one engine (D-14):
  - `Distribution\Domain\Compensation\CompensationCalculator`, a pure function of scheme terms, rules in force, the hierarchy snapshot (`Beneficiary` per level) and a `Trigger`;
  - `Distribution\Application\Compensation\CompensationEngine`, which loads those on the trigger's day, records `compliance_exceptions` (once per trigger, producer and reason) and returns `CommissionAward`s.
- Calculation (design note §2):
  - no commission when the mode pays none;
  - non-life needs the profile switch;
  - the seller must be active (or terminated with `pays_after_termination` in a renewal year), licensed for the class that day (renewal years may skip it when the rule's `renewal_requires_valid_licence` is off), and of an allowed type;
  - an ineligible seller means nothing for anyone on the trigger;
  - direct commission comes from the most specific rule (product > type > level) and each level above is paid once by its most specific override rule;
  - an ineligible manager is skipped and reported, two equally specific rules block and report, and a rule with minimum persistency produces a conditional line.
- INVARIANT Σ commission rates on a trigger ≤ the compliance cap for the product and policy year: a breach blocks the calculation (no entries, `COMPLIANCE_CAP_EXCEEDED`), never the policy.
- Commission subledger (`Insurance\Commission\Application\CommissionAccrual`): one entry per beneficiary with `scheme_id`, `rule_id`, `beneficiary_role`
  (direct | override), `level_code` and `hierarchy_snapshot` (INVARIANT: later tree changes never change a payout), COMMISSION_EARNED per unconditional entry.
  Triggers: allocation (`premium_received`, policy year from the installment due date, A-21) and issue (`premium_written` on gross, `net_premium` on net).
- Clawback on cancellation per beneficiary via `ClawbackCalculator` (half-even share of unearned); conditional entries are reversed instead. A bounced allocation claws back
  every beneficiary's entry. Replay guards per beneficiary: unique (allocation, beneficiary), (policy transaction, beneficiary, rule), (reversed allocation, beneficiary).
- Golden fixtures `tests/Fixtures/compensation`, run by `CompensationGoldenTest`:
  - `01_first_year_direct`, `02_renewal_direct`, `03_two_level_override`;
  - `04_cap_hit`, `05_ineligible_producer`, `06_clawback_split`.
- Phase 1 plans keep working as flat terms (A-20). All existing commission, payout, cheque bounce, reconciliation and page tests are unchanged and green.
  The demo database, rebuilt, gives 16 commission entries for 2 agents and no exceptions.
- Test setup change: `TenantIsolationEveryTableTest` runs the engine once on a non-life trigger so `compliance_exceptions` has rows.
- Tests: `CompensationGoldenTest` (6), `CompensationEngineTest` (7).
- Result: 1,051 Pest tests green, PHPStan 0 errors.

### D6 — Distribution: advances and monthly statement run — done
- `Insurance\Commission\Application\CommissionStatementRun` (design note §2 step 6):
  - **prepare** (`commission.approve`): releases conditional commission whose rule's minimum persistency the producer meets on the period end (A-23; posted then, dated the
    period end, with the stored `withholding_bp`), then gives each producer with accrued commission up to the period end one draft statement.
    The draft's split is earned (direct) + override + bonus + clawback − withholding − proposed advance recovery = net, with the payout route (A-22).
    Rerunning rebuilds the period's drafts, and producers netting to nothing carry forward.
  - **approve** (`commission.approve`): numbers the statement, recovers advances (posting PRODUCER_ADVANCE_RECOVERED) and approves its entries.
- `CommissionPayoutService::pay` (`commission.pay`, SoD: the approver never pays — `SodGuard` on the statement) posts by route:
  - `bank` (Phase 1) → COMMISSION_PAID;
  - `payroll` → COMMISSION_PAYOUT_TO_PAYROLL (DR commission_payable / CR salary_payable) + outbox `CommissionPayrollEarning`;
  - `ap` → COMMISSION_PAYOUT_TO_AP (DR commission_payable / CR accounts_payable) + outbox `CommissionPayableToAp`.
- `Distribution\Application\Advances\AdvanceService`:
  - **issue** (`commission.pay`): PRODUCER_ADVANCE_ISSUED (DR producer_advances / CR bank_main, bank account override);
  - **recovery**: rule `full` or `percent_of_net` (bp of the statement net), oldest advance first, never beyond the balance; recoveries recorded per advance and statement.
- `commission_statements` is the producer statement (D-15). Changes:
  - new columns: `period_end`, the split, `paid_via`, `prepared_by`;
  - a `draft` status, with number and approver required once not draft;
  - one statement per producer per period, and `net = gross − withholding − advances recovered ≥ 0`.
- New tables `producer_advances` and `producer_advance_recoveries` (RLS). New account roles `producer_advances` and `accounts_payable`:
  - demo chart accounts 1160 and 2500;
  - existing tenants map the roles before issuing advances or paying through AP.
- New posting rules with golden fixtures:
  - `05d_producer_advance_issued`, `05e_producer_advance_recovered`;
  - `05f_commission_payout_to_payroll`, `05g_commission_payout_to_ap`.
- ASSUMPTIONS A-22, A-23. Phase 1 per-agent `approve` and bank payment keep working unchanged (CommissionPayoutTest green).
- Test setup change: `TenantIsolationEveryTableTest` issues and recovers an advance.
- Tests: `tests/Feature/Distribution/StatementRunTest.php` (4), `GoldenRulesTest` (+4 fixtures).
- Result: 1,059 Pest tests green, PHPStan 0 errors. Local demo database rebuilt with the new accounts.

### D7 — Distribution: targets, incentives, persistency and leaderboard — done
- Tables:
  - `targets`: producer, branch or channel × monthly, quarterly or annual period (A-24) × premium, policies, persistency or collections; one value, replaced and audited;
  - `incentive_plans`: code, period, metric, tiers, applies_to by producer type, channel or level, effective dates, optional withholding;
  - `incentive_awards`: once per plan, producer and period.
- All three have RLS. `commission_entries.policy_id` is now nullable for `bonus` entries only.
- Distribution:
  - `TargetService`;
  - `IncentivePlanService`, which validates tiers (ascending achievement in basis points, fixed or percent-of-metric bonus, percentages only on money metrics);
  - `IncentivePlanDirectory` (plans ending a period on a day, and the active producers each applies to);
  - domain `IncentiveTiers` and `IncentivePeriod`.
- Insurance:
  - `ProductionQuery`: premium, policies, collections, persistency per producer;
  - `IncentiveRun` (`commission.approve`): at a period end, measures each targeted producer, and the highest tier reached creates an award plus a `bonus` commission entry
    posted INCENTIVE_BONUS_EARNED. Reruns are no-ops (A-25). The statement run already sums bonuses into `bonus_minor`, so a salaried BDO's bonus is paid through payroll.
- Reports (`reports.financial`): `GET /api/reports/leaderboard?metric&from&to[&channel_id&branch_id]` (rank with ties, value, target and achievement when the range is a
  target period) and `GET /api/reports/persistency?as_of` (13th and 25th month, with cohort sizes, A-23).
- New posting rule INCENTIVE_BONUS_EARNED (DR commission_expense / CR commission_payable / CR commission_withholding_payable) with golden fixture `05h_incentive_bonus_earned`.
- ASSUMPTIONS A-24, A-25.
- Test setup change: `TenantIsolationEveryTableTest` sets a target, a plan and runs incentives for July.
- Tests: `tests/Feature/Distribution/IncentivesTest.php` (5), `GoldenRulesTest` (+1).
- Result: 1,065 Pest tests green, PHPStan 0 errors.

### D8 — Distribution: screens — done
- Design note §6 on the UX brief's components (QueueView, DataTable, Drawer + FormLayout, JournalPreviewDialog, StatusBadge, tabs as on object pages).
  Sidebar (secondary): Producers, Hierarchy, Schemes, Statement run, Targets; each link follows its area permissions.
- **Producers** `/distribution/producers`: the queue has a "Needs attention" column (Licence expiring within 60 days, No valid licence, Advance outstanding,
  Statement pending), level, parent, licence expiry, advance balance and pending statements, plus a "New producer" drawer for any type (standard channel by default).
- **Producer page** `/distribution/producers/{id}`:
  - header facts (type, channel, branch, level, licence, advances outstanding);
  - tabs: Overview (licences, advances) · Hierarchy (chain, team, positions held, dated transfer) · Compensation (entries by direct, override or bonus, and "Not paid, and why"
    from compliance exceptions) · Production (month, quarter and year premium with target, policies and collections, plus 13th and 25th-month persistency) · Statements ·
    Documents (not built, says so) · Audit (deferred);
  - actions: record licence, issue advance (journal preview), change status.
- **Hierarchy tree** `/distribution/hierarchy?scheme&on`: tree on a date with scheme level names, and drag a producer onto its new manager or onto "Top of the tree".
  The keyboard does the same (arrows, Left/Right to collapse, M to move) and a "Move…" button is on each row; every move asks for its effective date.
  Server refusals (cycles on any later date, rank order) come back as form errors. Tree logic lives in `lib/hierarchyTree.ts` (Vitest).
- **Schemes** `/distribution/schemes`, **scheme editor** `/distribution/schemes/{id}`:
  - compliance profile (producer types, non-life switch, caps in percent) and levels (rank, code, name), each saved as a whole;
  - rules table, an "Add rule" drawer with rates in percent (stored as basis points exactly, `PageSupport::basisPoints`) and flags, and end a rule from a date;
  - refusals show the service's message ("In policy year 1 commission could reach 3750 basis points … above the cap of 3500.").
- **Statement run** `/distribution/statements?period_end`: month picker, Run incentives, Prepare statements (asks before rebuilding drafts), and the split per producer with zeros left
  blank and footer totals. The inspector shows the entries, and Approve / Pay go through the journal preview.
  The approver is told someone else pays; the server enforces it (SodGuard).
- **Targets grid** `/distribution/targets?period_type&period_start&metric`: tabs Producers · Branches · Channels, editable target cells (Enter or leaving the cell saves; money, count or
  percent as the metric needs), actual and achievement; branch and channel actuals add up their producers.
- Composition controllers at the app layer where a screen needs both contexts (`App\Http\Distribution\ProducersPageController`, `TargetsPageController`); the scheme and
  hierarchy screens live in Distribution, the workbench in the Insurance commission module. Money movements (advance, approve, pay) use the `moves-money` preview.
- Screenshots: `storage/ux-screenshots/D8/{producers,producer,hierarchy,schemes,scheme,statements,targets}-{1366,1920}-{light,dark}.png`, against the demo data of the End step.
  Self-critique fixed: producer type words (BDO, Agency), zero amounts blank in the statement split, entry kinds in sentence case, duplicate toolbar links removed,
  the statement title shortened (wrapped at 1366 with the inspector open), and the "end rule" label.
- axe-core on all seven screens in light and dark: one critical finding (cap product selects without a name) fixed; no violations remain.
- Not achieved: no sidebar badge for expiring licences (the shell badges come from role work queues, and there is no distribution queue yet); drag-transfer has no touch support.
- Tests: `tests/Feature/Distribution/DistributionScreensTest.php` (6), `resources/js/tests/hierarchy-tree.test.ts` (4).
- Result: 1,071 Pest tests, 231 Vitest tests green, PHPStan 0 errors, vue-tsc and build green.

### D9 — Distribution: producer portal REST — done
- Laravel Sanctum 4.3 (composer). `personal_access_tokens` is a tenant table (tenant_id, forced RLS, UUIDv7 ids, uuid morphs) with the model
  `Platform\Authentication\PersonalAccessToken`; the tenant is resolved before authentication, so a token only authenticates in the tenant that issued it (D-16).
- `users.kind` (`staff` | `portal`): Fortify web sign-in accepts staff only; the token endpoint accepts portal users only. `producers.portal_user_id` links a producer to
  its portal user; `Distribution\Application\Portal\ProducerPortalAccess::grant` (`agent.manage`) creates it through `Platform\Administration\PortalAccounts`
  (portal role scoped to the producer's branch, invitation e-mail to set a password) (A-26).
- Endpoints under `/api/portal` (composition in `App\Http\Portal`, middleware `auth:sanctum` + `producer-portal` + Sanctum abilities):
  - `POST tokens` (sign in, throttled) and `DELETE tokens/current` (sign out);
  - `portal:read`: `GET me`, `licence`, `customers`, `policies[?status]`, `policies/{id}` (with installments and outstanding), `renewals-due[?within_days]`, `collections-to-deposit`,
    `statements`, `statements/{id}` (with entries), `targets[?period_type&period_start]`;
  - `portal:collect`: `POST collections`, a cash collection for an installment of the producer's own policy, recorded through `ReceiptService` as an agent collection
    (allocation limits, numbering, posting and agent cash all as for staff).
  - Another producer's record is 404; a suspended producer gets 403 `PRODUCER_NOT_ACTIVE`.
- OpenAPI 3.1 generated from the routes and a `PortalOperation` attribute on each controller method plus `PortalSchemas`: `php artisan portal:openapi` writes
  `docs/api/producer-portal.openapi.json`. `ProducerPortalTest` fails when a portal route is undocumented or the file is out of date. No UI (design note §5 LATER).
- Test setup change: `TenantIsolationEveryTableTest` grants portal access and creates a token so `personal_access_tokens` has rows.
- Tests: `tests/Feature/Distribution/ProducerPortalTest.php` (5).

### Distribution (D1–D9) — end state
- All nine slices are done, one commit each, following docs/distribution-module-design.md §7 MVP. Decisions D-12 to D-16; ASSUMPTIONS A-14, A-16 to A-26
  (there is no A-15).
- **Seeded example** (`DistributionDemoSeeder`, run by `composer db:fresh`, local only):
  - **Life, commission mode:** `LIFE-AGENCY` on the Endowment product has levels FA < UM < BM, 25% first-year and 5% renewal direct, UM overrides of 5% / 1% and a BM override of 2%,
    caps of 35% for the first year and 10% for renewals, and 5% withholding. BM-01 → UM-01 (FA-01, FA-02), UM-02 (FA-03).
  - **Non-life, salary and incentives:** `NL-BDO` on SME fire has commission disabled (profile `non_life_commission_allowed` false). BDO-01..03 are on payroll, with a monthly premium incentive
    plan (100% → fixed bonus, 125% → 1% of premium) and targets.
  - **Activity:** August statements prepared and approved, three paid (accounts payable and payroll); September drafts; FA-02 has an advance being recovered and a licence expiring
    on 20 Oct 2026. A local-only `payer@demo.local` holds `commission.pay`, because no §7.2 role template grants it.
  - `DistributionDemoSeederTest` checks both modes: direct FA and UM/BM override entries, no BDO commission, bonuses through payroll, recoveries, and no failed events.
- **Not achieved, and why:**
  - LATER in the design note: leads and activities, contests, bancassurance settlement, full proposal submission in the portal, IDRA electronic returns (the register export is CSV, A-16).
  - Payroll and accounts payable are Phase 2 modules not built yet: payouts move the net to `salary_payable` / `accounts_payable` and queue
    `CommissionPayrollEarning` / `CommissionPayableToAp` outbox messages, but nothing consumes them yet. Licence-expiry alerts are likewise queued, not delivered.
  - Existing tenants must map the new account roles `producer_advances` and `accounts_payable` before issuing advances or paying through AP (the demo chart has 1160 and 2500).
  - `commission.pay` is in no §7.2 role template, so a tenant must give it to a role before statements can be paid. It is worth asking the customer who pays commission.
  - The design note's OPEN 1 (IDRA caps and the non-life circular) and OPEN 3 (renewal commission after termination) stay open as configurable defaults (A-18, A-19); the level names
    and rates of OPEN 2 exist only in the seeded example.
  - Phase 1 commission plans still work as flat terms (A-20) and are not migrated into schemes; product versions move to schemes one by one.
  - No staff screen grants portal access yet (service `ProducerPortalAccess::grant`), and there is no portal UI (design note §5).
  - No sidebar badge for expiring licences; hierarchy drag-transfer has no touch support.
- Final gate: 1,077 Pest tests, 231 Vitest tests green, PHPStan 0 errors, vue-tsc and production build green; `composer db:fresh` builds the demo with both modes.
- **Pending after Distribution:** 2.0c Playwright E2E happy path, 2.0d claim reserve property test, 2.1 design addendum v2 (rows above).


### S1 — Onboarding: setup wizard — done
- Market cross-check G9 / Part A. The first sign-in to a tenant with no products, while nobody has finished setup, goes from Home to `/setup` for any user who can do a step
  (`App\Http\Setup\SetupWizard::shouldOpenFor`); others see Home. Admin → Setup reopens it at any time.
- One page, six steps (company and branches → fiscal year and currency → chart of accounts → first product → users and roles → done), each posting on its own and recorded in
  `setup_progress` (tenant table, forced RLS). Step services, all audited:
  - `Platform\Setup\CompanySetup`: the legal entity and branches; saving again renames by code and adds, never removes;
  - `Accounting\Application\Setup\FiscalYearSetup`: LOCAL book, twelve open periods, tenant fiscal start month and base currency;
  - `Accounting\Application\Setup\ChartOfAccountsSetup`: template `resources/setup/chart-of-accounts/non-life-insurance.csv` (32 accounts, the demo tenant's roles plus cash, advance tax,
    share capital, rent and stationery) reviewed and edited in a table, imported through the ordinary `ChartOfAccountsImport` (validation errors land on the row and field),
    then control accounts registered in `subledger_controls`, which the import alone never did;
  - first product through `ProductCatalogue` (term, class, monthly earning) with `Platform\Tax\TaxRateSetup` for VAT;
  - users through `UserAdministration::invite` and `RoleAssignmentService::assign` (so SoD holds).
  Gating and defaults: A-27, A-28. Decision D-17.
- `php artisan erp:tenant <slug> "<name>"` creates a tenant on its first day (`BlankTenantSeeder`: roles and SoD rules only) plus `admin@<slug>.local`; sign in at `http://<slug>.localhost:8000`.
- `Stepper` gains `free` (any step opens, ticks show saved steps) and `wide`.
- Test setup change: `TenantIsolationEveryTableTest` records a setup step so `setup_progress` has rows.
- Screenshots: `storage/ux-screenshots/s1-wizard/` (company, chart of accounts, product, users, done; 1366/1920, light/dark).
- Tests: `tests/Feature/Setup/SetupWizardTest.php` (9).

### S2 — Onboarding: Part A demo story (`php artisan erp:demo`) — done
- `php artisan erp:demo [--tenant=nonlife]` (local, staging and testing only) seeds the market cross-check Part A week in its own tenant, Padma General Insurance, through the
  application services, acted by one user per role (`<role>@nonlife.local`, admin password; sign in at `http://nonlife.localhost:8000`). `PartADemoSeeder`:
  - 3 non-life products (motor, fire, marine; VAT 15% included, monthly earning), 5 customers, 2 producers: agent AG-001 Jamal Uddin on a 10% plan and salaried BDO-001 Nasima Akter
    with none (the zero-commission case: the plan sits on the agent, not the product);
  - August: bank balance brought forward (manual journal, maker/checker), four policies issued, three paid by bank transfer, a motor claim reserved at 200,000, approved at 180,000,
    released by finance and closed (20,000 released), the August statement imported and matched, every close task run and the month locked;
  - September (open): a paid policy, a payment without reference allocated from suspense by the accountant, 8,500 still in suspense, an unpaid policy, a quote, the fire policy
    cancelled, a marine claim reserved at 150,000, and the September statement imported unmatched: three lines with suggestions and two exceptions (bank charges, unknown transfer).
    The CSV is also written to `storage/app/demo/city-bank-2026-09.csv`.
- Idempotent: a tenant that already has policies is left unchanged ("already has the Part A story"). The story is one transaction, so a failure leaves nothing; inside it
  the demo posts queued accounting events itself after each step that needs them (bank matching, each close task), because the after-commit dispatch waits for the commit.
- Tests: `tests/Feature/Setup/PartADemoTest.php` (3).

### S3 — Onboarding: "How this works" panel (English and Bangla) — done
- Words in `resources/help/<module>.<en|bn>.md` for policies, receipts (with suspense), bank, claims, commission, accounting, close and reports: a title and three parts
  (what the screen is for, what happens in the accounting, the next step), five to eight sentences, taken from market cross-check Part A and its Bangla version.
- `GET /help/{module}[?locale=bn]` (`App\Http\Help\HelpController`, `HelpContent`) renders the Markdown with raw HTML escaped and unsafe links dropped; without `locale` it uses the
  user's saved language.
- `AppLayout` takes `help="<module>"`; 23 module screens set it. The top bar shows *How this works* when the screen has help; the panel opens as a right-hand column with an
  English / বাংলা switch. Open or closed and the language are user preferences (`help_open`, `locale`; `tour` is added for S4).
- Screenshots: `storage/ux-screenshots/s3-help/` (policies and receipts in English, claims in Bangla).
- Tests: `tests/Feature/Help/HowThisWorksTest.php` (4: files and sentence counts, endpoint and language, escaping, every module screen opens its help).

### S4 — Onboarding: guided tour of the Part A flow — done
- Eight steps from Home: work queues → issue a policy → receive the premium → allocate suspense → import the bank statement → register a claim → reserve, approve and pay it →
  run the month-end close. Words in `resources/help/tour.<en|bn>.md` (`## <step id>`, `### title`, text), served by `GET /help/tour`; the wiring (page and `data-tour` anchor of
  each step) is `resources/js/lib/tour.ts`, and a Vitest check keeps the two in the same order.
- `GuidedTour` (in `AppLayout` while the tour is active): an accent ring and scrim around the step's element that never blocks the page, so the user can do the step for real,
  and a card with the step number, what to do and what the accounting does, Back / Next / End tour (Esc). On another page the card offers *Go to this step*.
  The card sits below or above the spotlight, or in the bottom-right corner when the spotlight fills the window.
- State is the user preference `tour` (`active` | `dismissed` | `finished`, step), so it survives sign-out and devices. Home shows *Take the guided tour*, *Resume the tour (step n of 8)*
  or *Take the tour again*.
- The tour explains each step; it does not create records or move money. Steps follow segregation of duties, so no one person can do them all: each step names the permission
  that does it and its role template; a user without it is told who does the step (and, in the `nonlife` demo company, which `<role>@nonlife.local` account to use), and a page the
  user cannot open is not visited — the card stays where the user is.
- Screenshots: `storage/ux-screenshots/s4-tour/` (every step on its page, plus the card on another page; 1366/1920, light/dark).
- Tests: `tests/Feature/Help/GuidedTourTest.php` (2), `resources/js/tests/tour.test.ts` (3).

### S5 — Onboarding: plain captions on journal lines — done
- `resources/help/roles.<en|bn>.md`: a table of every account role (all 28 in `AccountRolesSeeder`) with what a debit and a credit mean, e.g. premium_receivable debit
  "Customer owes us the premium", unearned_premium credit "Cover not yet provided — a liability until time passes". Served by `GET /help/roles` (`HelpContent::roleCaptions`).
- Journal lines sent to the screens now carry their account role (`role`): `ObjectHistory::accounting` (every object page's *View accounting* drawer and Accounting tab) and
  `PreviewJournal` (the confirmation before money moves); a line without a role on it (manual journals) takes its account's current role mapping (`PageSupport::accountRoles`).
- `AccountingList`, `JournalPreviewDialog` and the journal viewer show the caption under each line in the user's language (`lib/captions.ts`); an account with no role
  (an ordinary expense account) has no caption.
- Test change: the two exact line assertions in `JournalPreviewTest` and `ObjectPagesTest` now also expect `role`.
- Screenshots: `storage/ux-screenshots/s5-captions/` (the *View accounting* drawer and the Accounting tab of the demo motor policy).
- Tests: `tests/Feature/Help/AccountingCaptionsTest.php` (3), `resources/js/tests/captions.test.ts` (2).

### S6 — Onboarding: empty states — done
- UX brief §4: every queue's empty state is one sentence and one action. `QueueView` falls back to the queue's own primary action when no `emptyAction` is given, and
  `DataTable` empty actions can be a link or a button (the page's handler), so "Add a bank account", "New party", "Prepare statements" open the same form or run the same step.
- Rewritten: journals, roles, users, approvals (Back to Home), bank accounts, close (Open the fiscal year in the setup wizard), payout statements, producers, schemes, statement run
  (Prepare statements), parties, cheques and reminders (Record a receipt), refunds (Open policies). Policies say "a product has to be set up first" with *Set up a product* while the
  tenant has none; products offer *Set up the first product*.
- Home queues carry `emptyAction` (e.g. "No unallocated receipts." → *Import a bank statement*, the brief's own example). Home also shows *Continue setup* while the tenant has no
  products and the user can do a setup step, and locally, while there are no policies, how to load the Part A demo (`php artisan erp:demo`).
- Shared `shell.onboarding` (`setupNeeded`, `canSetup`, `demoCommand`; `lib/onboarding.ts`).
- Tables inside a workbench (`:url-sync="false"`: bank matching panes, allocation candidates, report rows, scheme rules, commission entries and plans) keep their sentence without an
  action: the action is already beside them.
- Screenshots: `storage/ux-screenshots/s6-empty/` (a tenant with company, periods and chart of accounts but no products: Home, policies, products, receipts, claims).
- Tests: `tests/Feature/Help/EmptyStatesTest.php` (2), `resources/js/tests/empty-states.test.ts` (one per queue screen, parsed from the templates).

### Onboarding (S1–S6) — end state
- Goal (market cross-check G9): a first-time user can run the Part A "week in a non-life insurer" without help. Done in six slices, one commit each, plus one fix
  (the tour names who does a step). No business rules were added: onboarding calls the existing services (D-17). ASSUMPTIONS A-27, A-28.
- **Try it:**
  - `php artisan erp:tenant acme "Acme General Insurance"`, then sign in at `http://acme.localhost:8000` as `admin@acme.local`: the setup wizard opens.
  - `php artisan erp:demo`, then sign in at `http://nonlife.localhost:8000` as any `<role>@nonlife.local` (the login page lists them): Home → *Take the guided tour*.
- **Screenshots** (1366 and 1920 wide, light and dark), in `storage/ux-screenshots/`:
  - `s1-wizard/`: wizard steps;
  - `s3-help/`: "How this works" in English and Bangla;
  - `s4-tour/`: every tour step, the card on another page, a role hint, and a step the user cannot open;
  - `s5-captions/`: captioned accounting;
  - `s6-empty/`: empty states of a tenant without products.
- **Part A steps that cannot be completed in the UI (product gaps to report back):**

  | Part A step | What is missing | Gap |
  |---|---|---|
  | 1. New policy | No vehicle or risk details and no sum insured on the policy; the premium is typed in, not rated. | G1 |
  | 1. VAT and stamp duty added automatically | VAT only: a product version has one tax type, so stamp duty is neither calculated nor posted (the wizard says so). | new (tax engine) |
  | 2. Policy number `POL-HO-2026-000123` | Numbers have no branch code (`POL-2026-000001`). | minor |
  | 3. Receipt number printed for the customer | No printable receipt; there are no documents or PDFs (schedule, cover note, receipt). | G2 |
  | 5. Register the claim with documents | Documents cannot be attached; the Documents tab says so. | G2 / new |
  | 7. Approve within their limit, otherwise it routes up | Approval limits exist in the engine (`approval_policies`), but no screen sets them; a new tenant has none, so every approval is within limit. | new (admin screen) |
  | 10. Vendor bills (AP) and salaries | Neither module exists; only a manual journal can record an office expense. | G6 / Phase 2 |
  | 14. Regulatory returns | Premium register (per transaction, not totalled by class), outstanding claims and loss ratio are in Reports. There is no unearned premium reserve report or IDRA form. The agency register export (A-16) exists only as an API, with no screen. | G5 |
  | All steps, one person | By design (§7.3 segregation of duties) no single role does the whole week. The local admin cannot issue policies, record receipts or register claims. The tour says which role, or demo account, does each step. | by design |

- **Found and fixed along the way:**
  - no role template could maintain the chart of accounts (A-28);
  - a chart imported by file never registered its control accounts with their subledgers (the wizard now does);
  - the posting preview and accounting panels did not carry account roles.
- **Not done, and why:**
  - The help text is plain Markdown served as escaped HTML; there is no in-app editor.
  - Bangla covers the help panel, the tour and the line captions only; the rest of the interface is English (brief §8 localisation is LATER).
  - The Part A walk-through was checked against the screens, the feature tests and the seeded story, not by a browser test clicking through every step; that is 2.0c (Playwright), still pending.
  - A tenant without a company gets 404 on business pages until wizard step 1 is saved; the Home redirect normally prevents reaching them.
- Final gate: 1,100 Pest tests and 269 Vitest tests green, PHPStan 0 errors, vue-tsc and production build green.
- **Pending after onboarding:** 2.0c Playwright E2E happy path (now with the Part A demo as its data), 2.0d claim reserve property test, 2.1 design addendum v2; G1–G5 per the market cross-check.

### F1 — Policy numbers carry the branch code — done
- Closes the Part A gap "2. Policy number `POL-HO-2026-000123`". Policy sequences were already per entity + branch + fiscal year; the number now reads `POL-<BRANCH>-<FY>-<seq>`
  (e.g. `POL-HO-2026-000001`).
- The format lives in the numbering settings: `config/erp.php` `numbering.formats` per document type (`policy` → `{prefix}-{branch}-{fy}-{seq}`, env `ERP_POLICY_NUMBER_FORMAT`);
  other documents keep `{prefix}-{fy}-{seq}`. `{branch}` is left out with its separator for an entity-level sequence. Only new numbers use the format: issued numbers are
  stored and never rewritten (numbering INVARIANT unchanged), so existing policies keep theirs and a running sequence simply continues.
- Global search and the command palette find `POL-HO-1042` and `POL-1042` typed without year and padding (the sequence part is the last digits of the number).
- Test changes: `PolicyLifecycleTest` now expects exactly `POL-HO-2026-000001` (was a `POL-2026-` prefix); `GlobalSearchTest` derives the short form from the new format and also
  searches the branch form.
- Tests: `DocumentNumbererTest` (+2).

### F2 — Documents on claims, receipts and policies — done
- Market cross-check Part A step 5 ("register the claim with documents", G2). The Documents tab of the claim, receipt and policy pages lists the object's documents
  (name and description, size, uploaded by, date, *Download*) and, for people who may attach, a file and an optional description. Empty state: "No documents yet." + *Attach a document*.
- `Platform\Documents\DocumentStore` (generic; Platform knows no business object): `attach(objectType, objectId, UploadedFile|DocumentContents, actor, description)`,
  `list`, `find` (only through the object the document is attached to) and `download` (streamed as an attachment under the original name, `nosniff`).
  - Files go to the private `documents` disk (`storage/app/private/documents`, `serve` off, config `erp.documents.disk`) at `<tenant>/<first two hash characters>/<sha256>`:
    the same bytes are stored once, and an existing file is never written again.
  - Rows in `stored_documents` (tenant table, forced RLS): object type and id, original name, content type, size, SHA-256, disk, path, description, uploaded by (null = the system), uploaded at.
    Append-only by trigger (`DOCUMENT_APPEND_ONLY`), and CHECKs keep the hash hexadecimal and inside the path. Decision D-25.
  - Audit on the object itself: `document.attached` (name, size, hash, description; same transaction as the row) and `document.downloaded`. The timeline reads
    "Document survey-report.pdf attached by Rafiq Islam"; downloads show in the Audit tab only.
  - Refusals: `DOCUMENT_TYPE_NOT_ALLOWED`, `DOCUMENT_TOO_LARGE`, `DOCUMENT_EMPTY`, `DOCUMENT_DESCRIPTION_TOO_LONG`, `DOCUMENT_FILE_MISSING`; forms validate the same limits first. ASSUMPTION A-52.
- HTTP (authorized by the business controllers, A-53): `POST /claims|receipts|policies/{id}/documents` (multipart, back to `?tab=documents`) and
  `GET …/{id}/documents/{document}`. Page props: `documentUpload` (the POST URL, or null) and `documents` (deferred group `history`, with accounting and audit).
  Composition helper `App\Http\Pages\ObjectDocuments`.
- UI: `components/object/DocumentList.vue` inside `ObjectPage`; `formatFileSize` in `lib/format.ts`. The Documents tab no longer says documents cannot be attached.
- Test setup change: `TenantIsolationEveryTableTest` attaches a document to the claim so `stored_documents` has rows.
- Not done: no removal or replacement of a document (append-only by design), no preview in the browser, no virus scan, no documents on other objects (parties, refunds, bank lines);
  the Phase 3 generated PDFs will reuse `DocumentStore` (docs/rating-quotation-documents-design.md §3, `generated_documents.pdf_document_id`).
- Tests: `tests/Feature/Documents/ObjectDocumentsTest.php` (11: attach with hash, file and audit; page list and download; permissions; validation; append-only;
  tenant isolation; receipts and policies; one file per hash on the real local disk), `resources/js/tests/documents.test.ts` (3).
