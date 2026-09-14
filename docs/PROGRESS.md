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
| 2.0c | Phase 1 carry-over: Playwright E2E happy path | done | see git log |
| 2.0d | Phase 1 carry-over: claim reserve property test | done | see git log |
| 2.1 | Design addendum v2 and Phase 2 customer questions | done | see git log |
| 2.1b | Business clock, close pending documents and early lock (CQ-H2, CQ-C4, CQ-C5 decided) | done | see git log |
| 2.1c | Numbering fix for receipts, claims and agent deposits across branches | done (as fix G5, D-53) | see git log |
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
| A-10 | 1C.4 | Dunning schedule and grace period are not specified (spec §4 names dunning, grace and auto-lapse only): reminders at 7 and 21 days overdue, automatic lapse of an active policy after 30 days unpaid (counted from the later of due date and reinstatement), auto-lapse on. Notices are recorded and queued; delivery channels are LATER. | `config/erp.php` `collections.*` (`ASSUMPTION:`), `DunningRun`. |
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
| A-52 | F2 | Upload limit and accepted document types are not specified: 10 MB per file; PDF, JPG/JPEG, PNG, DOC/DOCX, XLS/XLSX. The file type is judged by its extension and served with that extension's content type, always as a download. PHP `upload_max_filesize` / `post_max_size` must be at least the limit. | `config/erp.php` `documents.max_upload_kb`, `documents.allowed_extensions` (`ASSUMPTION:`), `DocumentStore`; hint text in `DocumentList.vue`. |
| A-53 | F2 | Who may attach documents is not specified: claims `claim.register`, `claim.reserve` or `claim.approve`; receipts `receipt.create` or `receipt.allocate`; policies `policy.create`, `policy.issue` or `policy.endorse` — for the object's branch. Listing and downloading follow the page's own area permissions (whoever can open the object page). No one can remove or replace a document. | `ATTACH_DOCUMENTS` in `ClaimPageController`, `CollectionsPageController`, `PolicyPageController` (`ASSUMPTION:`). |
| A-54 | F3 | Who sets approval limits is not specified: a new permission `platform.manage_approvals`, held by the Tenant Admin template (existing tenants' `tenant_admin` role gets it by migration). It is platform configuration, not `accounting.*`, so the §7.3 rule `platform.manage_roles` ✕ `accounting.*` is untouched; the Tenant Admin could already give anyone any role. | `RoleTemplates`, `PermissionsSeeder`, migration `2026_09_19_000030`, `ApprovalPolicyService::PERMISSION` (`ASSUMPTION:`). |
| A-55 | F3 | **Verify with the customer** (design §7.3 OPEN: actual thresholds). Default limits offered by the setup wizard and seeded in the Part A demo: claim payment approval from 500,000 BDT → Finance Manager, then CFO (below it the Claims Manager's own approval stands, no policy); claim payment release from 500,000 → CFO; manual journal and journal reversal, any amount → Finance Manager. "> 500,000" is read as "500,000 and above" (the engine's `min_amount_minor` is inclusive). Refunds and commission payouts are not routed through the approval engine (maker-checker only), so they have no limit to set. A policy's end date is not included (`effective_to` exclusive, as the engine matches). | `ApprovalPolicyService::DEFAULTS`, `config/erp.php` `approvals.object_types` (`ASSUMPTION:`). |
| A-56 | F4 | Which accounts a role may be mapped to is not specified: an active, postable account of the entity; a control account only to a role its subledger reconciles to (`subledger_controls` of the entity and book), and such a control role only to a control account. | `AccountRoleMappingService::assertMappable` (`ASSUMPTION:`). |
| A-57 | F4 | "Roles used by active posting rules" = the line roles and the rounding residual role of every rule in force on the day that posts to the book. Overridable roles (`bank_main`) still need a mapping, because events without an override fall back to it. | `AccountRoleMappingService::rolesUsedByRules`, `unmappedRoles` (`ASSUMPTION:`). |
| A-60 | F5 | "Class" in the unearned premium report and the premium register totals is not defined: the product's line of business (`products.lob`: motor, fire, marine, …), not `products.insurance_class` (life / non-life), which would put all non-life business in one group. | `UnearnedPremiumQuery` (`ASSUMPTION:` docblock), `PremiumRegisterQuery`. |
| A-61 | F5 | How unearned premium is dated is not specified: as the ledger posts it (A-8) — net written premium on the issue or endorsement accounting date, each earning row on its posting date (a scheduled row on its period end, a cancellation catch-up on the cancellation date), the released remainder on the cancellation date. Postings to the control from anywhere else (a manual journal, a reversal) are not in the register and show as the reconciliation variance. | `UnearnedPremiumQuery`. |
| A-62 | F6 | The as-of date of the agency register downloaded from the producers queue is not specified: today (the route and the API still take `as_of`). The XLSX holds the same columns as the CSV (A-16), every cell as text. | `resources/js/pages/distribution/producers/Index.vue` (`ASSUMPTION:` comment), `LicenceController::register`. |
| A-65 | R1 | Rating design OPEN 3 (is premium recognised at the cover note?) and OPEN 4 (credit issuance rules) are unanswered: every product version has `recognise_at = policy` and `allow_credit_issue = false` unless set. Stored now; the quotation flow (R5–R7) reads them. | `product_versions.recognise_at` / `allow_credit_issue` (`ProductCatalogue::ratingTerms`, `ASSUMPTION:`), `PremiumRecognition`. |
| A-66 | R1 | Which duties apply to a product is not specified beyond "stamp (flat by class), VAT on premium": every duty in force for the product's class applies unless the version's `duty_profile` excludes it (`{"exclude": ["levy"]}`), so a product never silently goes without VAT or stamp duty. | `DutyProfile` (`ASSUMPTION:`). |
| A-67 | R3 | Which minimum premium wins when both the plan (a `minimum` step) and the product version (`min_premium_minor`) set one is not specified: the product's minimum is applied after the plan's premium steps and before rounding, so the higher of the two wins. | `RatingCalculator` (`ASSUMPTION` in the class docblock), `product_versions.min_premium_minor`. |
| A-68 | R2 | How duties combine is not specified beyond "stamp (flat by class), VAT 15% on premium": VAT and levies are worked on the net premium (after minimum and rounding), never on stamp duty or other duties; duties not named by a plan step apply automatically in the order stamp, levy, vat. Duty values are placeholders flagged `verify` (design OPEN 1). | `DutyDefinition::amountFor`, `RatingCalculator` (R3), `duties` rows. |
| A-69 | R2 | No §7.2 role template covers tariffs: `rating.manage_plans` (draft plans, new versions, duties) and `rating.approve_plans` (approve, activate, retire) both go to the Finance Manager and CFO templates; the SoD object rule (SOD7) stops anyone approving a plan they drafted or edited. Duties are recorded without a second approval (effective-dated, audited). Activating a plan that overlaps the active plan of its class is refused unless the caller asks to supersede it (the current plan then ends the day the new one starts, only if it started earlier). | `RoleTemplates`, `PermissionsSeeder::SOD`, migration `2026_09_20_000002`, `RatingPlanService::activate`, `DutyBook`. |
| A-80 | R4 | "Valid 15 days" is not defined further: a quotation is valid on the day it is issued and the 14 days after (`valid_until` = issue date + 14); it expires the day after, in the nightly job or when quotations are read. | `config/erp.php` `quotations.valid_days` (`ASSUMPTION:`), `QuotationService::issue`, `expireDue`. |
| A-81 | R4 | How producer eligibility affects a quote is not specified beyond "feeds referral": quoting is never blocked; the answer (licence for the product's life / non-life class, producer active — `LicenceRegistry`, A-14) is recorded on the quotation when saved and again on the issue date, and the proposal refers an ineligible producer (R5). | `ProducerEligibility`, `quotations.producer_eligible` / `producer_eligibility_reason`. |
| A-82 | R4 | Whether a quotation may be issued without a customer is not specified: a draft may be anonymous (a price for a walk-in), an issued quotation names its customer (`QUOTATION_CUSTOMER_REQUIRED`), because it is printed and becomes a proposal. | `QuotationService::issue` (`ASSUMPTION:`), CHECK `quotations_issued_complete`. |
| A-83 | R4 | No §7.2 template covers quotations: new permission `quotation.create` (rate, save, issue, decline, and in R5 turn into a proposal and record KYC) in the Branch Officer template, so also the Branch Manager; existing tenants' roles get it by migration. The quotation screens open read-only for `policy.create` holders. | `RoleTemplates`, `PermissionsSeeder`, migration `2026_09_24_000001`, `QuotationPageController::AREA`. |
| A-84 | R4 | Which day a quote is rated on is not specified: the proposed cover start (the tariff and product version in force when cover starts), and cover cannot start before the issue day (`QUOTATION_INCEPTION_IN_PAST`). | `QuotationService::rate` / `issue`. |
| A-85 | R5 | "Sum insured > limit" per user/role is not specified further: a user's limit for a class is the highest limit among the roles they hold (any scope) on the day; a submitter without one is always referred. A referral goes to the role holding `underwriting.decide` with the smallest limit covering the sum insured; when none covers it, anyone deciding referrals may take it but only decline it (approving always needs the decider's own limit to cover the sum insured). An approval policy for `proposal_referral` that matches the sum insured takes precedence. | `UnderwritingLimits::limitFor` / `rolesCovering`, `ProposalService::referralStep`, `UnderwritingDecisions::completeApproval`. |
| A-86 | R5 | No §7.2 template decides underwriting referrals: new permission `underwriting.decide` (approve, approve with a loading, decline; waive KYC) for the Branch Manager, Finance Manager and CFO templates, always within their underwriting limit; SoD object rule `quotation.create` ✕ `underwriting.decide` (whoever prepared a proposal — created it, recorded KYC, submitted it — does not decide it), on top of the engine's maker ≠ checker. | `RoleTemplates`, `PermissionsSeeder` (`SOD8`), migration `2026_09_24_000002`. |
| A-87 | R5 | Who sets underwriting limits is not specified: new permission `underwriting.manage_limits`, Tenant Admin template (like approval limits, A-54). | `UnderwritingLimits::PERMISSION`, Admin → Underwriting limits. |
| A-88 | R4, R5 | Which risk fields identify "the same risk" for the duplicate check (design §5) is not specified: motor registration number or chassis number, fire risk address; compared with letters and digits only, upper-cased; values shorter than three characters are ignored. Verify with underwriting. | `config/erp.php` `underwriting.duplicate_keys` (`ASSUMPTION:`), `RiskKeys`. |
| A-89 | R5 | Which risk flags refer a proposal (design "risk flags") is not specified: motor vehicle older than 15 years (from the year of manufacture to the submission year); fire construction class 3. Rules available: above, below, age_above, in. Verify with underwriting. | `config/erp.php` `underwriting.risk_flags` (`ASSUMPTION:`), `UnderwritingRules::riskFlags`. |
| A-90 | R5 | Underwriting limits are unknown (design §5): placeholder defaults, seeded only in the demo tenants with `verify = true` — branch officer motor 2,000,000.00 / fire 5,000,000.00 / marine cargo 2,000,000.00 / misc 1,000,000.00; branch manager 10,000,000.00 / 25,000,000.00 / 10,000,000.00 / 5,000,000.00; finance manager 50,000,000.00 each; CFO 250,000,000.00 each. No limits in a new tenant, so every proposal is referred until they are set. | `UnderwritingLimits::DEFAULTS`, `acceptDefaults`, `DemoBusinessSeeder`, `PartADemoSeeder`. |
| A-91 | R5 | The form of a "manual loading" is not specified: a percentage loading only (no manual discount), 0.01 % to 100.00 %, with a reason; applied after the plan's premium steps and the product minimum, before rounding and duties, on the quotation's rating and plan version. | `ManualLoading`, `RatingCalculator`, `RatingEngine::rerate`. |
| A-92 | R5 | KYC on a proposal is not specified: an officer (`quotation.create`) records the identity document type (NID, passport, birth certificate, trade licence, TIN) and number, or an underwriter (`underwriting.decide`) waives it with a reason; only while the proposal is a draft; pending KYC refers the proposal, verified or waived passes. Proposal documents may be attached by `quotation.create` or `underwriting.decide` holders on the branch. | `config/erp.php` `underwriting.kyc_id_types` (`ASSUMPTION:`), `ProposalService::verifyKyc` / `waiveKyc`, `ProposalPageController::ATTACH_DOCUMENTS`. |
| A-93 | R6 | Design OPEN 2 (cover note maximum validity per class) is unanswered: 30 days for every class (placeholder, verify), counted with both ends included (from 20 Sep, the last day is 19 Oct); a cover note starts today or later (`COVER_NOTE_BACKDATED`); one active cover note per proposal; an expired note can still be superseded by the policy. | `config/erp.php` `cover_notes.max_days` (`ASSUMPTION:`), `CoverNoteService::maxDays` / `issue`, partial unique index `cover_notes_one_active_per_proposal`. |
| A-94 | R6 | No §7.2 template covers cover notes: `cover_note.issue` for the Branch Officer template (so also the Branch Manager), `cover_note.cancel` for the Branch Manager; the cover notes queue opens for either or `quotation.create`. | `RoleTemplates`, `PermissionsSeeder`, migration `2026_09_24_000003`, `CoverNotesPageController::AREA`. |
| A-100 | R8 | How much of Blade a tenant-edited template may use is not specified: a whitelist — escaped output of documented variables (`{{ $name['key'] }}`, `?? 'fallback'`), `@if/@elseif/@else/@endif` over variable comparisons, `@foreach($list as $item)`, comments, `@@` for a literal @, CSS `@media`/`@page`, images as data: URIs. Everything else is refused on save with the reason (PHP tags, `{!! !!}`, every other directive, components, scripts, frames, forms, SVG, event handlers, `javascript:`, `@import`, `file:` and remote URLs). Quoted text in conditions holds no brackets, braces, quotes, `@`, `$`, `<`, `>` or `?`. A body must also render with the demo variables before it is saved or activated. | `TemplateBodyGuard` (`ASSUMPTION` in docblock), `DocumentTemplates::validate`. |
| A-101 | R8 | Who generates documents and who edits templates is not specified: `document.generate` (checked in the object's branch, plus the page's own area permission) goes to the Branch Officer template (so also the Branch Manager: they quote, issue and record receipts); `document.manage_templates` goes to the Tenant Admin template (configuration, `document.*` is not `accounting.*`, so the §7.3 rule `platform.manage_roles` ✕ `accounting.*` is untouched). Existing tenants' roles get them by migration. Claims officers get nothing yet (claim acknowledgement and discharge voucher have no provider). | `RoleTemplates`, `PermissionsSeeder`, migration `2026_09_26_000001`, `DocumentGenerator::PERMISSION`, `DocumentTemplates::MANAGE`, `GeneratedDocumentsController`. |
| A-102 | R8 | Which language and template a document prints with is not specified: the person generating chooses English or Bangla (default `erp.documents.default_locale` = en); the active template of the object's product class wins over the template for every class; with neither, generation is refused (`DOCUMENT_TEMPLATE_MISSING`), never falls back to another language. | `DocumentGenerator::generate`, `DocumentTemplates::active`, `config/erp.php` `documents.default_locale`. |
| A-103 | R8 | Number and date style on Bangla documents is not specified (brief §8 makes Bengali digits optional): Latin digits, thousands separators, negatives in parentheses and dates like "14 Sep 2026" in both languages; labels and fixed wording in Bangla. | `DocumentValues` (`ASSUMPTION` in docblock). |
| A-104 | R8 | What each provided document contains is not specified beyond design §3: a schedule only once the policy is issued (not for a quote) with every endorsement's date and reason — and, when policies carry a frozen rating result (R7), its manual adjustments (steps whose code starts with `manual`), its `special_terms` list, duties by name and the rating breakdown — as special terms; an endorsement's number is the policy number plus `/E<n>` (n = its order among the policy's endorsements) and its PDF is listed on the policy; a receipt shows the allocations still standing (reversed ones left out), what is held in suspense, the payer (the receipt's party, else the first allocated policy's holder), and a bounced cheque gets no receipt. | `PolicyScheduleDocumentData`, `EndorsementDocumentData`, `ReceiptDocumentData`. |
| A-105 | R8 | What the footer's "short hash" is, is not specified: a PDF cannot print its own hash, so the footer shows "Generated <date time in the tenant's time zone> · Reference <12 hex>", the start of the SHA-256 of the rendered letterhead and body (`generated_documents.content_sha256`); the SHA-256 of the PDF bytes is `generated_documents.sha256` (= the stored document's). | `TemplateRenderer::document`, `DocumentGenerator`. |
| A-106 | R8 | Template editing workflow is not specified beyond "editing an active template creates a new draft version; activating retires the previous": a draft is edited in place, one open draft per code, class and locale (`DOCUMENT_TEMPLATE_DRAFT_EXISTS`), activation needs no second person (templates move no money; audited), drafts are not deleted from the screen, and documents already generated keep the template version they were printed with. | `DocumentTemplates` (`saveDraft`, `activate`). |
| A-107 | R8 | **Verify with the insurer (legal wording).** The default templates' fixed wording is a placeholder: closing sentences ("This schedule forms part of the policy…", "Payments by cheque… subject to realisation", "…full and final settlement…"), signature lines, section titles and their Bangla translations; the demo values of the preview (Padma General Insurance, Rahima Akter, POL-HO-2026-000123, amounts) are illustrative only. | `DefaultDocumentTemplates`, `DocumentVariables::demo`. |
| A-110 | R10a | How the tariff grid saves is not specified beyond "tables grid with effective dates": a rate table is saved whole — its rows become exactly the grid's rows (added, edited, removed) in one draft-only save, audited `rating_plan.rows_replaced` with the row counts before and after; the diff view shows row by row what changed between versions. | `RatingPlanService::replaceRows` (`ASSUMPTION:`), `RateTableGrid.vue`. |
| A-111 | R10a | Units of band tables on screen are not specified (D-20 covers rate and flat tables): band bounds are the raw whole numbers the step compares (engine cc, years — or minor units when a band is on an amount) and are typed as whole numbers; a band's value is edited as a percentage (basis points, for `band_value()` in `pct()`); a band row that carries an amount instead keeps it unless a percentage is typed. | `resources/js/lib/rating.ts` (`ASSUMPTION:`), `RateTableGrid.vue`. |
| A-112 | R10a | Which version the diff compares with by default is not specified beyond "the currently active/previous version": the active version of the same code, else the version the plan was copied from, else the latest earlier version, else the latest later one. A row is the same row in both versions when its keys (a band table: its band start) and its own start date match; two rows with the same identity are paired in order. | `RatingPlanDirectory::comparison` (`ASSUMPTION:`), `RatingPlanDiff`. |
| A-113 | R10a | Which duties the plan page lists is not specified: the duties of the plan's class in force today or starting later (ended ones are not listed; the audit trail keeps them), marked "verify" while flagged. A duty recorded on the page defaults to `verify = true`. | `RatingPlanDirectory::duties` (`ASSUMPTION:`), `DutiesPageController`. |
| A-114 | R10a | Who opens the tariff editor is not specified: holders of `rating.manage_plans` or `rating.approve_plans` (not report readers). Approve shows as disabled with the reason for anyone who drafted or edited the plan (created it or exercised `rating.manage_plans` on its audit trail — the SoD object rule); the server refuses regardless. | `TariffsPageController::AREA` (`ASSUMPTION:`), `RatingPlanDirectory::editors`, `navigation.ts` (Tariffs). |
| A-115 | R7 | Which products lose the typed premium is not specified beyond "products without a rating plan keep it": a product version with a product class is rated (the rating engine refuses `PRODUCT_NOT_RATED` exactly when it has none). Its policies come only from approved proposals; `PolicyLifecycle::quote` and `endorse` refuse it (`PRODUCT_RATED`), the quote form lists only products none of whose versions has a class, with a link to Quotes. Renewing a rated policy goes through `quote` and is therefore refused until renewal quotations (R9). | `PolicyLifecycle::isRated` (`ASSUMPTION` in the class docblock), `PolicyPageController::create`. |
| A-116 | R7 | "A valid, unexpired quotation basis" is not defined further: the proposal's quotation was accepted (converted) and the policy is issued on or before the quotation's `valid_until`; after that the customer is quoted again (`QUOTATION_EXPIRED`), so a premium is never held longer than the quotation promised. Configurable. | `config/erp.php` `policies.issue_within_quotation_validity` (default true, `ASSUMPTION:`), `PolicyLifecycle::issueFromProposal`. |
| A-117 | R7 | Design OPEN 4 (credit issuance rules) is unanswered: a policy is issued on credit only when its product version has `allow_credit_issue` (default false, A-65); otherwise the officer confirms the premium was received and gives its reference (1–128 characters: receipt, bank transfer or cheque), which is stored on the policy (`issue_basis = premium_received`, `premium_received_reference`) and audited. The reference is not matched to a receipt: there is no pre-issue receipt target, so the receipt is recorded after issue against the installment as today (gap). Credit by producer or customer type is not implemented (gap). The demo products (both demo stories and FIRE-SME) allow credit issue because their stories collect premiums after issue. | `PolicyLifecycle::issueFromProposal` (`PREMIUM_NOT_RECEIVED`), `product_versions.allow_credit_issue`, proposal page "Issue policy". |
| A-118 | R7 | How a rating result's duties map to the policy and its accounting is not specified beyond "gross/net/duties from rating_result": net premium → unearned premium; VAT and levies (every duty whose code is not `stamp`) → `tax_minor` and `premium_tax_payable`; stamp duty → `stamp_duty_minor` and the new role `stamp_duty_payable` (D-37). A cancellation reverses VAT as before (D-06, on `tax_minor` only) and never stamp duty: the receivable credit is at most unearned premium plus the VAT reversal, so the customer still owes the stamp duty. Verify with the insurer's accountant (stamp duty refund rules). | `RatedPremium::of`, `PolicyAccountingEvents`, rules `POLICY_ISSUED.default` / `POLICY_ENDORSED.default` version 2, `PolicyLifecycle::cancellationAmounts` (unchanged). |
| A-119 | R7 | How an endorsement's re-rated premium change is charged is not specified ("delta premium") and no endorsement pro-rata convention exists (the typed endorsement takes the delta as given): the whole annual difference between the new rating and the rating in force is charged by default; `pro_rata` charges net premium and VAT/levies for the days left (effective date to expiry, both included, over the days of the policy, half-even) and stamp duty in full. | `config/erp.php` `policies.endorsement_premium` (`full` \| `pro_rata`, `ASSUMPTION:`), `RatedPremium::minus`. |
| A-120 | R7 | Endorsement re-rating details are not specified: the change is measured against the rating in force (the latest re-rated endorsement, else the frozen issue rating), not always the issue rating; the policy's manual loading (special terms) applies again; optional coverages stay as they are unless the endorsement changes them; `endorsement_uses_current_tariff` rates on the tariff in force on the effective date (product version's pinned plan or the active plan of the class), otherwise on the issue rating's plan version, product version and rating date; the same risk again is refused (`ENDORSEMENT_NO_CHANGE`); a change of details with no premium change is recorded without an accounting event; a reason is required. The endorsement needs `policy.endorse` as before — no §7.2 template holds it (unchanged Phase 1 gap). | `PolicyLifecycle::rateEndorsement` / `endorseRisk`. |
| A-121 | R7 | What else is chosen when a proposal becomes a policy is not specified: the issue date and 1–12 installments (planned as for every policy); the policy starts on the proposal's cover start and runs the product version's term; the policyholder pays (multi-payer shares are not offered on this path, gap); the proposal's producer is the policy's agent and is checked for a licence on the issue date as `issue` does. | `PolicyLifecycle::issueFromProposal`, `ProposalPageController::issuePolicy`. |
| A-122 | R7 | "Active elsewhere" for the duplicate-risk check (design §5) is not defined further: issued or active policies not yet expired, by the risk keys of their current risk (set at issue from the proposal, moved by re-rated endorsements), whether issued from a proposal or not; open proposals (draft, submitted, approved) and issued quotations as before; an issued proposal is represented by its policy, and a proposal's own policy is not its duplicate. | `UnderwritingRules::duplicates`, `policies.risk_keys`. |
| A-123 | R7 | The extent of "rating_result frozen at issue" is not specified: the database refuses any change to a policy's `rating_result`, `risk_inputs`, `rating_plan_code`, `rating_plan_version`, `special_terms`, `quotation_id` and `proposal_id` once written, and to an endorsement transaction's premium deltas, rating result and basis (`POLICY_RATING_FROZEN`); the policy's premium totals, version, status and duplicate-risk keys still move through endorsements and the lifecycle. | Migration `2026_09_28_000001` (triggers `policies_protect_rating`, `policy_transactions_protect_rating`). |
| A-124 | R7 | Who issues a policy from a proposal and who sees the Rating tab is not specified: `policy.issue` on the proposal's branch (the branch officer template, so the officer who prepared an automatically approved proposal may issue it — underwriting segregation already applied at referral, A-86); the Rating tab opens with the policy page (its area permissions) and shows labels in the user's language preference. | `ProposalPageController` (`can.issue_policy`), `PolicyPageController::show`. |
| A-125 | R9 | The expiry register's window and buckets are not specified beyond "60/30/15/7 days": issued and active policies (with a number) expiring within the largest bucket are listed; a policy is in the smallest bucket not below its days left (8–15 days → 15); the register is built nightly and again when the queue is opened; a row keeps its policy for good (unique) and only open rows are refreshed. Verify the buckets with the insurer. | `config/erp.php` `renewals.buckets` (`ASSUMPTION:`), `ExpiryRegister::build` / `bucketFor`. |
| A-126 | R9 | No §7.2 template works renewals: new permission `renewal.manage` (offer a renewal quotation from the register, record why a policy is not renewed) for the Branch Officer template (so also the Branch Manager); the Renewals queue opens only for `renewal.manage` held in any scope (not `policy.create` / `policy.issue`, conservative), actions check the policy's branch. The renewal reports sit on the Reports page under `reports.financial` like every other report. Existing tenants' roles get the permission by migration. | `RoleTemplates`, `PermissionsSeeder`, migration `2026_09_29_000001`, `RenewalsPageController`, `navigation.ts` (Renewals). |
| A-127 | R9 | Design "status renewal_offered" collides with the quotation statuses (draft/issued/expired/converted/declined): the renewal quotation stays `issued` and the register row becomes `renewal_offered`. It is offered `erp.renewals.quote_days_before` (45) days before expiry, covers from the day after expiry, and is valid until the expiry date (renew by), not the 15-day validity of a new-business quotation; at most one open renewal quotation per policy (unique index). A nightly quotation has no creating user (system audit); one offered from the queue is the officer's. Verify T-45 and renew-by with the insurer. | `config/erp.php` `renewals.quote_days_before`, `QuotationService::offerRenewal`, `RenewalQuotations`. |
| A-128 | R9 | "Prior-claims data (NCB)" is not defined further: only when the renewal product version's risk schema has the claim-free years field (`erp.renewals.ncb_field`, `ncb_years`, motor); +1 year (capped at the field's maximum) when the expiring policy had no claim with a loss date in its period, 0 after any claim that is not rejected (a registered claim counts, conservative); the plan's NCB scale gives the discount. A claim registered after the quotation was offered does not change it (the underwriter sees the claim at referral) — gap. Verify the NCB scale (design OPEN 5, 0/10/20/30). | `NoClaimBonus::apply` (`ASSUMPTION:`), `config/erp.php` `renewals.ncb_field`. |
| A-129 | R9 | Which risk a renewal re-rates is not specified beyond "current tariff": the risk in force (the latest re-rated endorsement, else the issue rating) with its optional coverages, on the product version and active tariff in force on the renewal's cover start; the expiring policy's manual loading (special terms) is not carried over automatically — underwriting applies it again at referral. | `RenewalQuotations::offer` / `riskInForce`. |
| A-130 | R9 | Policies of products without a rating plan (typed premium) get no automatic renewal quotation and no notices: their register row stays `upcoming` (marked "no rating plan") and they are renewed from the policy page as in Phase 1 (`PolicyLifecycle::renew`, a renewal quote); the row becomes `renewed` once that renewal quote is issued. `renew` refuses a rated policy (`RENEWAL_BY_QUOTATION`) and the policy page hides *Renew* for it. | `RenewalQuotations::offer` (`RENEWAL_NOT_RATED`), `PolicyLifecycle::renew`, `PolicyPageController::show`. |
| A-131 | R9 | Notice timing and recipients are not specified beyond "reminders at configured offsets": the renewal notice goes out once a renewal quotation is offered and still issued, within `quote_days_before` days of expiry, with the `renewal_notice` PDF generated by the system (English, `erp.documents.default_locale`) and kept on the expiring policy; reminders at `erp.renewals.reminders` (30, 15, 7) days reuse that PDF; one message per policy and offset; a policy entering late gets only the latest offset due; nothing after the quotation is declined, expired or converted. Messages go to the policyholder party through every enabled channel (email and SMS, log-only adapter) — parties hold no email address or phone number yet, so gateways (LATER) need contact details first (gap). A notice whose PDF cannot be generated (no active template) is still sent without it and logged. | `config/erp.php` `renewals.reminders`, `notifications.channels`, `RenewalNotices`, `Notifier`. |
| A-132 | R9 | How a register row closes is not specified: the day after expiry without a renewal it becomes `lapsed` with reason `no_response`; when the policy is cancelled or lapses for non-payment before expiry, `lapsed` with `policy_cancelled` / `policy_lapsed` and its open renewal quotation is declined; a person records `not_renewed` with a configured reason (price, service, sold asset, moved to a competitor, no response, other — other needs a note) while the row is open or lapsed, which declines the open renewal quotation; a renewal issued later still turns any row `renewed`. Verify the reason list. | `config/erp.php` `renewals.lapse_reasons`, `RenewalReasons`, `ExpiryRegister::recordNotRenewed` / `closeFinished` / `markRenewed`. |
| A-133 | R9 | How a renewal passes underwriting and issue is not specified: a renewal is not new business — the producer's licence is not checked when the renewal quotation is offered or the renewal policy issued, and `PRODUCER_INELIGIBLE` does not refer it; the policy it renews is not a duplicate risk of it; KYC, underwriting limits, risk flags and the premium-received / credit rule apply as for new business. The renewal policy's transaction is `new` (as Phase 1 renewals, so earning and reports are unchanged); the expiring policy must be active or expired (`RENEWAL_BASE_NOT_RENEWABLE`) and becomes `renewed` in the issue transaction. | `UnderwritingRules::evaluate`, `PolicyLifecycle::issueFromProposal` / `assertRenewable`, `QuotationService::offerRenewal`. |
| A-134 | R9 | The renewal reports' definitions are not specified: the expiry register as at a date lists policies issued on or before it, not cancelled by then, expiring within the largest bucket after it (renewal status from the register, `renewed` when a renewal was issued, else `upcoming`); renewal conversion counts policies expiring in the period except cancelled ones — renewed when a renewal policy was issued, not renewed when the register closed it (its reason), it lapsed for non-payment or it expired before today without either (`no_response`), otherwise open; conversion = renewed ÷ expiring in basis points rounded half up, shown as a percentage with two decimals; grouped by branch, agent (producer) or product. | `ExpiryRegisterReportQuery`, `RenewalConversionQuery` (`ASSUMPTION` in the docblocks), `ReportsPageController`. |
| A-135 | R9 | When the renewal run happens is not specified: nightly at 00:30 (after issued quotations expire at 00:15, so an expired renewal quotation gets no reminder), one tenant at a time; a quotation that cannot be offered (for example no tariff in force on the renewal date) keeps the row `upcoming` with the reason shown on the queue and is tried again the next night. | `routes/console.php`, `RenewalRunJob`, `RenewalQuotations::offerDue`. |
| A-136 | G2 | Which screens a branch-scoped user reaches is not specified beyond design §7.2 "branch users never see other branches": the policies, receipts (list, new receipt, receipt page), claims, quotations and proposal screens open to a holder of the area's permissions in any scope; their lists and pickers (branches, policies, installments, the policy a receipt is prefilled from) show only the user's branches (entity roles: the whole entity), and another branch's record page or document download is 403 (as a missing permission). Follow-up H1 extends the same rule to the rest: suspense (and its installment picker), the allocation workbench (another branch's receipt 403, candidates from the user's branches), refunds (refundable policies and the list), agent cash (see A-159), the cheque register, dunning notices, the underwriting referral queue, cover notes, the expiry register (and its branch filter), printing and downloading quotation and cover note PDFs (another branch's 403), Home work queues and sidebar badges (each branch-bound queue limited to the reach of the area its rows open: policies, receipts, quotations, claims), global search (policies, claims, receipts) and the policy and installment lookups, which now also open to branch-scoped holders. Not branch-bound, so unchanged in content: customers, producers and payees in lookups and search (A-160), bank lines, journals, accounting events, reconciliation, the close, approvals (the inbox already decides per step) and commission statements (entity-level, no branch; their screens still open tenant-wide only). | `PermissionChecker::authorizeArea` / `reach`, `AreaReach::constrain`; `PolicyPageController`, `CollectionsPageController`, `ClaimPageController`, `QuotationPageController`, `ProposalPageController`; H1: `SuspenseQuery`, `RefundableQuery`, `ChequeRegisterQuery`, `AgentCashPositionQuery`, `ReferralsPageController`, `CoverNotesPageController`, `RenewalsPageController`, `GeneratedDocumentsController`, `WorkQueues`, `GlobalSearchQuery`, `LookupController`; `BranchScopedPagesTest`, `BranchScopedQueuesTest`. |
| A-137 | G3 | Who endorses a policy is not specified: design §7.2 gives `policy.endorse` to no role. The Branch Manager template holds it (the officer does not: changing an issued policy's premium is a manager's decision); the finance manager and CFO do not. No SoD rule involves it. **Verify** with the customer whether branch officers endorse. Existing tenants get it on the template role by migration `2026_09_30_000043_grant_role_template_permission_gaps`; roles already renamed or rebuilt in Admin → Roles need it added there. | `RoleTemplates` (branch_manager), migration `2026_09_30_000043_grant_role_template_permission_gaps`. |
| A-138 | G3 | Who pays commission payouts is not specified: design §7.2 gives `commission.pay` to no role, so no shipped role could pay an approved statement or a producer advance. The Accountant template holds it (the finance manager approves, §7.3 commission.approve ✕ commission.pay); it is added to the accountant role only, not to the shared accountant permissions the finance manager and CFO templates are built on, so no template holds both sides. **Verify** who pays with the customer. Existing tenants get it on the accountant role by the same migration; a user who already holds the accountant role together with a role holding commission.approve now holds both, which the action-time SoD guard still refuses on the same statement (the role-assignment check does not rerun for existing holders). | `RoleTemplates` (accountant), migration `2026_09_30_000043_grant_role_template_permission_gaps`, `CommissionPayoutTest`. |
| A-143 | 2.0d | Design §5.5 says only "closed ─reopen(approval)─▶ reserved": one reopening waits at a time. Asking again while a `claim_reopen` approval is pending is refused (`REOPEN_PENDING`, nothing written) and the claim page hides Reopen meanwhile; after a rejection it can be asked again. Before, a second request left a second approval that could never complete once the first reopened the claim, or that would reopen it again after a later close without anyone asking. | `ClaimService::reopen` (`ASSUMPTION:`), `ClaimPageController::show` `actions.reopen`, `ReasonMessages`; `ClaimsTest`, `ClaimsCommissionApprovalsPagesTest`. |
| A-147 | 2.0c | Which database the E2E runner may wipe is not specified: only the one named by `DB_DATABASE` set explicitly in the environment (a value only in `.env` is refused, exit 2), because the runner runs `migrate:fresh` and `erp:demo` on it; CI names `erp` in its throwaway Postgres service. `E2E_SKIP_SEED=1` reuses an already seeded database (the test is repeatable on the same story: its customer, registration and references carry a run stamp). | `scripts/e2e.sh`, `.github/workflows/ci.yml` job `e2e`. |
| A-148 | 2.0c | Which date the E2E happy path acts on is not specified: today, as a user would (the forms' own "today" defaults, `t` in the date inputs), on the Part A demo whose story sits in August–September 2026 with open periods to June 2027. The test refuses to start outside 2026-09-01 … 2027-06-30 with a message to move the demo story forward, rather than failing somewhere in the flow. Dates come from the browser clock (UTC on CI), so a run across midnight in the tenant's timezone is not covered. | `tests/e2e/happy-path.mjs` (`ASSUMPTION A-148`). |
| A-149 | 2.0c | What counts as a regression beyond the explicit checks is not specified: any uncaught page error or any HTTP 5xx response seen by the browser during a step fails the run, even when the screen recovered; console warnings and errors are only logged (to storage/e2e/console.log on failure). | `tests/e2e/lib.mjs` `createRun().step`. |
| A-150 | G5 | The customer has not chosen the receipt, claim and agent deposit number formats (CQ-E5): they carry the branch code like policies, `{prefix}-{branch}-{fy}-{seq}` (`RCT-HO-2026-000001`, `CLM-HO-…`, `ADP-HO-…`), each set in the numbering settings (env `ERP_RECEIPT_NUMBER_FORMAT`, `ERP_CLAIM_NUMBER_FORMAT`, `ERP_AGENT_DEPOSIT_NUMBER_FORMAT`). Numbers already issued keep their branch-less shape and the running sequences continue; a format chosen later applies to new numbers only. Verify with the customer (regulators check receipt numbering). | `config/erp.php` `numbering.formats` (`ASSUMPTION:`), `DocumentNumberer`; `BranchNumberingTest`. |
| A-151 | 2.1b | Which zone the business clock uses where no entity is named is not specified (CQ-H2 decides "the entity's time zone"): the tenant's first legal entity (the setup wizard's company); a tenant without an entity, `tenants.timezone`; outside a tenant or with an unknown zone name, `erp.business_clock.default_timezone` (Asia/Dhaka). Page controllers pass the entity where they have one (receipts workbench, trial balance, dunning per entity, periods). | `BusinessClock::timezone` (`ASSUMPTION`), `config/erp.php` `business_clock.default_timezone` (env `ERP_BUSINESS_TIMEZONE`). |
| A-152 | 2.1b | When the nightly runs start on the business clock is not specified: the existing times (quotations 00:15, cover notes 00:20, renewals 00:30, earning 01:00, dunning 01:30, licence alerts 01:45, reconciliation 02:00) in the default business zone, so they run just after the company's day turns; each job reads each company's today inside the tenant loop. A tenant whose entity uses another zone gets its own today but the same start time. | `routes/console.php` (`$businessZone`), the jobs under `*/Infrastructure/Jobs`. |
| A-153 | 2.1b | What counts as "dated in the period and still pending" is not specified beyond the kinds (CQ-C4): manual, adjustment and opening journals in pending_approval, approved, queued, posting or failed by posting date (drafts are the maker's and are not listed); reversal requests pending by their reversal date; claim payments pending approval, approved, release requested or release pending approval, by the release date once released, otherwise the approval date; refunds requested, by the day requested on the entity's clock (a refund has no business date before it is paid); accounting events received, queued, posting or failed, by transaction date. | `KernelPendingDocuments`, `ClaimPaymentsPendingAtClose`, `RefundsPendingAtClose` (`ASSUMPTION`). |
| A-154 | 2.1b | "Re-dated to the next open period by its approver" is not specified further: only a manual journal pending approval moves, to the first day of the period immediately after its own, which must be open (not soft-locked or locked, `NEXT_PERIOD_NOT_OPEN`); the mover must be able to decide it now (the current approval step's decider, or a single checker holding `accounting.approve_journal` who is not the maker); the approval keeps its steps; a reason is optional; audited `journal.moved_to_next_period` with both dates. Reversals, claim payments and refunds are cleared by approving or rejecting them. | `ManualJournalService::moveToNextPeriod` (`ASSUMPTION`), `ApprovalService::assertMayDecideCurrentStep`, `ClosePageController::moveJournal`. |
| A-155 | 2.1b | "A CFO" for the early lock (CQ-C5) is not a permission: the holder of `periods.reopen` in any scope (only the CFO template holds it), conservative; the reason is required and recorded on `period.locked` with `early_lock: true`; the close checklist sends it as the lock task's note. The soft lock from the last day has no exception, so an early lock is possible from the last day at the earliest. | `FiscalPeriodService::lock` (`ASSUMPTION` in the docblock), `PeriodCloseService::lockPeriod`, `close/Run.vue`. |
| A-156 | 2.1b | Keyboard dates (`t`, `+3`) counted from the browser clock; on a UTC browser they differ from the company's day in the evening. They now count from the server's `businessToday` (the business clock), and the allocation workbench's date starts from the server's today. | `HandleInertiaRequests` (`businessToday`), `DateInput.vue`, `lib/dates.ts` `businessDate`. |
| A-165 | U1 | Which day the calendar week starts on is not specified: Sunday, the first day of Bangladesh's working week (Sunday to Thursday) and CLDR's first day for bn-BD. Some offices print Saturday-first calendars. `erp.ui.week_starts_on` (env `ERP_WEEK_STARTS_ON`, `sunday` … `saturday`), shared with every page as `calendar.week_starts_on`. Verify with the customer. | `config/erp.php` `ui.week_starts_on` (`ASSUMPTION:`), `resources/js/lib/calendar.ts`, `DateInput.vue`; `date-picker.test.ts`. |
| A-166 | U1 | The app has no interface language switch, only the language of "How this works" (`preferences.locale`). The calendar's month and weekday names follow it: Bangla names with Latin digits (`bn-BD-u-nu-latn`). Typed and shown dates stay in English ("14 Sep 2026"), which is what the parser reads. Verify whether Bangla readers want Bangla month names in the field too. | `calendarLocale` in `resources/js/lib/calendar.ts` (`ASSUMPTION:`), `DateInput.vue`; `date-picker.test.ts`. |
| A-167 | U2 | What may change on an account already in use is not specified. Once any journal line names it (in any status), its type, normal side and postability stay (`ACCOUNT_HAS_POSTINGS`). An account that an account role is mapped to, today or later, cannot become a heading (`ACCOUNT_ROLE_MAPPED`). The code never changes. Name and parent may always change, but an account never goes under itself or its own child. | `ChartOfAccounts::update` (`ASSUMPTION:`); `ChartOfAccountsScreenTest`, `chart-of-accounts.test.ts`. |
| A-168 | U2 | When an account may be deactivated is not specified. Only when nothing is left on it:<br>• no ledger balance in any currency or book (`ACCOUNT_HAS_BALANCE`)<br>• no line on a journal that is not posted, reversed or cancelled (`ACCOUNT_IN_OPEN_JOURNAL`)<br>• no account role mapped to it, today or later (`ACCOUNT_ROLE_MAPPED`)<br>• no active bank account posting to it (`ACCOUNT_IN_USE`, through `AccountUsage`)<br>• no active child account (`ACCOUNT_HAS_ACTIVE_CHILDREN`)<br>Reactivating is always allowed. | `ChartOfAccounts::deactivate` (`ASSUMPTION:`); `ChartOfAccountsScreenTest`. |
| A-169 | U2 | The screen does not change an existing account's control flag, subledger or currency, because they decide reconciliation and which journals may post. A wrong one is corrected with a new account and a journal that moves the balance. Verify with the customer's accountant. | `ChartOfAccounts::update` (`ASSUMPTION:`), `ChartOfAccountsPageController::update`. |
| A-159 | H1 | Which agents a branch-scoped user sees on the agent cash screen is not specified: the agents whose own branch (`producers.branch_id`) is within their reach, each with the whole position (collections on any branch's receipts, deposits, and the agent_receivable GL by agent), so the difference column still reconciles; the agent picker for a deposit lists the same agents. This matches `AgentDepositService`, which checks `receipt.create` on the agent's branch. An agent collecting for another branch shows under the agent's own branch. | `AgentCashPositionQuery::position` (`ASSUMPTION A-159`), `CollectionsPageController::agentCash`; `BranchScopedQueuesTest`. |
| A-160 | H1 | Whether customers, producers and claim payees are branch-bound is not specified: they are not. A party can buy from, sell for or be paid by any branch, and a branch officer quoting for an existing customer must find them. So customer, agent and payee lookups and customer search results are not limited by branch (they open to branch-scoped holders of the area). Policies, installments, claims and receipts are branch-bound and limited to the user's reach. | `LookupController::search`, `GlobalSearchQuery::search`; `BranchScopedQueuesTest`. |

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

### F5 — Reports: unearned premium and premium register totals — done
- New report *Unearned premium* (`/reports/unearned-premium?as_of=`, API `GET /api/reports/unearned-premium?entity_id=&as_of=`, `reports.financial`): one row per policy
  with unearned premium at the date (policy → policy page, product, class, branch, net premium, earned to date, unearned), totals by class and by product, and a reconciliation:
  unearned in the register, the `unearned_premium` role's ledger balance at the date (→ account activity) and the variance. `UnearnedPremiumQuery` rebuilds each policy's
  balance from dated business rows the way the posting rules move the control (A-61), so the variance is zero while only policy, earning and cancellation events post to it;
  the ledger side is `FinancialStatementsQuery::roleBalanceByDimension`.
- *Premium register*: new columns class and branch, summary tables *Totals by class* and *Totals by branch* (also `by_class` / `by_branch` in the API), and the policy number
  links to the policy page (the row still drills to its journal).
- `reports/Show` takes optional `links` per row (a link per cell) and `summaries` (small tables under the totals). Both reports are in the reports index.
- ASSUMPTIONS A-60, A-61.
- Tests: `tests/Feature/Reports/UnearnedPremiumReportTest.php` (4: zero variance on twelve dates through issue, endorsement, monthly earning and a mid-month cancellation with a
  negative catch-up; a stray posting shows as variance; register totals by class and branch; pages, drill links, API and permission refusal).

### F6 — Distribution: agency register export on the producers queue — done
- The producers queue toolbar shows *Export agency register: CSV · XLSX* to people with `reports.regulatory` (page prop `can.export_register`). Both are plain links to the new
  session-authenticated web route `GET /distribution/licences/register?format=csv|xlsx[&as_of=]`, which is the existing `LicenceController::register` action (same permission,
  same `IdraRegisterExport`), so the web download and `GET /api/distribution/licences/register` return identical files. The API also accepts `format=xlsx`.
- XLSX: `IdraRegisterExport::xlsx` writes the CSV's header and rows as one sheet through `Platform\Exports\XlsxWriter`, a dependency-free writer (DECISION D-28). No package
  was added. The export is not audited, like the API export and the other exports.
- ASSUMPTION A-62.
- Tests: `tests/Feature/Distribution/AgencyRegisterExportTest.php` (4: the web CSV equals the API CSV; the XLSX is a valid zip whose sheet holds the same rows, including
  quotes and markup; refusal without `reports.regulatory`, an unknown format, and the toolbar permission prop; column letters past Z and XML escaping).

### F3 — Approval limits screen — done
- Market cross-check Part A step 7 ("approve within their limit, otherwise it routes up"): approval limits existed in the engine (`approval_policies`) but no screen set them.
- **Admin → Approval limits** `/admin/approval-limits` (sidebar, secondary, after Roles; `platform.manage_approvals`, A-54): a queue of policies by what they approve —
  Claim payment approval, Claim payment release, Manual journal, Journal reversal, Claim reopening, Period reopening (`config/erp.php` `approvals.object_types`, the object types
  the engine requests) — with the amount band in BDT, the approvers in order, from / until and status (in force, scheduled, ended). A drawer adds or changes a policy (what it approves,
  from amount (included), below amount (not included), one to five steps each with a tenant role, dates) and another ends it.
- `Platform\Approvals\ApprovalPolicyService`, every change audited (`approval_policy.created | updated | ended`):
  - effective-dated, history never rewritten: a policy in force ends the day its change starts and a successor takes over (the audit links it with `replaces`); a scheduled policy is
    edited in place; an ended policy does not change; new policies and changes start today or later;
  - refused: no step, a role the tenant does not have, amounts the wrong way round, and a policy overlapping another of the same object type on amount band and dates
    (journal `kinds` conditions are kept on change and only overlap when they meet);
  - a step is stored as `{permission, role}` in the engine's existing steps array. The engine now also honours `role`: the decider must hold the role, the permission stays the
    duty for SoD and audit; steps without a role are unchanged (D-26). `ApprovalInboxQuery` follows the same rule, so the inbox and the sidebar badge show role steps to role holders.
  - a role named by a policy in force or scheduled cannot be deleted (`RoleAdministration`, ROLE_IN_USE).
- Defaults (A-55, placeholders to verify): claim payment approval from 500,000 → Finance Manager then CFO; claim payment release from 500,000 → CFO; manual journal and journal
  reversal → Finance Manager. Not seeded in `DemoTenantSeeder` (used by nearly every test); set by the setup wizard and seeded at the end of `PartADemoSeeder` (after the story, whose
  approvals are unchanged).
- **Setup wizard:** new optional step *Approval limits* between *Users and roles* and *Done* (owner Tenant Admin): lists the defaults, *Use these limits* creates those that do not
  overlap an existing policy (running it twice adds nothing), *Skip for now* moves on. `SetupProgress::STEPS` gains `approvals`.
- Refunds and commission payouts are not routed through the approval engine (maker-checker only), so the screen offers no limit for them; routing them would need their services to
  request approvals and handlers for the pending state — a change beyond this fix.
- Migration `2026_09_19_000030_add_manage_approvals_permission` (permission + grant to existing tenants' `tenant_admin`).
- Test changes: `SetupWizardTest` — the step indices after *Users and roles* moved by one (`steps.5.id` is now `approvals`, `steps.6.id` `done`; the users step redirects to
  `step=approvals`), plus a new wizard test; `PermissionsTest` and `RoleAdministrationTest` expect `platform.manage_approvals` in the Tenant Admin template; `PartADemoTest` also checks the
  four default limits.
- Tests: `tests/Feature/Platform/ApprovalLimitsTest.php` (6: screen and permission, validation and overlap, effective dating and audit, claim payment routed to Finance Manager then CFO
  through `ClaimPaymentService` and the inbox, role deletion guard, defaults), `SetupWizardTest` (+1).

### F4 — Account role mapping screen — done
- Design §3.4: posting rules post to account roles; a role without an account makes its events fail (`UNMAPPED_ROLE`), and until now nothing showed which roles had which account.
- **Accounting → Account roles** `/accounting/account-roles` (sidebar, secondary, after Imports; `accounting.manage_coa`, Finance Manager and CFO): entity and book selectors (shown even with
  one of each), a queue of every account role (plain description, code muted) with the account (code · name) in force today, from / until and status (mapped, no account, not used);
  the inspector shows the rules that use the role, whether it is a subledger's control role and the mapping history; a drawer maps or remaps the role to an account from a date
  (control roles offer control accounts, other roles the rest).
- Warning banner at the top: "N account roles used by the posting rules have no account: …", with *Show only these* filtering the queue to them.
- `Accounting\Application\AccountRoles\AccountRoleMappingService`:
  - `roles(entity, book, date)` (current mapping and history), `rolesUsedByRules(book, date)` and `unmappedRoles(entity, book, date)` read the real posting rules through
    `PostingRuleRepository` (A-57);
  - `map(entity, book, role, account, from)` ends the current mapping that day and inserts the new one; refuses overlaps, a remap to the same account, a date already posted with the
    role (D-27), and accounts that cannot take the role (A-56); audited as `account_role.mapped` with before and after.
- **Setup wizard**, chart of accounts: the import, its mappings and control accounts now commit in one transaction that ends by checking every role the posting rules in force use
  has an account; otherwise nothing is written and the step lists the missing roles (`SETUP_ROLES_UNMAPPED`). The non-life template already maps every role the rules use
  (checked by the test), so `resources/setup/chart-of-accounts/non-life-insurance.csv` is unchanged; `dac_asset` and `recovery_receivable` are used by no rule in force.
- No migration, no new permission.
- Tests: `tests/Feature/Accounting/AccountRolesTest.php` (5: remap with effective dates, history and audit; overlap refusals; account and permission refusals; no remap over posted
  days; unmapped roles against the real rules; screen props, banner data and the map endpoint), `SetupWizardTest` (+1: template covers the rules, refusal with an extra rule writes
  nothing, success once the account is added).

### Flow audit after F1–F6 — done
- `scripts/flow-audit.mjs` walks market cross-check Part A steps 1–14 in a browser as each step's role on the Part A demo company; results and the run instructions are in
  `docs/flow-audit.md` (results in `storage/flow-audit/`). Latest run: 9 pass, 5 partial, 0 fail — the partials are rating (G1), stamp duty, printed documents (G2), AP/payroll (G6)
  and IDRA forms (G5). Steps 2 (number), 5 (documents), 7 (limits) and 14 (UPR, register by class, agency export) moved to pass or closer through F1–F6.
- Observations to decide on are listed there: default dates may follow UTC rather than the tenant's time zone; a month can be locked with a manual journal pending approval
  in it and before the month ends; the finance manager lacks `reports.regulatory`; without a queue worker the close shows variances.
- Final gate after merging F1–F6: 1,134 Pest tests and 277 Vitest tests green, PHPStan 0 errors, vue-tsc green.

### R1 — Rating: product model extensions — done
- Phase 3 (docs/rating-quotation-documents-design.md §1, committed with this slice). Migration `2026_09_20_000001_product_classes_and_coverages`:
  - `product_classes` — global catalogue (D-18): code, `name_en`, `name_bn`, life/non-life, status. Active: motor, fire, marine_cargo, misc; `later` (reserved, refused):
    marine_hull, engineering, health, life. Seeded by the migration and `ProductClassesSeeder` (called by `seedDemoTenant`, `DatabaseSeeder`, `BlankTenantSeeder`, `PartADemoSeeder`).
  - `product_versions` += `class_code` (FK, nullable so Phase 1 versions keep working), `risk_schema` jsonb, `duty_profile` jsonb, `document_set_id`, `allow_short_period`
    (false), `min_premium_minor` bigint (≥ 0), `recognise_at` (`policy` | `cover_note`, default policy — OPEN 3, A-65), `allow_credit_issue` (false — OPEN 4, A-65).
    `rating_plan_id` arrives with rating plans in R2.
  - `coverages` (tenant, forced RLS): version, code, EN/BN names, mandatory, basis (`sum_insured` | `flat` | `per_unit` | `pct_of_base`), `rating_rule_ref`, `limit_rule`,
    `deductible_rule`, sort order; unique per version. The Phase 1 `product_versions.coverages` JSON is untouched (D-19).
- Domain (`Insurance\Product\Domain`): `Risk\RiskSchema` / `RiskField` (readonly): field list `{key, label_en, label_bn, type text|integer|money|date|select|boolean, required,
  options [{value, label_en, label_bn}], min, max, max_length}` validated on write (`RISK_SCHEMA_INVALID` names the first problem); `validate(inputs)` returns the inputs
  normalised in schema order (integers and money as int minor units — never floats, digit strings accepted; booleans; `Y-m-d` dates; absent optional fields null) or throws
  `RiskInputsInvalid` (`RISK_INPUTS_INVALID`) listing every field's problem (REQUIRED, UNKNOWN_FIELD, NOT_INTEGER, BELOW_MIN, ABOVE_MAX, NOT_AN_OPTION, NOT_A_DATE, NOT_BOOLEAN,
  NOT_TEXT, TOO_LONG). Money is never negative. `DutyProfile` `{"exclude": [...]}` (A-66). Enums `RiskFieldType`, `CoverageBasis`, `PremiumRecognition`.
- `ProductCatalogue`: `addVersion` takes the new optional keys (`class_code`, `risk_schema`, `duty_profile`, `document_set_id`, `allow_short_period`, `min_premium_minor`,
  `recognise_at`, `allow_credit_issue`, `coverage_definitions`) — existing calls unchanged; `configureRating(version, fields)` and `addCoverage(version, coverage)` while no
  policy uses the version (`PRODUCT_VERSION_IN_USE`). Refusals: `PRODUCT_CLASS_UNKNOWN`, `PRODUCT_CLASS_NOT_AVAILABLE`, `PRODUCT_CLASS_MISMATCH` (life class on a non-life
  product), `DUTY_PROFILE_INVALID`, `MIN_PREMIUM_INVALID`, `RECOGNISE_AT_INVALID`, `COVERAGE_INVALID`, `COVERAGE_DUPLICATE`. Audited (`product_version.created` now carries the
  rating fields, `product_version.rating_configured`, `product_version.coverage_added`), permission `product.manage`.
- Demo data: `Database\Seeders\DemoRatingCatalogue` holds the risk schema and coverages per class (motor: vehicle_type private/commercial/motorcycle, registration_no, chassis_no,
  engine_cc, seats, year_of_manufacture, driver_age, sum_insured, ncb_years; own damage, third party, passenger liability. Fire: occupancy, construction_class, address,
  sum_insured. Marine cargo: voyage_type, conveyance, commodity, from/to, sum_insured. Misc: description, sum_insured). `DemoBusinessSeeder` and `PartADemoSeeder` give MOTOR,
  FIRE and MARINE their class, schema and coverages; `DistributionDemoSeeder` gives FIRE-SME the fire class (the life product stays without a class: life is LATER).
  The schemas are illustrative (select options, integer bounds such as engine 50–10,000 cc, seats 1–60, driver age 18–99): **verify** with underwriting.
- Test setup changes (additive): `seedDemoTenant` runs `ProductClassesSeeder`; `SchemaInvariantsTest` lists `product_classes` as a global table (D-18);
  `TenantIsolationEveryTableTest` adds a coverage; `DistributionDemoSeederTest` also checks FIRE-SME's class.
- Tests: `tests/Unit/Insurance/RiskSchemaTest.php` (18), `tests/Feature/Insurance/ProductRatingTermsTest.php` (6), `tests/Feature/Insurance/RatingDemoSeedersTest.php` (1).
- Result: 1,127 Pest tests green, PHPStan 0 errors, Vitest and vue-tsc green.

### R2 — Rating: plans, rate tables, steps, duties and the rating evaluator — done
- Module `App\Modules\Insurance\Rating` (D-20). Migration `2026_09_20_000002_create_rating_plans` (all tenant tables, forced RLS):
  - `rating_plans` (code, name, class, version, currency, effective dates, status `draft`|`approved`|`active`|`retired`, source `idra_tariff`|`company`, `verify`, notes,
    copied_from, created/approved/activated/retired by and at). CHECKs: range, `approved_by <> created_by`, approved plans have an approver.
    **INVARIANT** one active plan per class per date: `rating_plans_one_active_per_class` EXCLUDE USING gist (tenant, class, daterange) WHERE status = 'active'.
  - `rate_tables` (code, name, dimensions jsonb, value_type `rate_pm`|`rate_pct`|`flat`|`band`), `rate_table_rows` (keys jsonb, `value_minor` bigint | `value_bp` int,
    `band_from`/`band_to` bigint with `band_label`, optional effective dates, position), `rating_steps` (order_no, code, kind, expression, condition, applies_to, EN/BN labels).
  - Immutability triggers: an approved, active or retired plan cannot be edited or deleted, nor its tables, rows or steps (`RATING_PLAN_IMMUTABLE`); status only moves forward
    (`RATING_PLAN_TRANSITION`); from active only retiring and shortening `effective_to` (superseding) are allowed.
  - `duties` (code vat|stamp|levy, basis `pct_of_premium` (rate_bp) | `flat_per_policy` (amount_minor) | `per_sum_insured_band` (bands jsonb), class_codes jsonb, effective dates,
    EN/BN labels, `verify` default true, `source`); a CHECK ties the value columns to the basis.
  - `product_versions.rating_plan_id` (FK): a version may pin a plan of its own class (`ProductCatalogue` refuses `RATING_PLAN_UNKNOWN`, `RATING_PLAN_CLASS_MISMATCH`).
  - Permissions `rating.manage_plans`, `rating.approve_plans` (A-69), inserted by the migration, added to existing tenants' finance_manager and cfo roles, with the SoD object rule.
- Units (D-20): `rate_pct` rows hold basis points of a percent (1500 = 15.00 %); `rate_pm` rows hold hundredths of a per mille (250 = 2.50 ‰); flat rows minor units; bands are
  half-open [from, to).
- `RatingPlanService` (D-21): `createDraft`, `createFromDefinition`, `updateDraft`, `addTable`, `addRows`, `removeTable`, `addStep` (expression checked on entry), `removeStep`,
  `deleteDraft` (all draft-only, `RATING_PLAN_NOT_DRAFT`), `newVersion` (copy of any plan as a draft, version + 1), `approve` (checker ≠ drafter: `RATING_PLAN_SAME_APPROVER`,
  SoD on the plan's audit trail, and the plan must be valid — `RATING_PLAN_INVALID` lists every problem), `activate(plan, supersede: false)` (`RATING_PLAN_NOT_APPROVED`,
  `RATING_PLAN_OVERLAP`, exclusion race mapped to the same reason), `retire`. Every change audited on `rating_plan`. `RatingPlanRepository`: `activeFor(class, date)`,
  `definition(plan)`. `DutyBook`: `record` (`DUTY_OVERLAP` for the same duty and class on overlapping dates), `end`, `inForce(class, date)`.
- Pure domain: `RatingPlanDefinition` / `RateTableDefinition` / `RateRow` / `RatingStepDefinition` / `DutyDefinition` (from/to arrays; `problems()` checks at least one base
  step, phase order — premium steps, then rounding, then duties/taxes — unique codes, row keys = dimensions, value columns by type, overlapping rows or bands, coverage steps
  naming a coverage, and every table an expression names exists with the right kind).
- Evaluator (`RatingExpressions`, `RatingFunctions`, `RatingScope`, `RatingContext`): its own Symfony ExpressionLanguage instance (posting rules untouched), evaluate-only.
  Variables `risk`, `coverage`, `sum_insured`, `running`, `steps`. Functions `lookup('table', keys…)`, `band(value, 'table')`, `band_value(value, 'table')`, `pct(base, bp)`,
  `per_mille(base, rate)`, `div(a, b)`, `round_to` / `ceil_to` / `floor_to(value, unit)`, `duty('code')`, `min`, `max`. Refused when parsed: `/`, `%`, `**`, `~`, bit and regex
  operators, decimal constants, method calls and indexing, other functions (e.g. `constant`), unknown variables, a table not named in quotes. Amounts must be integers
  (`RATING_EXPRESSION_NOT_INTEGER`, catches overflow into float), conditions booleans. `RatingMath` does half-even division and refuses overflow. Failures are `RatingFailed`
  with reasons such as `RATE_NOT_FOUND` (names table and keys), `RATE_AMBIGUOUS`, `BAND_NOT_FOUND`, `RATE_TABLE_UNKNOWN`, `RATE_TABLE_TYPE`, `RISK_INPUT_MISSING`.
- Arch test: no `floatval`, `round`, `number_format`, `fdiv`, `ceil`, `floor` in `App\Modules\Insurance\Rating`.
- Shared file changes: `PermissionsSeeder` (2 permissions, SOD row 7), `RoleTemplates` (A-69), `tests/Pest.php` (`activeRatingPlan` helper), `TenantIsolationEveryTableTest`
  (a duty and an active plan with a table, row and step), `DependencyTest`, `ProductCatalogue` (`rating_plan_id`).
- Tests: `tests/Unit/Insurance/RatingExpressionsTest.php` (22), `tests/Feature/Insurance/RatingPlanLifecycleTest.php` (6), `tests/Feature/Insurance/DutyBookTest.php` (2).

- Result: 1,158 Pest tests green, PHPStan 0 errors, Vitest (269) and vue-tsc green.

### R3 — Rating: RatingEngine::rate() — done
- `Insurance\Rating\Application\RatingEngine::rate(ProductVersion|id, riskInputs, CarbonImmutable asOf, chosenCoverages = []): RatingResult` — reads only (no writes, no audit,
  no permission check: the caller that quotes checks its own). Plan: the version's `rating_plan_id` when set (must be active — `RATING_PLAN_NOT_ACTIVE` — and in force —
  `RATING_PLAN_NOT_EFFECTIVE`), else the active plan for the version's class on the date (`RATING_PLAN_NOT_FOUND`); a version without a class is `PRODUCT_NOT_RATED`.
- Pure `Rating\Domain\RatingCalculator::calculate(RatingRequest)`: validates the inputs against the risk schema (`RISK_INPUTS_INVALID`), rates mandatory coverages plus the
  chosen ones (`COVERAGE_UNKNOWN`), runs the steps in order (base/coverage/loading add, discount subtracts, minimum floors, rounding replaces; a step with `applies_to`
  runs only for a rated coverage, a false condition skips it; negative step amounts or premium refused), applies the product minimum (A-67), then duties: duty/tax steps via
  `duty('code')`, and every other duty in force for the class that the duty profile does not exclude, in the order stamp, levy, vat, on the net premium (A-66, A-68).
- `RatingResult` (readonly): currency, as_of, product_version_id, plan {id, code, version, class_code}, inputs_hash (sha256 of plan code/version/class, date, normalised inputs
  and coverages — no database ids, so it is stable across databases), risk_inputs, coverages, base premium, coverage premiums, loadings, discounts, minimum and rounding
  adjustments, net premium, duties, duties total, gross premium, explanation lines {step_code, kind, label_en, label_bn, amount_minor, running_total_minor}, `verify` (a duty
  used is a placeholder). `toArray()` / `fromArray()` round-trip exactly (R4/R7 freeze it as JSON).
- `ProductCatalogue`: coverage definitions keep their listed order (`sort_order` defaults to the position).
- Golden fixtures `tests/Fixtures/rating/` (expected amounts worked by hand, not produced by the code), each rated by the pure calculator and by RatingEngine through the
  database (plan drafted, approved, activated; duties recorded; product version created):
  - `01_motor_comprehensive`: own damage per mille by vehicle type × cc band, third-party, passenger liability per seat, young-driver loading, (skipped) old-vehicle
    loading, no-claim bonus band, minimum by vehicle type, rounding, stamp duty and VAT steps — gross 30,918.30;
  - `02_fire_per_mille_by_occupancy`: rate per mille by occupancy, construction loading (skipped), minimum, rounding (+0.22), stamp duty by sum insured band and VAT applied
    automatically — gross 57,006.40;
  - `03_marine_cargo_voyage`: voyage rate per mille by voyage type × conveyance, minimum, rounding (−0.15), stamp and VAT steps — gross 15,220.20.
- Property tests (fixed seed 20260914, no library): 200 random motor quotes rate identically twice (objects, arrays and the fromArray round trip; gross = net + duties = last
  running total); 150 random risks × 6 increasing sums insured each for motor and fire never lower net or gross.
- Demo data: `Database\Seeders\DemoRatingPlans` seeds duties and active plans MOTOR-TARIFF, FIRE-TARIFF, MARINE-CARGO-TARIFF, MISC-TARIFF (drafted by the finance manager,
  approved and activated by the CFO) in `DemoBusinessSeeder` (demo tenant; `DistributionDemoSeeder`'s FIRE-SME is rated by the same fire plan) and `PartADemoSeeder`
  (nonlife tenant). Products are linked by class (no `rating_plan_id` pin). `DemoTenantSeeder` is unchanged; tests create plans with `activeRatingPlan`.
- **Placeholder values to verify (consolidated, R1–R3)** — every one is illustrative, not an IDRA tariff or NBR rule. In the data: plans `verify = true` with a notes line,
  duties `verify = true` and `source = placeholder_verify`, golden fixtures carry a `note`.
  | Where | Value (minor units unless stated) |
  |---|---|
  | Duty VAT (motor, fire, marine_cargo, misc) | 15 % of net premium (1500 bp), on net premium only (A-68) — OPEN 1 |
  | Duty stamp, motor | flat 5,000 (50.00) per policy — OPEN 1 |
  | Duty stamp, fire | by sum insured: < 10,000,000.00 → 200.00; < 50,000,000.00 → 500.00; above → 1,000.00 — OPEN 1 |
  | Duty stamp, marine cargo and misc | flat 10,000 (100.00) per policy — OPEN 1 |
  | Motor own damage rate (‰) | private 20.00 / 22.50 / 25.00; commercial 27.50 / 30.00 / 32.50; motorcycle 15.00 / 17.50 / 20.00 for cc ≤ 1300 / 1301–1800 / > 1800 |
  | Motor cc bands | [0, 1301), [1301, 1801), [1801, ∞) |
  | Motor third-party liability | private 2,500.00; commercial 4,000.00; motorcycle 900.00 |
  | Motor passenger liability | 45.00 per seat (all vehicle types) |
  | Motor loadings | driver age < 25: +10 %; year of manufacture ≤ 2015: +15 % |
  | Motor NCB scale (OPEN 5) | 0 years 0 %, 1 year 10 %, 2 years 20 %, 3+ years 30 % |
  | Motor minimum premium | private 5,000.00; commercial 7,500.00; motorcycle 1,500.00 |
  | Fire rate (‰) by occupancy | dwelling 0.80; shop 1.50; warehouse 2.00; factory 2.50 |
  | Fire loading | construction class 3: +25 % |
  | Fire minimum premium | 1,000.00 |
  | Marine cargo rate (‰) | import sea 1.50 / air 1.00 / road 1.20; export 1.20 / 0.80 / 1.00; inland 1.80 / 1.20 / 2.00 |
  | Marine cargo minimum premium | 500.00 |
  | Misc rate and minimum | 3.00 ‰ of sum insured; minimum 500.00 |
  | Rounding (all plans) | to the nearest 1.00, half-even |
  | Risk schemas (R1) | select options (vehicle types, occupancies, construction classes, voyage types, conveyances) and bounds: engine 50–10,000 cc, seats 1–60, year 1950–2100, driver age 18–99, claim-free years 0–50 |
  | Product flags (R1) | `recognise_at = policy` (OPEN 3), `allow_credit_issue = false` (OPEN 4) — defaults, not placeholders, but to confirm |
- Not done, and why: no screens or HTTP endpoints (R10); quotations, proposals, cover notes and policies do not call the engine yet (R4–R7); endorsement re-rating with the
  original plan version needs a "rate with this plan" entry point, left to R7 (the pinned `rating_plan_id` path covers a fixed plan today); `document_set_id` is stored
  only (documents are R8); duties need no second approval (A-69).
- Tests: `tests/Feature/Insurance/RatingGoldenTest.php` (3), `tests/Unit/Insurance/RatingPropertiesTest.php` (3), `tests/Feature/Insurance/RatingEngineTest.php` (4);
  `RatingDemoSeedersTest` extended (active plans, verify flags, the demo motor product rates the golden quote).

- Result: 1,168 Pest tests green, PHPStan 0 errors, Vitest (269) and vue-tsc green.

### R10a — Rating: tariff editor with draft, approve, activate and diff view — done
- Phase 3 design §6 "Tariff editor (plan → tables grid with effective dates, draft/approve/activate, diff view)" on the R2 backend (D-36). No migration, no new permission.
- **Tariffs** (`/rating/plans`, sidebar secondary item gated by `rating.manage_plans` | `rating.approve_plans`, A-114): QueueView of every plan version — code, name, class,
  version, from/until, status, source, verify flag — with list filters by class, status, source and verify; "New plan" (draft) and, in the inspector, "New version" (copy as
  draft version + 1, optional new dates).
- **Plan page** (`/rating/plans/{id}`), header strip with status, facts and lifecycle actions, "Placeholder values — verify before use" banner when flagged, tabs:
  - Overview: what stops approval (`RatingPlanDefinition::problems`), the active plan the dates overlap (approved plans), lifecycle (drafted/approved/activated/retired by
    whom and when, copied from), header form while draft (name, dates, source, verify, notes).
  - Tables: each rate table as a grid (`components/rating/RateTableGrid.vue`) — dimension columns (band tables: from, below, label), the value in human units (‰ for `rate_pm`,
    % for `rate_pct` and band rates, BDT for flat, D-20) right-aligned tabular, per-row dates; while draft every cell is an input (Tab along the row, Enter saves, "Add row"
    focuses the new row, remove per row, discard), unreadable cells named before sending; add table (code, name, dimensions, value type) and remove table with confirmation.
    A table is saved whole (A-110). Units convert on the digits in `resources/js/lib/rating.ts` (A-111).
  - Steps: ordered list (order, code, kind, expression, condition, coverage, EN/BN label); add, edit and remove while draft; expression and condition errors from the server's
    evaluator shown on the field.
  - Duties: the class's duties in force today or later with basis, value, classes, dates and verify flag (A-113); plan managers record a duty (percent, flat, or sum insured
    bands; verify defaults on) and end one from a date through DutyBook.
  - Diff: compare with another version of the same code, by default the active / copied-from / previous version (A-112): plan field and date changes, per table rows added,
    removed and changed (before → after in units), tables added/removed, step changes (`components/rating/PlanDiff.vue`).
  - Timeline and Audit: ObjectHistory over `rating_plan` (deferred).
- Lifecycle with `confirmAction` dialogs: Approve (disabled with "You drafted or edited this plan, so someone else approves it." for the drafter or an editor, and while
  problems remain), Activate (when an active plan overlaps, the page names it and offers "Activate" — refused with the invariant message — and "Activate and supersede"), Retire,
  Delete draft. Refusals (`RATING_PLAN_NOT_DRAFT`, `RATING_PLAN_SAME_APPROVER`, `SOD_CONFLICT`, `RATING_PLAN_OVERLAP`, `DUTY_OVERLAP`, `PERMISSION_DENIED`, …) come back as the
  form error with the reason.
- Backend: `Rating\Http\Controllers\TariffsPageController` and `DutiesPageController` (routes under `/rating`); `RatingPlanService::replaceRows` (`rating_plan.rows_replaced`) and
  `updateStep` (`rating_plan.step_updated`, same checks as addStep); read-only `RatingPlanDirectory` (list, header with names, versions, default comparison, editors, overlapping
  active plans, duties); pure `RatingPlanDiff::compare(from, to)`.
- Placeholder values: none added (duty and plan placeholders stay as listed in R3; the page shows their verify flags).
- Shared files: `routes/web.php` (rating group), `resources/js/lib/navigation.ts` (Tariffs), `docs/PROGRESS.md`, `docs/DECISIONS.md`.
- Not done, and why: help panel text for tariffs (the "How this works" modules are written in English and Bangla per module; left for a docs pass); screenshots (workers
  do not start servers); no importing of IDRA circulars (LATER in the design).
- Tests: `tests/Feature/Insurance/TariffEditorTest.php` (9: queue and page props and permissions for manager, approver and neither; draft editing of header, rows with dates,
  tables and steps with field errors; HTTP edits refused when not draft; approve refused for the drafter and an editor; activate invariant message and supersede; retire;
  new version copy and diff against the active version; duties recorded and ended; draft deletion), `tests/Unit/Insurance/RatingPlanDiffTest.php` (4),
  `resources/js/tests/rating.test.ts` (9: "2.25‰" ↔ 225, "10%" ↔ 1000, BDT ↔ minor units, refusals, exact integers, grid rows).

- Result: 1,213 Pest tests green, PHPStan 0 errors, Vitest (292) and vue-tsc green.

### R8 — Documents: templates, generated PDFs, Bangla fonts — done
- Phase 3 design §3 (market cross-check G2, Part A step 3 "receipt for the customer"). Migration `2026_09_26_000001_create_document_templates_and_generated_documents`
  (both tenant tables, forced RLS):
  - `document_templates`: code (`quotation`, `cover_note`, `policy_schedule`, `endorsement`, `receipt`, `renewal_notice`, `claim_ack`, `discharge_voucher`), `product_class`
    (null = every class), version, engine `blade_pdf`, locale `en`|`bn`, body, letterhead (null = the entity name), status `draft`|`active`|`retired`, created_by (null =
    seeded), activated_by/at, retired_at. **INVARIANT** one active version per (code, class, locale): partial unique index `document_templates_one_active`. Trigger
    `DOCUMENT_TEMPLATE_IMMUTABLE`: code, class, locale, version and author never change; active and retired bodies and letterheads never change; only draft → active → retired
    (`DOCUMENT_TEMPLATE_TRANSITION`); only drafts can be deleted.
  - `generated_documents`: template id, code and version, locale, object type and id, business number, version per (object type, object id, code), `stored_document_id`
    → `stored_documents`, `sha256` (of the PDF, equal to the stored document's), `content_sha256` (of the rendered letterhead and body, printed as the reference), size,
    rendered_at/by. Append-only by trigger (`GENERATED_DOCUMENT_APPEND_ONLY`).
  - Permissions `document.generate`, `document.manage_templates` (A-101), added to existing tenants' branch officer / branch manager and tenant admin roles.
- Platform, no business dependency (arch tests unchanged):
  - `Documents\Templates\DocumentTemplates`: `all`, `find`, `versions`, `active(code, class, locale)` (class template, else every class), `create`, `saveDraft` (a draft in place;
    an active or retired version → the next draft version; `DOCUMENT_TEMPLATE_DRAFT_EXISTS`), `activate` (retires the version in force; `DOCUMENT_TEMPLATE_NOT_DRAFT`,
    `DOCUMENT_TEMPLATE_ACTIVATION_CONFLICT`), `preview(code, locale, body, letterhead)` (demo data, stores nothing), `seedCurrentTenant()` (active v1 of the 16 defaults, rerun-safe).
    Audited on `document_template`: `created`, `draft_saved`, `activated`, `retired` (body and letterhead SHA-256 before/after). Also `DOCUMENT_TEMPLATE_CLASS_UNKNOWN`,
    `DOCUMENT_TEMPLATE_CODE_UNKNOWN`, `DOCUMENT_TEMPLATE_LOCALE_UNKNOWN`, `DOCUMENT_TEMPLATE_EMPTY`, `DOCUMENT_TEMPLATE_TOO_LONG` (200,000 / 50,000 characters). A-106.
  - `TemplateBodyGuard` (A-100): Blade compiles output tags and directive arguments to PHP, so a body is checked against a whitelist before it is compiled; refusals
    (`DOCUMENT_TEMPLATE_UNSAFE`) say what is not allowed, e.g. "@php is not allowed in a template. Use @if, @elseif, @else, @endif, @foreach and @endforeach only", "Only
    variables can be printed: {{ system('id') }} is not allowed", "$app is not a variable of this template". Directives are found the way Blade finds them (after comments, with
    output tags still in place), so `{{ $x ?? '@php(…)' }}` and `a{{ $x }}@php(…)` are caught. A body must also render with the demo variables (`DOCUMENT_TEMPLATE_RENDER_FAILED`).
  - `DocumentVariables`: one bag for every template — `company {name}`, `document {title, number, date}`, `currency`, `parties [{role, name}]`, `product {code, name, class}`,
    `period {from, to}`, `details [{label, value}]`, `money [{label, amount}]`, `total {label, amount}`, `special_terms [text]`, `installments [{no, due_date, amount}]`,
    `allocations [{reference, description, amount}]`, `rating [{label, amount}]`; values are text (money and dates formatted by `DocumentValues`, A-103), always escaped.
    `documentation(code)` is the editor's variables list per document; `demo(code, locale)` the preview data (illustrative, A-107).
  - `Rendering\TemplateRenderer` (Blade string → HTML with only the bag in scope; A4 layout, letterhead, footer "Generated <tenant time> · Reference <12 hex>", A-105) and
    `Rendering\PdfRenderer` → `ChromePdfRenderer` (D-34: the Chrome binary headless through Symfony Process; `erp.documents.chrome_binary` / `ERP_CHROME_BINARY`, default
    `/usr/bin/google-chrome`; `erp.documents.render_timeout_seconds`; `DOCUMENT_PDF_FAILED`). IBM Plex Sans and Noto Sans Bengali (400/600, OFL) are self-hosted in
    `resources/fonts/documents` and embedded as data URIs; the PDF embeds a NotoSansBengali subset.
  - `Generation\DocumentGenerator::generate(templateCode, objectType, objectId, actor, ?locale)` and `history(objectType, objectId)` (D-35): provider → subject (page object,
    number, class, scope) → `document.generate` in that scope → active template (A-102, `DOCUMENT_TEMPLATE_MISSING`) → HTML → PDF → one transaction: advisory lock, next
    version, `DocumentStore::storeGenerated` (new, narrowly scoped: same disk, hash, limits and append-only row as `attach`, audited `document.generated` with template code and
    version, version, locale, generated-for object and number instead of `document.attached`), `generated_documents` row. Also `DOCUMENT_PROVIDER_MISSING`,
    `DOCUMENT_OBJECT_UNKNOWN`, `DOCUMENT_OBJECT_NOT_READY`.
  - `Generation\DocumentDataProvider` (contract) and `DocumentDataProviders` (services tagged `DocumentDataProvider::class`, bound in `PlatformServiceProvider`).
- Insurance providers (tagged in `InsuranceServiceProvider`, A-104): `Policy\Application\Documents\PolicyScheduleDocumentData` (`policy`: parties with payers and agent,
  product and class, period, status/issued/version/installments, net premium, VAT and duties — or the rating result's named duties when there is one — gross, installments,
  special terms from endorsement reasons and the rating result's manual steps and `special_terms`, the rating breakdown), `EndorsementDocumentData` (`policy_transaction` of type
  endorsement → PDF on the policy, number `<policy>/E<n>`), `Collections\Application\Documents\ReceiptDocumentData` (`receipt`: payer, received on, method, cheque, reference,
  standing allocations by policy and installment, suspense, amount received). `PolicyDocumentFacts` reads `policies.rating_result` only when R7 has added it.
- **Adding a provider after merge (quotation R4–R6, cover note R6, renewal notice R9) is one class.** Implement `DocumentDataProvider` in the owning context's Application layer —
  `objectType()` (`quotation`, `cover_note`, `policy`), `templateCodes()` (`[DocumentTemplateCode::Quotation]` …), `subject($id, $code)` returning
  `new DocumentSubject(<page object type>, <page object id>, <number>, <class code>, AuthorizationScope::branch($entity, $branch))` (throw `DOCUMENT_OBJECT_NOT_READY` when the
  object cannot have the document yet) and `variables($id, $code, $locale)` filling the bag above (the editor lists what each variable holds per code; `DocumentVariables::demo`
  shows the shape) — and add the class to the `tag([...], DocumentDataProvider::class)` call. Templates, preview, versions, storage, audit and permissions already work. For a
  "Generate …" button, add the action to that page's panel in `App\Http\Documents\GeneratedDocumentsController` (like `forPolicy`/`forReceipt`), pass `documentGeneration` from
  `ObjectPageController` and add a POST route that calls `DocumentGenerator::generate`.
- HTTP and screens:
  - Documents → Templates (`/documents/templates`, sidebar "Templates", secondary, `document.manage_templates`; `Platform\Documents\Http\DocumentTemplatesPageController`): queue by
    document, class, language, version, status, changed (QueueView with inspector; "New template" drawer: document, class, language → a draft copied from the template in use).
    Editor (`/documents/templates/{id}`): letterhead and body, "Save draft" (a version in use saves as a new draft), "Activate" (confirmation; disabled while unsaved), live
    preview in a sandboxed `iframe srcdoc` refreshed 500 ms after typing (`POST /documents/templates/preview`, JSON, stores nothing, the refusal shown above the frame),
    "Preview saved version as PDF" (`GET …/{id}/preview?format=pdf`, real Chrome; the HTML form has a strict CSP), variables list and versions list.
  - Policy and receipt pages, Documents tab: "Printed documents" — language (English / বাংলা), "Generate schedule" (issued policies), "Generate endorsement n (date)" per
    endorsement, "Generate receipt" (not for a bounced cheque); the versions generated (document and number, version, language, generated by, date, reference, download of the
    stored PDF). `POST /policies/{id}/generated-documents` (template_code, object_id for an endorsement, locale) and `POST /receipts/{id}/generated-documents` (locale) in the
    composition controller `App\Http\Documents\GeneratedDocumentsController` (page area + the generator's permission check); deferred prop `documentGeneration` (group
    `history`) from `ObjectPageController`. The PDFs are also in the tab's document list. Timeline: "Policy schedule version 2 generated by Rafiq Islam". The policy and
    collections module controllers are untouched.
  - Components: `components/object/GeneratedDocuments.vue`; `DocumentList.vue` (`generation`), `ObjectPage.vue` (`documentGeneration`); pages `documents/templates/Index.vue`, `Edit.vue`.
- Seeding: `DocumentTemplates::seedCurrentTenant()` from `BlankTenantSeeder`, `DatabaseSeeder` (demo tenant, after the unchanged `DemoTenantSeeder`) and `PartADemoSeeder`.
  Tests use `seedDocumentTemplates($tenantId)` and `fakePdfRenderer()` (tests/Pest.php).
- CI: `.github/workflows/ci.yml` sets `ERP_CHROME_BINARY=/usr/bin/google-chrome` on the backend job; `.env.example` documents it.
- Shared files edited: `PermissionsSeeder`, `RoleTemplates`, `PlatformServiceProvider`, `InsuranceServiceProvider`, `DocumentStore` (attach split into a private `write`, behaviour
  unchanged, plus `storeGenerated`), `ObjectPageController`, `ObjectHistory` (`document.generated` sentence), `routes/web.php`, `config/erp.php`, `lib/navigation.ts`,
  `DocumentList.vue`, `ObjectPage.vue`, `components/object/types.ts`, `policies/Show.vue`, `receipts/Show.vue`, `tests/Pest.php`, `TenantIsolationEveryTableTest` (seeds templates and
  generates a schedule with the fake renderer), `documents.test.ts` (+1), `BlankTenantSeeder`, `DatabaseSeeder`, `PartADemoSeeder`, `ci.yml`, `.env.example`.
- Test changes (the requirement changed; equally strict exact lists): `PermissionsTest` and `RoleAdministrationTest` now expect `document.manage_templates` on the tenant admin
  template and `document.generate` on the branch officer template; `UserAdministrationTest` expects the auditor refusal to name `document.generate` (the branch officer role's
  first write permission, was `party.manage`).
- **Placeholder values to verify (R8):**
  | Where | Value |
  |---|---|
  | Default template wording (A-107) | closing sentences, e.g. "This schedule forms part of the policy and must be read together with the policy wording.", "Payments by cheque or other instruments are subject to realisation.", "I accept the amount above in full and final settlement of this claim."; signature lines ("For <company> / Authorised signatory", claimant and witness); section titles |
  | Bangla wording (A-107) | every Bangla label and sentence of the defaults (e.g. পলিসি তফসিল, বিশেষ শর্তাবলি, প্রাপ্তি রসিদ, দাবি নিষ্পত্তি ভাউচার) — to be checked by a Bangla-speaking underwriter |
  | Endorsement number (A-104) | `<policy number>/E<n>` |
  | Preview demo data (A-107) | Padma General Insurance PLC, Rahima Akter, POL-HO-2026-000123 and the amounts — illustrative only |
  | Numbers on Bangla documents (A-103) | Latin digits and English month abbreviations |
- Not done, and why: providers for quotation, cover note and renewal notice (their objects come with R4–R6 and R9; templates, variables and preview are ready); claim
  acknowledgement and discharge voucher providers (outside this slice; templates ready); bulk print/email/SMS from queues (design §3, with the notification gateway, LATER); a
  template diff view; deleting drafts from the screen; syntax highlighting in the editor (plain text areas; the theme test forbids monospace utility classes).
- Tests: `tests/Feature/Documents/DocumentTemplatesTest.php` (30: seeding, versioning and one active, database immutability, 22 unsafe bodies, the safe subset, escaping and
  render failures, preview of all 8 defaults in en and bn, permissions and screens), `DocumentGenerationTest.php` (8: schedule row, stored document hash = bytes, audit;
  regeneration keeps v1 with its template version; append-only triggers; endorsement and receipt; refusals; class template; branch permission; pages, props and download;
  tenant isolation under the runtime role), `ChromePdfRendererTest.php` (2: real Chromium Bangla schedule → `%PDF-`, > 8 KB, NotoSansBengali embedded; a missing binary is
  refused), `resources/js/tests/documents.test.ts` (+1).


- Result: 1,240 Pest tests green, PHPStan 0 errors, Vitest (282) and vue-tsc green.

### R4 — Quotation workbench — done
- Phase 3 design §2 step 1 and §6 "Quote workbench". Module `App\Modules\Insurance\Quotation` (D-30). Migration `2026_09_24_000001_create_quotations`:
  - `quotations` (tenant, forced RLS): entity, branch, number (branch-coded, unique when set), product and product version, class, customer (null on a draft), producer,
    proposed cover start `inception`, `risk_inputs`, chosen optional `coverages`, `risk_keys` (normalised duplicate-risk keys, A-88, used by R5), `rating_result`
    (`RatingResult::toArray()`), plan code and version, currency, sum insured / net / duties / gross premium columns for queues, `valid_until`, status
    `draft|issued|expired|converted|declined`, producer eligibility (flag, reason code, note), decline reason/by/at, issued/expired/converted by and at, created/updated by.
  - CHECKs: status list; issued/expired/converted rows have number, rating, validity and customer; declined has a reason; amounts not negative.
  - **INVARIANT** trigger `protect_quotation`: only draft → issued | declined and issued → expired | declined | converted (`QUOTATION_TRANSITION`); once out of draft the
    terms, rating, premiums, dates and number never change and the row is never deleted (`QUOTATION_FROZEN`).
  - Permission `quotation.create` (A-83), granted to existing tenants' branch officer and branch manager roles.
- `QuotationService` (permission on the quotation's branch):
  - `rate(QuotationTerms, actor)` — `RatingEngine::rate` on the cover start (A-84); nothing written.
  - `saveDraft(terms, ?id, actor)` — creates or changes a draft, keeps only the version's risk fields, re-rates when the inputs are complete (otherwise no rating),
    records producer eligibility; refusals `BRANCH_UNKNOWN`, `PRODUCT_UNKNOWN`, `PRODUCT_NOT_RATED`, `PRODUCT_VERSION_NOT_EFFECTIVE`, `CUSTOMER_UNKNOWN`, `PRODUCER_UNKNOWN`,
    `QUOTATION_NOT_DRAFT`; audited `quotation.created` / `quotation.saved`.
  - `issue(id, on, actor)` — customer required (`QUOTATION_CUSTOMER_REQUIRED`, A-82), cover start not before the issue day (`QUOTATION_INCEPTION_IN_PAST`), re-rated (risk
    problems and rating failures refuse), number reserved before and used inside the transaction (`DocumentNumberer`, doc type `quotation`, prefix `QUO`, format
    `{prefix}-{branch}-{fy}-{seq}` → `QUO-HO-2026-000001`), rating frozen, valid for `erp.quotations.valid_days` (15) days including the issue day (A-80); audited.
  - `decline(id, reason, actor)` (draft or issued, `REASON_REQUIRED`, `QUOTATION_NOT_OPEN`), `expireDue(today)` (system actor, audited `quotation.expired`; run by
    `QuotationExpiryJob` nightly at 00:15 per tenant and before the queue and workbench are read), `accept(id, on, actor)` for R5 (inside the proposal transaction:
    issued and within validity → converted; `QUOTATION_EXPIRED`, `QUOTATION_NOT_ISSUED`).
  - `ProducerEligibility::check(producer, product, day)` wraps `LicenceRegistry::assertMayWriteNewBusiness` and never throws (A-81).
- HTTP (`QuotationPageController`): `GET /quotations` (queue), `GET /quotations/create`, `GET /quotations/{id}` (workbench), `POST /quotations/rate` (JSON: `{result}` or 422
  `{reason, message, errors: {field: code}}`), `POST /quotations` and `PUT /quotations/{id}` (save draft; `intent=issue` saves then issues — a refused issue keeps the draft
  and returns to it with the reason), `POST /quotations/{id}/issue`, `POST /quotations/{id}/decline`. Screens open for `quotation.create` or `policy.create` held in any scope
  (branch-scoped officers), actions check the quotation's branch.
- UI: sidebar **Quotes** (primary, above Policies, `quotation.create | policy.create`). `pages/quotations/Index.vue` (QueueView: number, customer, product, producer, sum insured,
  gross, valid until, status; inspector with eligibility). `pages/quotations/Workbench.vue`: branch, cover start, product, customer lookup (Ctrl+N creates), producer lookup,
  the risk form generated from the version's risk schema (text / integer / money / date / select / boolean, required marks, bounds as hints, errors per field from the browser
  and the server), optional coverages; right rail with the live breakdown (debounced 400 ms, stale answers dropped) in English or Bangla (toggle saves the user's `locale`
  preference), net, duties, gross, tariff code and version, the placeholder-duty warning; Save draft / Issue quotation / Decline (drawer with reason).
  Pure logic in `resources/js/lib/riskForm.ts` (form fields, bounds hints, typed values → risk inputs with money as minor-unit digit strings, local checks and
  problem wording, version in force, breakdown lines, rating key).
- Shared file changes: `config/erp.php` (`numbering.formats` quotation / proposal / cover_note, `quotations.valid_days`, `underwriting.duplicate_keys`), `RoleTemplates`,
  `PermissionsSeeder`, `routes/web.php`, `routes/console.php`, `lib/navigation.ts`, `tests/Pest.php` (`ratedProductsWorld`: the golden motor and fire plans with duties and
  rated products MOTOR-PVT and FIRE-SME), `TenantIsolationEveryTableTest` (a quotation row).
- Test change: `RoleAdministrationTest` expects `quotation.create` in the Branch Officer template after the refused change (the template gained it; the assertion stays exact).
- Placeholder values to verify: quotation validity 15 days (A-80); duplicate-risk key fields (A-88).
- Not done, and why: no printable quotation (R8 documents); the home queue "Quotes to follow up" still reads Phase 1 policy quotes (shared home queues, left for the lead);
  no quotation for life products (life rating is LATER).
- Tests: `tests/Feature/Quotations/QuotationWorkbenchTest.php` (10: endpoint equals engine and writes nothing; per-field schema problems and rating failures; drafts re-rate and
  keep incomplete inputs unrated; issue numbers, freezes and survives a superseding tariff, trigger refusals; issue needs customer and cover start; expiry by job and on read;
  decline; producer eligibility recorded; permissions and role templates; queue and workbench props), `resources/js/tests/risk-form.test.ts` (6).

- Result: 1,210 Pest tests green, PHPStan 0 errors, Vitest (287) and vue-tsc green.

### R5 — Proposal and underwriting — done
- Phase 3 design §2 step 2 and §5. Module `App\Modules\Insurance\Underwriting`. Migration `2026_09_24_000002_create_proposals_and_underwriting`:
  - `proposals` (tenant, forced RLS): one per quotation (unique), number, entity/branch, product and version, class, customer, producer, cover start, risk inputs and
    `risk_keys`, `rating_result` (the quotation's frozen result, or re-rated with a loading), plan code and version, sum insured / net / duties / gross, status
    `draft|submitted|approved|declined|issued`, KYC (`pending|verified|waived`, document type and number, recorded by/at, waiver reason), `underwriting_status`
    (`auto_approved|referred|approved|declined`), `referral_reasons` (list of {code, detail}), `approval_id`, manual loading bp / reason / by, submitted by/at, decided by/at,
    decision reason, `policy_id` and issued at (R7). CHECKs: statuses, KYC completeness, loading 1–10,000 bp with a reason, decided statuses, issued has a policy.
  - `underwriting_limits` (tenant, forced RLS): role code, class, largest sum insured (minor), effective dates, `verify`; exclusion constraint: no overlap per role and class.
  - `approvals.steps` (jsonb) with `policy_id` now optional (D-31).
  - Permissions `underwriting.decide` (branch manager, finance manager, CFO) and `underwriting.manage_limits` (tenant admin) for existing tenants; SoD object rule
    `quotation.create` ✕ `underwriting.decide` (`SOD8` in new tenants).
- `ProposalService`: `createFromQuotation` (issued quotation within validity → converted, proposal numbered `PRP-<branch>-<fy>-<seq>`, one transaction), `verifyKyc` /
  `waiveKyc` (drafts only; `KYC_INVALID`, `REASON_REQUIRED`, `PROPOSAL_NOT_DRAFT`), `submit` (UnderwritingRules → approved automatically, or referred with every reason and an
  approval request), and the **R7 hooks** `approvedForIssue(proposalId): ApprovedProposal` (parties, product version, cover start, risk, `RatingResult` to freeze, manual
  loading and its reason as `specialTerms`, active cover note — filled in R6; `PROPOSAL_NOT_APPROVED`) and `markIssued(proposalId, policyId, actor)` (inside the policy issue
  transaction; approved → issued).
- `UnderwritingRules::evaluate` (A-85, A-88, A-89, A-92): `SUM_INSURED_ABOVE_LIMIT`, `PRODUCER_INELIGIBLE` (LicenceRegistry on the day), `RISK_FLAG` (configured flags),
  `DUPLICATE_RISK` (the same normalised registration / chassis / address on another issued quotation, a draft, submitted or approved proposal, or an issued proposal whose policy
  is issued or active and not expired — `jsonb_exists_any` on `risk_keys`; R7/R9 hook: policies issued without a proposal must carry risk keys to be found), `KYC_NOT_VERIFIED`.
- `UnderwritingLimits`: `limitFor(user, class, day)`, `rolesCovering(class, sumInsured, day, ?holding)`, `all`, `set` (from today or later, ends the limit in force that day;
  `UNDERWRITING_LIMIT_OVERLAP`, `UNDERWRITING_LIMIT_INVALID`), `end`, `acceptDefaults` (A-90); audited `underwriting_limit.set|ended`.
- `UnderwritingDecisions::decide(proposal, Decision, ?loadingBp, ?reason, decider)`: `underwriting.decide` on the branch, the pending `proposal_referral` approval
  (`PROPOSAL_NOT_REFERRED`), optional manual loading applied first in the same transaction (`RatingEngine::rerate` on the quotation's result, D-32; reason mandatory,
  audited `proposal.loading_applied` with before/after premium), then `ApprovalService::decide`. `ProposalReferralApprovalHandler` completes it: approval needs the decider's
  own limit (`UNDERWRITING_LIMIT_EXCEEDED`, nothing changes) and SoD on the proposal; decline records the reason. The inbox shows referrals ("Underwriting referral PRP-…").
- `ApprovalService::requestWithSteps` and steps read from the approval (engine and inbox), D-31. Config `approvals.object_types.proposal_referral` (so a policy can be set on
  Admin → Approval limits).
- Rating: `ManualLoading` (1–10,000 bp, reason; `MANUAL_LOADING_INVALID`, `LOADING_REASON_REQUIRED`), `RatingRequest::manualLoading`, calculator step after the product
  minimum and before rounding, `RatingEngine::rerate` (D-32).
- HTTP: `POST /quotations/{id}/proposal`, `GET /proposals/{id}`, `POST /proposals/{id}/kyc` (verify | waive), `POST /proposals/{id}/submit`, `POST|GET /proposals/{id}/documents…`
  (DocumentStore, object type `proposal`), `GET /underwriting/referrals`, `POST /underwriting/referrals/{id}/decide` (approve | approve_with_loading with `loading_percent` |
  decline), `GET|POST /admin/underwriting-limits`, `POST /admin/underwriting-limits/{id}/end`.
- UI: sidebar **Referrals** (primary, after Quotes, `underwriting.decide`) and **Underwriting limits** (secondary, `underwriting.manage_limits`). `pages/proposals/Show.vue`
  (ObjectPage: underwriting outcome and reasons, special terms, KYC, risk with EN/BN labels, premium breakdown; Verify identity / Waive KYC / Submit to underwriting; Documents,
  Timeline, Audit tabs), `pages/underwriting/Referrals.vue` (QueueView, waiting first; inspector with reasons, risk, breakdown; Approve / Approve with loading / Decline drawers;
  a note when the user may not decide), `pages/admin/underwriting-limits/Index.vue`, `components/rating/RatingBreakdown.vue` (shared by the workbench, proposal and referrals),
  `lib/proposals.ts`. The quote workbench gains *Customer accepts: make proposal* and *Open proposal* (`can.convert`, `quotation.proposal_id`).
- Demo data: `DemoBusinessSeeder` and `PartADemoSeeder` set the placeholder underwriting limits (verify).
- Shared file changes: `ApprovalService`, `ApprovalInboxQuery`, `RatingEngine`, `RatingCalculator`, `RatingRequest`, `config/erp.php` (`approvals.object_types`, `underwriting.risk_flags`,
  `underwriting.kyc_id_types`), `RoleTemplates`, `PermissionsSeeder` (2 permissions, SoD row 8), `InsuranceServiceProvider`, `routes/web.php`, `lib/navigation.ts`,
  `QuotationPageController` / `Workbench.vue`, both demo seeders, `TenantIsolationEveryTableTest` (a proposal and an underwriting limit).
- Test changes: `PermissionsTest` and `RoleAdministrationTest` expect `underwriting.manage_limits` in the Tenant Admin template (exact lists kept); `QuotationWorkbenchTest` expects the new `can.convert` flag.
- Placeholder values to verify: underwriting limits (A-90), risk flags (A-89), KYC document types (A-92), duplicate keys (A-88).
- Not done, and why: no role is protected from deletion while an underwriting limit names it (approval policies are; small follow-up); no proposals list besides the referral queue and
  the quotation link; sanctions / blacklist check is LATER (design §5).
- Tests: `tests/Feature/Underwriting/ProposalUnderwritingTest.php` (7: auto-approval and R7 hooks; each referral reason incl. normalised duplicates and a declined proposal no longer
  counting; limits by role, class, sum insured and dates; engine decisions with role step, SoD on the proposal, maker ≠ checker, an approval policy, the decider's limit and decline;
  loading re-rated on the original tariff and audited; KYC verify / waive; proposal page, referral queue, decide endpoint, limits screen, documents, role templates),
  `tests/Feature/Insurance/RatingRerateTest.php` (3: identical re-rate after a tariff change; loading worked by hand, gross 34,006.05; refusals).

- Result: 1,220 Pest tests green, PHPStan 0 errors, Vitest (294) and vue-tsc green.

### R6 — Cover note — done
- Phase 3 design §2 step 3 and §6 "Cover notes queue (expiring)". Module `App\Modules\Insurance\CoverNote`. Migration `2026_09_24_000003_create_cover_notes`:
  - `cover_notes` (tenant, forced RLS): entity/branch, proposal (FK), number (unique), class, `valid_from`, `valid_to` (inclusive), status `active|superseded|cancelled|expired`,
    issued by/at, `superseded_by_policy_id` / at, cancelled by/at with reason, expired at. CHECKs: status list, `valid_to >= valid_from`, cancelled has a reason, superseded has a
    policy; partial unique index: one active cover note per proposal.
  - Permissions `cover_note.issue` (branch officer, branch manager) and `cover_note.cancel` (branch manager) for existing tenants (A-94).
- `CoverNoteService`: `issue(proposal, from, to, actor)` — approved proposal only (`PROPOSAL_NOT_APPROVED`), product `recognise_at = cover_note` refused
  (`RECOGNITION_AT_COVER_NOTE_NOT_SUPPORTED`, D-33 gap), from today or later (`COVER_NOTE_BACKDATED`), dates in order (`COVER_NOTE_DATES_INVALID`), at most
  `erp.cover_notes.max_days` of the class including both ends (`COVER_NOTE_TOO_LONG`, message names the last allowed day; A-93), one active per proposal
  (`COVER_NOTE_ALREADY_ACTIVE`); number `CVN-<branch>-<fy>-<seq>` reserved before and used inside the transaction; audited `cover_note.issued`. **No accounting event.**
  `cancel(note, reason, actor)` (`REASON_REQUIRED`, `COVER_NOTE_NOT_ACTIVE`), `expireDue(today)` (system actor; `CoverNoteExpiryJob` nightly at 00:20, and before the queue is
  read), and the **R7 hooks** `supersede(coverNoteId, policyId, actor)` / `supersedeForProposal(proposalId, policyId, actor)` — inside the policy issue transaction
  (LogicException otherwise), active or expired → superseded, audited with permission `policy.issue`. `maxDays(class)`.
- `ProposalService::approvedForIssue` now returns the proposal's active cover note id (`ApprovedProposal::activeCoverNoteId`); R7 should call
  `ProposalService::markIssued` and `CoverNoteService::supersedeForProposal` in the policy issue transaction.
- HTTP: `POST /proposals/{id}/cover-notes` (issue, back to the proposal), `GET /cover-notes?within=N` (queue; active notes ending within N days), `POST /cover-notes/{id}/cancel`.
- UI: sidebar **Cover notes** (primary, after Referrals; `cover_note.issue | cover_note.cancel | quotation.create`). `pages/coverNotes/Index.vue` (QueueView sorted active first
  by last day; days left; All / Ending within 7 days toggle — `erp.cover_notes.expiring_within_days`; inspector with the proposal link and *Cancel cover note* drawer with a reason).
  The proposal page shows its cover notes and *Issue cover note* (drawer: from the cover start or today, until the last allowed day by default; "Nothing is posted to the accounts").
- Shared file changes: `config/erp.php` (`cover_notes`), `RoleTemplates`, `PermissionsSeeder`, `routes/web.php`, `routes/console.php`, `lib/navigation.ts`, `ProposalPageController`
  and `proposals/Show.vue`, `ProposalService`, `TenantIsolationEveryTableTest` (a cover note row).
- Test changes: `RoleAdministrationTest` expects `cover_note.issue` in the Branch Officer template; `UserAdministrationTest`'s auditor refusal now names `cover_note.issue` (the first write permission of the Branch Officer template in alphabetical order, same assertion); `ProposalUnderwritingTest` expects the new `can.issue_cover_note` flag.
- Placeholder values to verify: cover note maximum 30 days per class (A-93, OPEN 2); `recognise_at` stays `policy` by default (OPEN 3).
- Not done, and why: premium recognition at cover note (D-33 gap: needs its own posting design); no printed cover note (R8 documents); policy issue from a proposal and superseding
  on issue are R7.
- Tests: `tests/Feature/CoverNotes/CoverNotesTest.php` (6: numbering and no accounting events, outbox or journals; validity cap per class, backdating, dates, unapproved proposal, no
  reserved number left behind; `recognise_at = cover_note` refused; supersede inside a transaction including an expired note; nightly expiry and cancel with permission and
  reason; queue order, expiring filter, proposal page props, cancel endpoint and role templates).

- Result: 1,226 Pest tests green, PHPStan 0 errors, Vitest (296) and vue-tsc green.

### R8b — Printing quotations and cover notes; quotes to follow up — done
- Closes what R4, R6 and R8 left for the merge: `QuotationDocumentData` and `CoverNoteDocumentData` (tagged `DocumentDataProvider`s) print the quotation and the cover note from
  their active templates. Both share `RatedRiskDocument`: risk details in the product version's own risk-schema labels (select options by label, money formatted, EN/BN), the
  frozen premium (net premium, each duty, gross) and the rating explanation. The quotation adds "Valid until" and its proposed period; the cover note its temporary period, and its
  status when it is no longer active. It prints the proposal's re-rated result when underwriting added a loading. A draft quotation is refused (`DOCUMENT_OBJECT_NOT_READY`).
- HTTP: `POST /quotations/{id}/generated-documents` and `POST /cover-notes/{id}/generated-documents` (locale en|bn), downloads `GET /quotations/{id}/documents/{doc}` and
  `GET /cover-notes/{id}/documents/{doc}`, all in `App\Http\Documents\GeneratedDocumentsController`. The quote workbench shows a "Printed quotation" panel (generate + versions);
  the cover notes inspector prints in English or Bangla and lists printed versions.
- Home "Quotes to follow up" now lists issued quotations still within their validity (newest price holding), next to Phase 1 policy quotes, opening the quotation; its
  empty action is *New quote* on the quote workbench.
- Test change: `tests/Pest.php` `fakePdfRenderer` declares its page list property explicitly (PHPStan generics).
- Tests: `tests/Feature/Documents/QuotationAndCoverNoteDocumentsTest.php` (3), `tests/Feature/Pages/HomeQuotesQueueTest.php` (1).

### R7 — Policy issue from proposal with frozen rating, endorsement re-rating — done
- Phase 3 design §2 steps 4–5 and INVARIANT "a policy's rating_result is frozen at issue". Migration `2026_09_28_000001_policy_issue_from_proposal_with_frozen_rating`:
  - `policies` += `quotation_id`, `proposal_id` (unique when set), `cover_note_id` (FKs), `risk_inputs`, `risk_keys` (duplicate-risk keys of the current risk), `rating_result`,
    `rating_plan_code`, `rating_plan_version`, `special_terms` (jsonb list `{code, loading_bp, reason, text}`), `stamp_duty_minor`, `issue_basis` (`credit` | `premium_received`),
    `premium_received_reference`. CHECKs: gross = net + tax + stamp duty; a rating needs its inputs and plan; `premium_received` needs a reference.
  - `policy_transactions` += `stamp_duty_delta_minor`, `rating_result` (an endorsement's re-rating), `rating_basis` (`original_plan` | `current_tariff`).
  - `product_versions` += `endorsement_uses_current_tariff` (default false; `ProductCatalogue` rating terms).
  - **INVARIANT** triggers `policies_protect_rating` / `policy_transactions_protect_rating` (`POLICY_RATING_FROZEN`, A-123).
  - Account role `stamp_duty_payable` (D-37). The renewal chain is the existing `renewal_of_policy_id` (D-38).
- Posting: rule version 2 of `POLICY_ISSUED.default` and `POLICY_ENDORSED.default` with a stamp duty line (`?? 0`, so events without stamp duty post as version 1); `PolicyAccountingEvents`
  sends `stamp_duty` / `stamp_duty_delta`. Golden fixtures `01d_policy_issued_with_stamp_duty.json`, `01e_policy_endorsed_rerated_decrease.json`. Demo chart account 2155 Stamp Duty Payable,
  chart-of-accounts template row, captions in `resources/help/roles.en.md` / `roles.bn.md`.
- `PolicyLifecycle` (D-39):
  - `issueFromProposal(proposal, on, actor, installments = 1, ?premiumReceivedReference)` — `ProposalService::approvedForIssue` (`PROPOSAL_NOT_APPROVED`), `policy.issue` on the branch,
    quotation basis (`QUOTATION_BASIS_INVALID`; `QUOTATION_EXPIRED` after its validity, A-116), credit issue (`PREMIUM_NOT_RECEIVED`, A-117), installments 1–12 (`INSTALLMENTS_INVALID`),
    producer licence on the issue date (LicenceRegistry), number `POL-<branch>-<fy>-<seq>` reserved before; one transaction: policy with the premium of the frozen rating (`RatedPremium`:
    net; VAT and levies as tax; stamp duty, A-118), risk keys, special terms from the proposal's manual loading, new-business transaction, installments, POLICY_ISSUED,
    `ProposalService::markIssued`, `CoverNoteService::supersedeForProposal`, audit `policy.issued` (plan, amounts, basis, reference, cover notes superseded), `PolicyIssued`.
  - `rateEndorsement(policy, effectiveDate, riskInputs, actor, ?coverages)` → `EndorsementRating` (before, after, basis, change, pro rata days), nothing written: the rating in force
    (latest re-rated endorsement, else the issue rating) against new inputs rated by `RatingEngine::rerateWith` on the issue rating's plan version, product version and date, or
    `RatingEngine::rate` on the effective date when the version sets `endorsement_uses_current_tariff`; the manual loading applies again (A-120); change in full or pro rata (A-119).
  - `endorseRisk(...)` — reason required, `ENDORSEMENT_NO_CHANGE`, `ENDORSEMENT_OUTSIDE_COVER`, `POLICY_NOT_RATED`; policy version, premium totals and risk keys move, the transaction keeps
    the new rating (frozen), installments increase or are credited, POLICY_ENDORSED for the change (none when the premium does not change), audit `policy.endorsed`.
  - `quote` and `endorse` refuse rated products (`PRODUCT_RATED`, A-115); `renew` of a rated policy is refused through `quote` until R9.
- `RatingEngine::rerateWith(result, inputs, ?loading, ?coverages)` (`rerate` delegates to it) and `rate(..., ?loading)`.
- `UnderwritingRules::duplicates` finds issued or active, unexpired policies by their risk keys; issued proposals are represented by their policy (A-122).
- Documents (R8 providers): the schedule prints the current rating (issue or latest endorsement) with its tariff, the policy's special terms, and VAT and levies / stamp duty
  when the rating's duties no longer add up to the policy's premium; the endorsement document adds the stamp duty change and the re-rating breakdown.
- HTTP and screens:
  - Proposal page: **Issue policy** (`can.issue_policy`: approved and `policy.issue`) — drawer with issue date, installments, "The premium was received" and reference (checked and
    required unless the product issues on credit), Review shows the POLICY_ISSUED journal (`lib/moneyForm`, `JournalPreviewDialog`), then redirects to the policy; *Open policy* once issued.
    `POST /proposals/{id}/issue-policy` (`moves-money`).
  - Policy page: **Rating** tab (new optional `rating` slot of `ObjectPage`) — tariff and rating date, proposal and quotation links, how it was issued (credit or premium received),
    which tariff endorsements use, special terms, the risk at issue with EN/BN labels by the user's language, each endorsement's before / re-rated / charged table and basis sentence,
    and the frozen breakdown (`RatingBreakdown`). Header fact *Stamp duty*. For rated policies **Endorse** opens `components/rating/EndorseRiskDrawer.vue`: effective date, reason, the risk
    form generated from the product's risk schema prefilled with the risk in force, optional cover, the live re-rated change (debounced `POST /policies/{id}/endorsement-rating`,
    field errors from the server), Review and post with the POLICY_ENDORSED journal (`POST /policies/{id}/endorse-risk`, `moves-money`). Pure helpers `lib/endorsement.ts`.
  - Policies → New (`policies/Create.vue`) lists only products without a rating plan and says how many are priced by a tariff, linking to Quotes.
- Demo data (D-39): `Database\Seeders\DemoNewBusiness::sell` runs quotation → proposal (KYC verified, submitted, approved automatically) → policy with the clock on the sale's day.
  `PartADemoSeeder` sells its 7 policies that way with risks chosen within the branch officer's limits (the bank statements and receipts follow the rated premiums) and leaves the
  follow-up as an issued quotation; the tenant admin now sets the placeholder underwriting limits from 1 Aug 2026 before the story (AdminUserSeeder runs earlier, unchanged).
  `DemoBusinessSeeder` sells its 32 policies and 4 follow-up quotations that way (limits from 1 Jan 2026). `DistributionDemoSeeder` sells FIRE-SME that way (the life product stays
  unrated, typed premium) and sizes the BDO monthly targets to the rated premiums. Demo product versions set `allow_credit_issue = true`.
- `scripts/flow-audit.mjs` steps 1–3 now quote in the workbench, make and submit the proposal, issue from the proposal page (checking for a stamp duty line) and receive the policy's
  gross premium. Not run in this slice (workers start no servers).
- Shared files edited: `PolicyLifecycle`, `PolicyAccountingEvents`, `Policy`, `PolicyTransaction`, `ProductVersion`, `ProductCatalogue`, `RatingEngine`, `UnderwritingRules`,
  `PolicyPageController`, `ProposalPageController`, `PolicyScheduleDocumentData`, `PolicyDocumentFacts`, `EndorsementDocumentData`, `routes/web.php`, `config/erp.php` (`policies`),
  `AccountRolesSeeder`, `DemoTenantSeeder`, `PartADemoSeeder`, `DemoBusinessSeeder`, `DistributionDemoSeeder`, `resources/setup/chart-of-accounts/non-life-insurance.csv`,
  `resources/help/roles.*.md`, `ObjectPage.vue`, `policies/Show.vue`, `policies/Create.vue`, `proposals/Show.vue`, `scripts/flow-audit.mjs`, `docs/PROGRESS.md`, `docs/DECISIONS.md`.
- Test changes (requirement changed): `PartADemoTest` — the Part A products are rated, so the quote to follow up is an issued quotation instead of a policy in `quote` status (7
  policies, not 8); the test now also checks that one quotation waits, no policy is a quote, every policy came from an issued proposal with a rating result and stamp duty, and gross =
  net + tax + stamp duty on every policy. `ProposalUnderwritingTest` expects the new `can.issue_policy` flag (exact array kept). `DocumentGenerationTest` gives its Phase 1 product its
  class after creating its typed-premium policies instead of before (same documents generated and checked). `ProductRatingTermsTest` now expects the typed quote on the rated version
  to be refused (`PRODUCT_RATED`) and puts the version in use with a policy row written directly; the in-use refusals are asserted as before.
- Tests: `tests/Feature/Policies/PolicyIssueFromProposalTest.php` (8: issue from an auto-approved proposal with amounts, journal lines, proposal issued and cover note superseded;
  referred then approved with a loading, special terms on policy and schedule; rollback when the issue fails after the proposal and cover note changed; database freeze; credit issue
  refusal / reference / product flag and quotation validity; endorsement on the original plan version after a newer tariff with the POLICY_ENDORSED delta and a second endorsement
  against the rating in force; current-tariff flag and pro rata; typed premiums refused for rated and kept for unrated products; duplicate risk by policy risk keys moved by an
  endorsement), `tests/Feature/Policies/PolicyRatingScreensTest.php` (3: proposal page issue with journal preview and refusal; policy Rating tab, live re-rating, endorse preview
  and post; quote form products), golden fixtures `01d`, `01e` (GoldenRulesTest), `resources/js/tests/endorsement.test.ts` (3).
- Result: 1,293 Pest tests green, PHPStan 0 errors, Vitest (321) and vue-tsc green.
- **Placeholder values to verify (R7):**
  | Where | Value |
  |---|---|
  | Credit issuance (A-117, OPEN 4) | not allowed unless the product version says so; premium-received reference otherwise |
  | Stamp duty on cancellation (A-118) | never refunded (VAT per D-06 only) |
  | Endorsement charge (A-119) | full annual difference (`pro_rata` available) |
  | Issue within quotation validity (A-116) | 15 days (A-80) |
  | Demo products | `allow_credit_issue = true`; demo risks (registrations, addresses, sums insured) illustrative; BDO monthly targets 60,000.00; demo underwriting limits from 1 Jan 2026 (local demo) and 1 Aug 2026 (Part A) |
- Not done, and why: credit by producer or customer type and linking a pre-issue receipt to the premium-received reference (A-117 gaps: no receipt target before issue); multi-payer
  shares when issuing from a proposal (A-121); the premium register and reports still show gross, net and tax (stamp duty is gross − net − tax there); renewals of rated policies (R9);
  the flow audit was not rerun; no role template holds `policy.endorse` (Phase 1 gap, unchanged).

### R10b — "How this works" and the guided tour start at Quote — done
- Help: new module `quotes` (`resources/help/quotes.en.md`, `.bn.md`) on the quote workbench, quotations list, proposal page, referral queue and cover notes queue — what a
  quote, proposal, underwriting referral and cover note are, that none of them posts accounting, and that the policy is issued from the approved proposal. `policies` help now says
  rated products start in Quotes, the premium is frozen at issue, stamp duty is recorded, and a risk change is a re-rating endorsement.
- Tour: step 2 is now *Quote, propose and issue the policy* on `/quotations/create` (was Policies → New), followed by a new *Decide referred proposals* step on
  `/underwriting/referrals` (branch manager); nine steps, EN and BN. The role hint names the Branch Manager for referrals.
- Screenshots of the Phase 3 screens: `storage/ux-screenshots/p3-quotes/` (quotes, quote workbench, new quote, referrals, referred proposal, cover notes, policy Rating and
  Documents tabs) and `storage/ux-screenshots/p3-admin/` (tariffs, plan, tables, templates, template editor, underwriting limits).
- Test changes: `HowThisWorksTest` expects the `quotes` module and its five screens; `GuidedTourTest` and `tour.test.ts` expect the new step ids.

### R9 — Renewals: expiry register, renewal quotations at T-45, notices, conversion, reports — done
- Phase 3 design §4 and §6 "Expiry register queue". Module `App\Modules\Insurance\Renewal` (D-40), Platform `Notifications` (D-41). Migration `2026_09_29_000001_create_renewals_and_notifications`:
  - `quotations` += `renewal_of_policy_id` (FK policies; frozen once out of draft, trigger `quotations_protect_renewal_link`; partial unique index: one open renewal quotation per
    policy); `created_by` nullable only for a renewal quotation (CHECK `quotations_creator`).
  - `expiry_register` (tenant, forced RLS): one row per policy (unique) — entity, branch, policy and number, product, class, producer, policyholder, expiry, rated, bucket, days left,
    as of, status `upcoming|renewal_offered|renewed|lapsed|not_renewed`, renewal quotation, quote problem (code, text), renewal policy, reason and note, closed by/at. CHECKs: status list,
    renewed has its policy, lapsed/not renewed have a reason, `other` has a note.
  - `renewal_notices` (tenant): one per policy and offset (unique) — kind `notice|reminder`, quotation, generated and stored document, notification ids, sent on.
  - `notifications` (tenant): channel `email|sms`, adapter, recipient (party id and name; address null until parties hold contact details), subject, template, title, body, attached
    stored document, idempotency key (unique per channel), status `sent|failed`, failure, sent at.
  - Permission `renewal.manage` (A-126), granted to existing tenants' branch officer and branch manager roles.
- `ExpiryRegister` (D-42): `build(today)` — idempotent upsert of issued/active policies expiring within the largest bucket (`erp.renewals.buckets` 60/30/15/7, A-125), days left and
  bucket refreshed on open rows; closes open rows: `renewed` (policy renewed and its renewal issued, also the Phase 1 renewal quote once issued), `lapsed` with `policy_cancelled` /
  `policy_lapsed` (open renewal quotation declined by the system) or `no_response` the day after expiry (A-132); `recordNotRenewed(entry, reason, note, actor)` (`renewal.manage` on the
  branch; `RENEWAL_REASON_INVALID`, `REASON_NOTE_REQUIRED`, `RENEWAL_ALREADY_CLOSED`; declines the open renewal quotation; audited `renewal.not_renewed` on the policy);
  `markRenewed(PolicyRenewed)` listener. `RenewalReasons` (configured list `erp.renewals.lapse_reasons` EN/BN plus system reasons).
- `RenewalQuotations`: `offerDue(today)` at `erp.renewals.quote_days_before` (45) for rated open rows not yet offered — problems stored on the row and retried nightly (A-135);
  `offerNow(entry, actor)` from the queue (`RENEWAL_NOT_RATED`, `RENEWAL_ALREADY_CLOSED`, `RENEWAL_QUOTATION_OPEN`). Risk in force (latest re-rated endorsement, else issue rating) and
  its coverages (A-129), `NoClaimBonus::apply` (A-128), product version and tariff in force on expiry + 1 day, valid until expiry (A-127).
- `QuotationService::offerRenewal(RenewalQuotationTerms, on, ?actor)` (`renewal.manage` for a person; `RENEWAL_BASE_NOT_RENEWABLE`, `RENEWAL_QUOTATION_OPEN`, `QUOTATION_INCEPTION_IN_PAST`,
  `PRODUCT_NOT_RATED`, rating refusals) — issued directly with number, frozen rating and `quotation.created` / `quotation.issued` audit (system or user); producer eligibility recorded as
  not applicable (A-133). `declineRenewal(quotation, reason, ?actor)`, `openRenewal(policy)`.
- `RenewalNotices::sendDue(today)` (A-131): offsets `[quote_days_before, ...erp.renewals.reminders]`; the latest due offset not yet sent per policy while the renewal quotation is issued;
  the first message generates the `renewal_notice` PDF by the system (`DocumentGenerator::generateBySystem`, D-41) through the new provider `RenewalNoticeDocumentData` (object type
  `expiry_register`, PDF on the expiring policy's Documents tab: expiring policy, expires on, renew by, renewal quotation, risk details, renewal premium rows, renewal period); reminders
  reuse it; `Notifier` sends through every enabled channel (`erp.notifications.channels` email/SMS, adapter `log`).
- `RenewalRun::run(today)` (build → offer → notices), `RenewalRunJob` nightly at 00:30 per tenant (`routes/console.php`).
- Conversion: the renewal quotation is accepted on the workbench as any quotation (proposal, KYC, submit); `UnderwritingRules` does not refer a renewal for the producer's licence and
  does not count the renewed policy as a duplicate risk; `PolicyLifecycle::issueFromProposal` reads the quotation's `renewal_of_policy_id`: `RENEWAL_BASE_NOT_RENEWABLE` unless the
  expiring policy is active or expired, no new-business licence check, sets the new policy's `renewal_of_policy_id`, moves the expiring policy to `renewed` (audit `policy.renewed`) and
  dispatches `PolicyRenewed` in the issue transaction (A-133). `PolicyLifecycle::renew` refuses rated policies (`RENEWAL_BY_QUOTATION`); the policy page hides *Renew* for them (A-130).
- Reports (`reports.financial`): **Expiry register** (as of a date: policy, customer, product, branch, producer, expiry, days left, bucket, renewal status, renewal quotation, gross; totals
  and summaries by bucket, branch, producer; rows drill to the policy) and **Renewal conversion** (period, by branch / agent / product: each expiring policy's outcome, reason and renewal
  policy with links; totals expiring / renewed / not renewed / conversion; summaries conversion per group and not renewed by reason), `ExpiryRegisterReportQuery`,
  `RenewalConversionQuery` (basis points, half up; A-134).
- UI: sidebar **Renewals** (primary, after Policies, `renewal.manage`). `pages/renewals/Index.vue` — QueueView "Expiry register": bucket toggle (All / 7 / 15 / 30 / 60 days), status,
  branch and producer filters (server query), columns policy, customer, product, branch, producer, expires, left, premium, renewal quotation, status; inspector with the policy facts,
  renewal quotation (number, premium, valid until, status), renewal policy, reason, quote problem, notices sent with PDF links, *Create renewal quote now*, *Open policy*, *Open renewal
  quotation*, *Record not renewed* (drawer: reason list, note). Empty state: "No policies expire in the next 60 days." + *Open policies* (filtered: *Show all renewals*).
  `lib/status.ts`: `renewed` ok, `renewal_offered` warn.
- The quote workbench shows "Renewal of POL-…" (`quotation.renewal_of`).
- Shared files edited: `QuotationPageController`, `quotations/Workbench.vue`, `PolicyLifecycle`, `PolicyPageController`, `QuotationService`, `Quotation` (docblock), `UnderwritingRules`, `DocumentGenerator`, `DocumentStore`,
  `InsuranceServiceProvider`, `ReportsPageController`, `RoleTemplates`, `PermissionsSeeder`, `config/erp.php` (`renewals`, `notifications`), `routes/web.php`, `routes/console.php`,
  `lib/navigation.ts`, `lib/status.ts`, `TenantIsolationEveryTableTest` (register, notice and notification rows), `docs/PROGRESS.md`, `docs/DECISIONS.md`.
- Test changes (requirement changed): `RoleAdministrationTest` and `PermissionsTest` expect `renewal.manage` in the Branch Officer template (exact lists kept).
- Tests: `tests/Feature/Renewals/RenewalsTest.php` (9: register buckets, tenant loop through the job, idempotent rerun, days left moving; T-45 quotation once on the newer tariff with
  claim-free years 2 → 3 without claims and → 0 after a claim, system audit; unrated policy without quotation or notices and its Phase 1 renewal, rated `renew` refused; notice with PDF and
  log notifications on both channels, reminders at 30/15/7 once each, late entry gets only the latest offset; end to end renewal quotation → proposal (auto-approved, no duplicate) →
  policy with `renewal_of_policy_id`, expiring policy and register renewed, second renewal refused; renewal of a lapsed policy refused and the row lapsed; not renewed with reason via
  HTTP, quotation declined, no reminder, lapse with `no_response` after expiry, reason recorded later; both reports' numbers, groups, reasons and permissions; queue props, filters, offer
  now from the queue, notice links, 403 for others, role template holders).
- Result: 1,306 Pest tests green (full suite; the renewals, quotation and document tests rerun after the last workbench change), PHPStan 0 errors, Vitest (323) and vue-tsc green.
- **Placeholder values to verify (R9):**
  | Where | Value |
  |---|---|
  | Buckets (A-125) | 60 / 30 / 15 / 7 days |
  | Renewal quotation (A-127) | offered 45 days before expiry, valid until the expiry date |
  | Reminders (A-131) | 30, 15 and 7 days before expiry; notice language English |
  | NCB (A-128) | +1 claim-free year without a claim, 0 after any non-rejected claim; scale 0/10/20/30 (design OPEN 5) |
  | Lapse reasons (A-132) | price, service, sold asset, moved to a competitor, no response, other (EN/BN labels; Bangla to be checked) |
  | Notification channels (A-131) | email and SMS enabled, log-only adapter |
- Not done, and why: gateway adapters (SSL Wireless / Twilio / email) are LATER by design and parties have no email address or phone number to send to; the expiring policy's special
  terms are not re-applied to the renewal quotation automatically (A-129); a claim registered after the renewal quotation was offered does not re-rate it (A-128); no demo expiring
  policies are seeded — the Part A and local demo stories sell from Aug 2026 / Jan 2026 on 12-month terms, so nothing reaches the 60-day window before Nov 2026 without back-dating rated
  sales before the demo tariffs start; help files and the tour are the
  lead's.

### Phase 3 (R1–R10) — end state
- All slices of docs/rating-quotation-documents-design.md §7 MVP are done, one commit each:
  - R1–R3: rating model and engine;
  - R4: quotation workbench;
  - R5: proposal and underwriting;
  - R6: cover notes;
  - R7: issue from proposal with frozen rating and endorsement re-rating;
  - R8 and R8b: documents;
  - R9: renewals;
  - R10a: tariff editor;
  - R10b: help and tour.

  The other §6 screens shipped with their slices:
  - referral queue (R5);
  - cover notes queue (R6);
  - policy Rating tab (R7);
  - Documents tab and template editor (R8);
  - expiry register queue (R9).
- **Decisions and assumptions:** D-18 to D-21 and D-30 to D-42; A-65 to A-69 and A-80 to A-135. They are recorded in the table above and in docs/DECISIONS.md.
- Worked in parallel git worktrees with separate test databases (`erp_test_a`…`d`) and merged onto main slice by slice.
- **Flow audit** (`scripts/flow-audit.mjs`, docs/flow-audit.md): Part A steps 1–14 on the rated demo now quote in the workbench, go through proposal and underwriting, issue with stamp duty, print the receipt and run the close: 12 pass, 2 partial. The partials are AP/payroll (G6) and IDRA forms (G5).
  - The audit found two UI bugs, fixed in 6b0c641:
    - every confirmation dialog answered "no";
    - the quote workbench kept its pre-save state.
- Final gate: 1,306 Pest tests and 325 Vitest tests green, PHPStan 0 errors, vue-tsc and production build green.
- Help: `renewals` joins the "How this works" modules (expiry register screen), EN and BN.
- **Screenshots:** `storage/ux-screenshots/p3-quotes/` and `p3-admin/`; earlier `s1-wizard`, `s3-help`, `s4-tour`, `s5-captions`, `s6-empty`.
- **Operations:** deploy with `php artisan queue:restart`. A worker still running the previous code posts POLICY_ISSUED with the old rule set, without the stamp-duty line, and the event fails as unbalanced; the audit hit exactly that. Existing tenants must map the new account role `stamp_duty_payable` (D-37) before issuing rated policies with stamp duty.

#### Placeholder values to verify (OPEN items and seeded data; none are confirmed IDRA/NBR/company figures)
| Area | Value today | Where | Ref |
|---|---|---|---|
| VAT on premium | 15% of net premium, every class | `duties` rows (`verify`, `source=placeholder_verify`) | OPEN 1, A-68 |
| Stamp duty | Motor 50.00 per policy; fire 200.00 / 500.00 / 1,000.00 by sum insured (<10m / <50m / above); marine cargo and misc 100.00 per policy; not refunded on cancellation | `duties`; A-118 | OPEN 1 |
| Motor own-damage rate (‰) | Private 20.00 / 22.50 / 25.00, commercial 27.50 / 30.00 / 32.50, motorcycle 15.00 / 17.50 / 20.00 for ≤1300 / 1301–1800 / >1800 cc | MOTOR-TARIFF plan (verify) | R3 |
| Motor third-party liability | Private 2,500, commercial 4,000, motorcycle 900 | MOTOR-TARIFF | R3 |
| Passenger liability | 45.00 per seat | MOTOR-TARIFF | R3 |
| Motor loadings | Driver under 25: +10%; built 2015 or earlier: +15% | MOTOR-TARIFF | R3 |
| No-claim bonus | 0 / 10 / 20 / 30%; +1 claim-free year after a period with no claim, reset to 0 after a non-rejected claim | MOTOR-TARIFF; renewals | OPEN 5, A-128 |
| Minimum premiums | Motor private 5,000 / commercial 7,500 / motorcycle 1,500; fire 1,000; marine cargo 500; misc 500 (higher of plan and product wins) | plans; A-67 | R3 |
| Fire rate (‰) and loading | Dwelling 0.80, shop 1.50, warehouse 2.00, factory 2.50; construction class 3 +25% | FIRE-TARIFF | R3 |
| Marine cargo rate (‰) | Import 1.50 / 1.00 / 1.20, export 1.20 / 0.80 / 1.00, inland 1.80 / 1.20 / 2.00 for sea / air / road | MARINE-TARIFF | R3 |
| Misc rate | 3.00‰ of sum insured | MISC-TARIFF | R3 |
| Rounding | Nearest 1.00, half-even | plans | R3 |
| Risk schema bounds and options | Engine 50–10,000 cc, seats 1–60, year 1950–2100, driver age 18–99, claim-free years 0–50; select options (vehicle types, occupancies, construction classes, voyages, conveyances) | demo product versions | R1 |
| Premium recognition | At policy, not cover note; `recognise_at=cover_note` is refused (gap) | product flag | OPEN 3, A-65, D-33 |
| Credit issuance | Not allowed unless the product version allows it (demo products allow it); otherwise a premium-received reference is required | product flag | OPEN 4, A-117 |
| Quotation validity | 15 days, issue day included; issue only within validity | `erp.quotations.valid_days` | A-80, A-116 |
| Underwriting limits (demo tenants) | Branch officer motor 2,000,000 / fire 5,000,000 / marine 2,000,000 / misc 1,000,000; branch manager 10m / 25m / 10m / 5m; finance manager 50m all; CFO 250m all. A new tenant has none, so everything is referred | `underwriting_limits` (verify) | A-90 |
| Referral risk flags | Motor vehicle older than 15 years; fire construction class 3 | config | A-89 |
| Duplicate-risk keys | Motor registration or chassis number; fire address | config | A-88 |
| KYC document types | NID, passport, birth certificate, trade licence, TIN | config | A-92 |
| Cover note maximum validity | 30 days for every class | `erp.cover_notes.max_days` | OPEN 2, A-93 |
| Endorsement premium | Full annual difference (not pro rata) unless configured | config | A-119 |
| Approval limits (demo / setup defaults) | Claim payment approval ≥ 500,000 → Finance Manager then CFO; claim release ≥ 500,000 → CFO; manual journal and reversal → Finance Manager | approval policies | A-55 |
| Renewals | Register buckets 60/30/15/7 days; renewal quotation 45 days before expiry, valid until expiry; reminders at 30/15/7 days; notices in English; email and SMS log-only | `erp.renewals.*` | A-125–A-135 |
| Lapse / non-renewal reasons | Price, service, sold asset, moved to competitor, no response, other (and their Bangla labels) | `erp.renewals.lapse_reasons` | A-133 |
| Document templates | All default wording (EN) and every Bangla label and sentence; endorsement number `<policy>/E<n>`; Latin digits on Bangla documents; preview demo data | `document_templates` | A-103, A-104, A-107 |
| Demo data | Risks, registrations, addresses, sums insured; BDO monthly targets 60,000 | demo seeders | R7 |

#### Not done / gaps (for the next phase)
- **Recognition at cover note:** refused (D-33). It needs its own posting path.
- **Approval engine:** refunds and commission payouts don't go through it, so they have no limit screen.
- **Credit issuance and payers:**
  - credit can't be set by producer or customer type;
  - the premium-received reference isn't linked to a receipt;
  - multi-payer shares aren't supported on the proposal path.
- **Reports:**
  - the premium register has no stamp duty column;
  - no IDRA forms (G5).
- **Renewals:**
  - manual loadings aren't copied to the renewal quotation;
  - a claim registered after the offer doesn't re-price it;
  - customers have no email or phone, so the real SMS/email adapters (LATER) need those first.
- **Permissions:** no role template holds `policy.endorse` (a Phase 1 gap) or `reports.regulatory` for the finance manager (flow audit).
- **LATER per the design note:** life rating, fleet/group policies, co-insurance on the schedule, sanctions, tariff import from IDRA circulars, bulk print/email from queues.
- **Observations from the flow audit to decide on:** UTC versus tenant dates; locking a month early or with pending manual journals.

### Flow fixes X1–X12 — measured Part A flow audit — done
Flow audit only, no new features: docs/flow-audit.md now meters each Part A step (screens, drawers/dialogs, clicks, keystrokes, leaves, defaults, unknowable fields, next steps). The worst cases were fixed in order of impact, one commit each, and the step was re-measured.

| | Screens | Drawers/dialogs | Clicks | Keys | Steps over 3 screens | Not creatable inline | No default | Unknowable | Next step not offered |
|---|---|---|---|---|---|---|---|---|---|
| Before | 45 | 18 | 161 | 324 | 6 | 3 | 13 | 1 | 3 |
| After | 38 | 19 | 137 | 291 | 1 | 0* | 1 | 0 | 0 |

\* A producer and an account are created inline by the roles that hold the permission (agent.manage, accounting.manage_coa). A branch officer or accountant is told whom to ask.

- **X1:** after issue, "Record the premium receipt?". `/receipts/create?policy=` is prefilled with the outstanding amount, allocation lines, branch and today. Receipt forms default to the user's branch and last channel.
- **X2:** today is the default on "now" dates: claim reported on, reserve, recovery, reject/reopen, approval, paid on, close, and the manual journal and reversal dates. Date of loss stays empty.
- **X3:** after the reserve, "Approve payment" if the user may (SoD and approval limits checked read-only), otherwise who approves. "Claims to settle" on the claims manager's Home; "Payments to release" for the finance manager and CFO. The approval drawer proposes the uncommitted reserve and the policyholder.
- **X4:** Home start actions: New quote, Record a receipt, Register a claim, New manual journal.
- **X5:** the receipt has "Print receipt" in its header and after recording (then Download). Allocate shows only while money is open.
- **X6:** new quotes and policies start with the product the user last used (`drafts.last-product`).
- **X7:** risk fields take `required_at` (quote|proposal) and `default`. The motor chassis number is required at proposal and entered in the proposal's "Enter risk details" drawer; that re-rates and saves only if the premium is unchanged. Vehicle type defaults to private.
- **X8:** payee lookup with inline "New payee" (vendor or beneficiary), `POST /lookup/payee`, checked on `claim.approve`.
- **X9:** "New producer" from the quote's producer lookup creates the party, producer and licence in one transaction (`POST /lookup/producer`). Code prefixes are in `erp.distribution.producer_code_prefixes`.
- **X10:** "New account" on a manual journal line, as a one-row chart-of-accounts import (`POST /accounting/accounts`).
- **X11:** account activity has a Source column. P&L, balance sheet and trial balance link to each other.
- **X12:** `GET /reports/{report}/export?format=csv|xlsx` and export links on the reports index.

**Remaining** (detail in docs/flow-audit.md):
- Step 10 is 4 screens, measured from the bank screen; it is 3 from Home.
- Product has no default on a user's first quote.
- **Found while fixing, not changed:**
  - "Pay from" in the claim release drawer is not read by the release endpoint.
  - Policy, receipt and receipts-create pages need an area permission held tenant-wide, so a user with only a branch-scoped role gets 403.
  - No Pest case covers an endorsement clearing a proposal-stage field.

### 2.1 — Design addendum v2 and Phase 2 customer questions — done
- Docs only (no code, migration, permission, assumption or decision row; no test run needed). `docs/phase-2/kickoff.md` §1 and the 2.1 row link both documents.
- `docs/design-addendum-v2.md`:
  - **Part A**, the delta since design v1 as built: stack and context map; Phase 0/1 deviations; Distribution D1–D9, onboarding S1–S6, F1–F6, Phase 3 R1–R10, X1–X12 (model, invariants, posting rules, permissions and templates, SoD, numbering, approvals, jobs, UI flows); every v1 and design-note OPEN item mapped to its ASSUMPTION (verify) or kept OPEN; D-01–D-42 in one line each; gaps carried into Phase 2.
  - **Part B**, the Phase 2 design per kickoff §1: workflow engine, AP, AR (non-premium), expenses and petty cash, fixed assets, budgets, cash flow, employees/attendance/leave, payroll (rule sets, runs, final settlement, self-service) and the commission payout route. Each has MVP vs LATER, a data model sketch, posting events with worked examples, invariants, state machines, reconcilers and close tasks, permissions and SoD, screens and dependencies.
  - Proposed decisions are tagged `DECISION (proposed) PD-n`, for review. They get D-ids only when a slice applies them; D-50–D-52 were not used.
  - Part B §B.18 proposes slice-list changes: 2.1b business clock and close rules; 2.1c numbering fix; 2.6 split into 2.6a/2.6b; kernel `for_each` rule lines and cost centres with 2.3; 2.14 payroll route blocked on the commission tax question.
- `docs/phase-2/customer-questions.md`: 71 questions in groups A–J with a summary table (default with A-id, blocked slices, needed before build / finish / go-live):
  - all 17 Phase 1 questions, carried and updated; none has a recorded answer;
  - the Phase 3 placeholder values to verify;
  - the three flow-audit decisions: H2 UTC or Asia/Dhaka for "today", C4 block or warn on pending approvals at close, C5 locking before month end;
  - the nine kickoff Phase 2 questions, plus the ones Part B needs.
- Found while reading the code, not changed (listed in addendum §A.7.6, A.12 and CQ-E5):
  - **Receipt, claim and agent deposit numbers can collide across branches.** Their sequences are per branch, but the format `{prefix}-{fy}-{seq}` has no branch code, and the tables are unique on (tenant, number). A second branch's first receipt of a year would be refused. No test covers two branches for these documents.
  - **Outbox messages other than `PostAccountingEvent` are never relayed.** This includes `CommissionPayrollEarning` and `CommissionPayableToAp`, so Phase 2 needs consumers (PD-7).
  - **No role template holds `commission.pay`.**

### 2.0d — Claim reserve property test — done
Phase 1 exit checklist: "add a generator-based property test for the claim reserve lifecycle". Test only, plus one fix the test found.

- `tests/Feature/Claims/ClaimReservePropertyTest.php` drives the real claim services on a fresh claim per run through random operation sequences:
  - operations: register, reserve (set and adjust), approve payment within and over the approval limit, approve/reject that approval, request release,
    release within and over the limit, approve/reject the release approval, close, reject, reopen (directly and through approval), approve/reject the
    reopening, recovery;
  - actors satisfy SoD: a claims officer reserves; a claims manager approves, requests release, closes, rejects, reopens and records recoveries; a
    finance user releases; a CFO decides the approvals;
  - approval policies in the test: payment from 50,000.00, release from 30,000.00, reopen from a reserve of 20,000.00, so both routes occur;
  - posting is synchronous (`QUEUE_CONNECTION=sync`, as in the other claims tests);
  - dates are random from July 2026 and move forward 0–3 days per operation, inside the policy's cover and the FY2026 periods;
  - deliberately invalid operations are drawn as well: zero or negative amounts, approvals over the reserve, reserves below the committed amount or
    unchanged, payments in the wrong status, blank reasons, an unknown recovery type, wrong claim status.
- `ClaimLifecycleModel` (reference model, D-46) predicts for every operation "accepted" or the exact reason code. After EVERY operation the test checks:
  - the services agreed with the model; a refused operation changed nothing (row counts of claims, reserves, payments, recoveries, accounting events,
    journals, lines, outbox, approvals, decisions, audit events, document numbers; the claim row, its payments and every approval's state);
  - claim status, reserve and reserve version, and every payment's amount and status, equal the model;
  - reserve history has contiguous versions, no negative total, and deltas summing to the current reserve;
  - paid ≤ approved ≤ committed ≤ reserve, so no approval ever exceeded the reserve available when it was made;
  - GL by claim equals the subledger:
    - claims_outstanding = reserve − approved (never negative);
    - claims_payable = approved − paid;
    - claims_expense = reserve;
    - bank = recoveries − paid;
    - claims_recovery_income = recoveries;
  - a closed or rejected claim has zero outstanding and a reserve equal to what was approved; a reopened claim keeps these balances and passes the same checks;
  - one posted journal per reserve version, approval, payment and recovery, every one balanced per currency, no accounting event left unposted;
  - pending approvals are exactly the model's waiting payments, releases and reopening;
  - `ClaimsReconciler` for the claim equals the GL of claims_outstanding + claims_payable (reserve − paid), and `ReconciliationService::currentVariances`
    reports no claims variance as of the operation's period.
- Size and replay: `PROPERTY_RUNS` (default 100), `PROPERTY_STEPS` (default 20), `PROPERTY_SEED` (default 20260914; run i uses seed + i). A failure
  prints the run, its seed, `PROPERTY_SEED=<seed> PROPERTY_RUNS=1 …` to replay it, and the operations so far. `PROPERTY_VERBOSE=1` prints how often each
  operation and outcome occurred. With 50 runs or more the test also requires every operation to have been accepted at least once and every refusal
  reached. The default takes about 45 s. Also run once with seed 777000, 400 runs × 25 operations: green (250 s).
- Generator: `Tests\Support\Property\SeededGenerator` (PHP `Random\Randomizer` with Xoshiro256**; no new dependency, no shrinking).
- **Bug found and fixed** (separate commit `fix(claims)`, A-143):
  - Asking to reopen a closed claim while a reopening waited for approval started a second approval. Once the first reopened the claim, the second could
    never be approved; after a later close it would reopen the claim with nobody asking.
  - Now `REOPEN_PENDING` refuses it, and the claim page hides Reopen while one is pending.
  - Regression tests: `ClaimsTest`, `ClaimsCommissionApprovalsPagesTest`.
- **Found, not changed** (design questions, not invariant breaks):
  - A reopened claim is `reserved` even when it was paid (§5.5). It cannot be closed again until a new payment is approved (close needs approved or paid),
    and recoveries are refused (`CLAIM_NOT_PAID`) until then. The only way to end it without paying more is to reject it.
- Result: 1,360 Pest tests, 348 Vitest tests green, PHPStan 0 errors, vue-tsc green.

### G5 — Branch-coded receipt, claim and deposit numbers — done
- **Bug (addendum §A.7.6, CQ-E5), now reproduced by a test:** receipts (`RCT`), claims (`CLM`) and agent deposits (`ADP`) reserve from a sequence per entity + branch + fiscal year, but had no
  entry in `erp.numbering.formats`, so they used the branch-less `{prefix}-{fy}-{seq}`. The first receipt, claim or deposit of the year in a second branch got the first branch's number
  (`RCT-2026-000001`) and was refused by the unique constraints (`receipts`, `claims`, `agent_deposits` unique on tenant + number): a second branch could not record any of them.
- **Fix:** `config/erp.php` `numbering.formats` gains `receipt`, `claim` and `agent_deposit` → `{prefix}-{branch}-{fy}-{seq}` (env `ERP_RECEIPT_NUMBER_FORMAT`, `ERP_CLAIM_NUMBER_FORMAT`,
  `ERP_AGENT_DEPOSIT_NUMBER_FORMAT`), e.g. `RCT-HO-2026-000001`, `CLM-CTG-2026-000001`, `ADP-HO-2026-000001`. ASSUMPTION A-150 (the CQ-E5 option (a)), decision D-53.
  - A document type with no configured format is now branch-coded too (`DocumentNumberer::DEFAULT_FORMAT`); `{branch}` still drops out for entity-level sequences, so commission
    statements read `CST-2026-000001` as before (now listed explicitly, env `ERP_COMMISSION_STATEMENT_NUMBER_FORMAT`). A future branch-scoped type cannot repeat this collision (addendum PD-6).
  - Branch-coded formats were preferred over one entity-wide sequence: an entity-wide sequence would start again at `000001` and collide with numbers existing tenants already issued.
    A branch-coded number can never equal a branch-less one, so running sequences simply continue (`RCT-2026-000007` → `RCT-HO-2026-000008`).
- **Issued numbers untouched:** no migration; `document_numbers` and the business rows keep their numbers (the database refuses renumbering). Only new numbers use the format.
- Every other `DocumentNumberScope` caller checked: policies, quotations, proposals and cover notes are branch-scoped with branch-coded formats (F1, R4–R6); commission statements
  (`CommissionStatementRun`, `CommissionPayoutService`) are entity-scoped with no branch. No other mismatch.
- There is no numbering settings screen or wizard step listing formats (formats are configuration only), so no UI change. Global search already finds branch-coded numbers
  typed short (`RCT-HO-8`).
- Sample numbers in document template previews (`DocumentVariables`) now read `RCT-HO-2026-000007` and `CLM-HO-2026-000003`. Addendum §A.7.6 and CQ-E5 note the fix.
  Earlier PROGRESS sections (1B receipts `RCT-<FY>-nnnnnn`, claims, agent cash) describe the shape at the time and are left as history. `docs/flow-audit.md` and
  `scripts/flow-audit.mjs` still show example numbers such as `RCT-2026-000007` (not edited; re-measure the flow audit to refresh them).
- Tests: `BranchNumberingTest` (new, 3: a receipt, a claim and an agent deposit in HO and CTG through `ReceiptService`, `ClaimService` and `AgentDepositService`; all three failed with
  the unique violation before the fix); `DocumentNumbererTest` +1 (receipt/claim/deposit shapes, an issued branch-less number kept while the sequence continues branch-coded,
  unconfigured types branch-coded, entity-level without branch).
- Test changes (requirement changed; all equivalent or stricter): `DocumentNumbererTest` expects `RCT-HO-2026-000001/2` and `RCT-HO-2027-000001` for the branch sequence
  (the entity-level `RCT-2026-000001` unchanged), the voided list `RCT-HO-2026-000001`, and in the F1 test a HO and a CTG receipt instead of "other documents keep their format";
  `CollectionsTest`, `ClaimsTest`, `AgentCashTest` assert the exact number (`RCT-HO-2026-000001`, `CLM-HO-2026-000001`, `ADP-HO-2026-000002` — its 000001 is reserved by the
  refused deposit just before) instead of a `…-2026-` prefix.

### 2.0c — Playwright E2E happy path — done
Phase 1 carry-over (design §9.1, CI-blocking; exit checklist §2 "E2E (Playwright)"). A browser test that fails on regressions, not a measurement: `tests/e2e/happy-path.mjs` on `playwright-core` with the system Chrome and a small assert helper, `tests/e2e/lib.mjs` (D-48).

**What it covers**, on the Part A demo tenant (`nonlife`), each step as its role:
1. Branch officer: New quote from Home, new customer created inline, producer AG-001, motor risk; issue the quotation → make proposal → KYC → risk details (chassis) → submit; the proposal is approved automatically.
2. Branch officer: issue the policy. The journal preview must balance and debit Premium Receivable with the gross premium and credit the Unearned Premium Reserve. The policy has a `POL-` number and is Issued/Active.
3. Branch manager: "Record the premium receipt" from the policy. The amount is prefilled with the premium; allocate it to the installment. The preview must debit the bank and credit Premium Receivable. The receipt is Allocated.
4. Claims officer: register a claim from Home, then reserve 200,000. The preview must debit Claims Incurred and credit the Outstanding Claims Reserve.
5. Claims manager: approve 180,000 (the preview moves it to Claims Payable) and request release.
6. Finance manager: pay (the preview debits Claims Payable and credits the bank).
7. Claims manager: close the claim (the preview releases the remaining 20,000).
8. Outcomes on the screens:
   - the policy is still in force;
   - the receipt is Allocated;
   - the claim is Closed;
   - the "View accounting" panels of the policy, receipt and claim list only Posted journals with the expected accounts;
   - the trial balance says Balanced, with equal non-zero totals;
   - the accountant's Home "Failed accounting events" queue is empty.

Every journal preview is also checked line by line: it balances, its lines add up to its totals, and it shows no "would not post". Any uncaught page error or HTTP 5xx fails the step (A-149). The run acts on today's date inside the demo's open periods (A-148).

**Not in it:** earning and closing the month (the checklist's original "earn → close month"). Closing the current month needs the bank statement imported and matched first: the bank reconciliation task blocks on the new receipt and claim payment. That is flow-audit steps 8–13, not a happy path. The close is covered by `Close/MonthEndCloseTest` and the flow audit.

**Run it locally** (Postgres from docker compose; the database is wiped, A-147):
```bash
DB_DATABASE=erp_test_c scripts/e2e.sh     # or: DB_DATABASE=erp_test_c npm run test:e2e
```
`scripts/e2e.sh` (D-49):
- runs `migrate:fresh --seed` and `php artisan erp:demo` on that database, and builds the frontend if `public/build/manifest.json` is missing;
- starts `php artisan serve` on `E2E_PORT` (default 8771) and a queue worker;
- waits until `http://127.0.0.1:<port>/login` with `Host: nonlife.localhost:<port>` answers 200;
- runs the test against `http://nonlife.localhost:<port>` (Chrome resolves `*.localhost` to loopback);
- stops both processes by PID, child processes included, and exits with the test's status.

Options:
- `CHROME` picks another Chrome binary.
- `E2E_TENANT` and `ERP_ADMIN_PASSWORD` as for the demo.
- `E2E_SKIP_SEED=1` reuses a seeded database.
- Against a server you already run: `npm run test:e2e:run -- --base http://nonlife.localhost:8765`.

On failure, `storage/e2e/` (gitignored) holds:
- `failure-<role>.png` and the page URL, per signed-in role;
- `trace-<role>.zip` (`npx playwright-core show-trace storage/e2e/trace-branch-manager.zip`);
- `console.log`, with the failing step, the 5xx responses and the browser console;
- `logs/server.log`, `logs/worker.log` and `logs/laravel-tail.log`.

A full run takes about 40 seconds after seeding. On the final code it ran green twice in a row against `erp_test_c` on port 8771, after the full Pest suite (1357 passed). A deliberately wrong expected status failed with exit 1 and left the evidence above.

**CI:** the `e2e` job in `.github/workflows/ci.yml`:
- ubuntu-24.04, its own Postgres 17 service, PHP 8.4 (with pcntl, so `serve` stops its child on SIGTERM), Node 24;
- `composer install`, `npm ci`, `npm run build`, `scripts/ci/prepare-database.sh`, then `scripts/e2e.sh` with `DB_DATABASE=erp` and `CHROME=/usr/bin/google-chrome` (preinstalled, nothing downloaded);
- on failure it uploads `storage/e2e/` and `storage/logs/laravel.log` as `e2e-evidence`.

Make `e2e` a required status check next to `backend` and `frontend`. The YAML was checked with PyYAML: jobs frontend, backend, e2e.

**Files:** `tests/e2e/happy-path.mjs`, `tests/e2e/lib.mjs`, `scripts/e2e.sh`, `package.json` scripts `test:e2e` and `test:e2e:run`, `.gitignore` (`/storage/e2e`), `.github/workflows/ci.yml`. No application code, migrations or permissions changed.

**Remaining:**
- The demo story is dated August–September 2026 with periods to June 2027. After June 2027 the test refuses to start until the demo story moves forward (A-148).
- The exit checklist row can move from gap to done once the job has run green on the hosted CI.

### Small fixes G1–G4 — done
Small fixes found by the flow audit, one commit each (`fix(…): Gn – …`); no design changes or features.

- **G1 (claims):** the release drawer shows the bank account the payment is paid from as read-only text, "Set when the release was requested", instead of a "Pay from" select the release endpoint never read. Each payment carries `pay_from`. Which bank pays is unchanged: the account named when the release is requested, else `bank_main`.
- **G2 (authorization):** a user whose only role is scoped to a branch now opens the policy, receipt (list, new receipt, receipt page), claim, quotation and proposal screens.
  - Lists and pickers show only their branches (entity roles: the whole entity).
  - Another branch's record page or document download is 403.
  - Actions keep their per-branch checks.
  - How: `PermissionChecker::authorizeArea` returns an `AreaReach`, and `AreaReach::constrain` filters the queries (D-43, A-136).
  - Not branch-filtered: finance screens (suspense, refunds, agent cash, cheques, dunning, allocation workbench), the referral queue, Home queues and search.
- **G3 (roles):**
  - `reports.regulatory` for the finance manager and CFO, so Part A step 14 (regulatory exports) can now run as the finance manager instead of the auditor.
  - `policy.endorse` for the branch manager only (A-137, verify).
  - `commission.pay` for the accountant only (A-138, verify); the finance manager approves. No template holds both sides of commission.approve ✕ commission.pay.
  - No SoD rule involves the other two.
  - Migration `2026_09_30_000043_grant_role_template_permission_gaps` adds all three to the template roles of existing tenants. Roles rebuilt under other codes need them added in Admin → Roles.
- **G4 (policies):** Pest case in `PolicyIssueFromProposalTest`: an endorsement that empties the motor chassis number (required only from the proposal, X7) is refused.
  - The service throws `RiskInputsInvalid` `{chassis_no: REQUIRED}`.
  - The live re-rating returns the field error.
  - The posted endorsement is refused with nothing written.
  - With the chassis number kept, the endorsement posts.
  - No bug found.
- **Tests:** `BranchScopedPagesTest`, `RoleTemplateGapsTest`, a payout case in `CommissionPayoutTest`, a props assertion in `ClaimSettlementFlowTest`. `RoleAdministrationTest` now expects the accountant role's 6 permissions (commission.pay added).
- **Found, not changed:**
  - Receipt and claim numbers are not branch-coded by default (`erp.numbering.formats` has no `receipt` / `claim` entry), so the first receipts or claims of two branches get the same number and the second insert fails on the unique number. A tenant with a second branch needs those formats set; `BranchScopedPagesTest` sets them.
  - The web endorse-risk refusal message still reads "The risk details are not valid (chassis_no: REQUIRED)." The drawer stops an empty required field before posting, so people do not normally see it.

### UX U1–U3 — date picker, chart of accounts, setup chart step — done
Product owner feedback: dates could only be typed; there was no screen to add an account; flows should be easier to follow. One commit each (`feat(ux)` / `feat(accounting)`).

- **U1 (date picker):** `DateInput` keeps typing (`14 Sep 2026`, `t`, `+3`, `12/09/2026`), the display and the `Y-m-d` model, and adds a calendar button inside the field (D-62).
  - Opening: the button, or Alt+ArrowDown in the field.
  - Keys: arrows move a day or a week, PageUp/PageDown a month, Shift+PageUp/PageDown a year, Home/End the week. Enter picks; Escape closes and focus returns to the field.
  - Today is ringed and the selected day filled. Month and year selects jump; "Today" picks today.
  - `min` / `max` disable days and refuse a typed date outside them. They are used where the server already checks: the report range filter ("to" not before "from") and the new producer's licence dates (issued no later than today, valid today).
  - The week starts on `erp.ui.week_starts_on`, Sunday by default (A-165). Month names are in Bangla when the user reads the help in Bangla (A-166).
  - Every date field already used `DateInput`; the grep found no raw date input. The one exception is the setup wizard's "first month" `type="month"`, which picks a month, not a date, and was left as it is.
- **U2 (chart of accounts):** Accounting → Chart of accounts (sidebar, secondary).
  - Who: it opens for `accounting.view_journals` or `accounting.manage_coa`. Every change needs `accounting.manage_coa` in the entity.
  - The list: an indented tree by code with type, normal side, posting (postable / heading / control with its subledger), the account roles mapped today, currency, balance on the normal side (primary book, base currency) and status.
  - Add account: a drawer with code, name, type (normal side suggested), under, postable, control with subledger, and currency. It goes through the import's rules, now `ChartOfAccountsRules`, shared with the CSV import (D-63). Audited `account.created`.
  - Edit: name, parent, postable, type and side, within A-167 and A-169. Audited `account.updated` with only the changed fields.
  - Deactivate and reactivate: deactivating needs nothing left on the account (A-168); both are audited. The inspector says why Deactivate is off.
  - Links: the manual journal's "New account" drawer and its hint for other users, and Account roles (toolbar and remap drawer), all point to the screen.
  - Tests: Pest `ChartOfAccountsScreenTest` (7), Vitest `chart-of-accounts.test.ts`.
- **U3 (setup wizard):** the chart step now has:
  - one sentence (template → adjust → create)
  - "Add account" above and below the table; the new row's code field is focused
  - "Import from a CSV file instead"
  - a lock on accounts the accounting needs
  - the normal side following the type
  - each row's errors under that row, with a count above the table
  - a notice with a button when "Company and branches" or "Fiscal year and currency" is not saved yet (the flow audit hit "Save the company first" only after filling in the table)
  - once the chart is saved: the step, the summary rail and the saved message link to Chart of accounts
  Sweep: the button that leaves a step without saving says "Skip for now", or "Continue" once the step is saved (it said "Cancel"; `FormLayout` has a `cancelLabel`).
- **Screenshots:** before and after, light and dark, 1366×768, in `storage/ux-screenshots/ux-u1-u3/{before,after}/` (gitignored; there is no `docs/screenshots` convention). The "before" set was taken from the main checkout's build.
- **No** migration, permission or role template change. Shared props gain `calendar.week_starts_on`.
- **Found, not changed:**
  - A new tenant's company step opens with an empty short code.
  - `vendor/bin/phpstan analyse` reports two errors in `tests/Pest.php` (`fakePdfRenderer`'s `ArrayObject` generics). They are not from this work.

### 2.1b — Business clock, close pending documents and early lock — done
The product owner decided the three flow-audit questions (CQ-H2, CQ-C4, CQ-C5); this slice applies them. Decisions D-54–D-56, assumptions A-151–A-156.
Commits `2.1b-1` (platform), `2.1b-2` (close pending documents), `2.1b-3` (early lock), then docs.

- **2.1b-1 Business clock (CQ-H2, D-54):**
  - `App\Modules\Platform\Tenancy\BusinessClock` — `today(?entityId)`, `now(?entityId)`, `timezone(?entityId)`. The zone is `legal_entities.timezone` (migration `2026_10_01_000211_add_timezone_to_legal_entities`, default Asia/Dhaka, backfilled from `tenants.timezone`); without an entity the first entity, then the tenant, then `erp.business_clock.default_timezone` (A-151). A business date is midnight UTC of the local date, so it compares like dates read from the database.
  - Every business "today" in page controllers, services and jobs reads it (52 files): X1/X2 form defaults, value/approval/payment dates, report and trial balance "as of", renewals T-45, dunning, quotation, cover note and licence expiry, approval and underwriting limit dates, distribution and portal screens. Jobs read today inside the tenant loop (dunning per entity). Technical timestamps stay UTC.
  - Nightly jobs are scheduled in the default business zone (A-152). The setup wizard's company step has a time zone select (audited). Inertia shares `businessToday`; `t`/`+3` in date inputs and the allocation workbench's date use it (A-156). PDFs' "generated at" uses the clock's zone.
  - `tests/Architecture/BusinessClockTest.php` scans the PHP tokens of every module's Http, Application and Infrastructure code and app/Http and forbids `CarbonImmutable::today()`, `today()`, `now()->toDateString()`-style reads and `'today'` date strings; only `BusinessClock` is allowed.
- **2.1b-2 Pending documents at close (CQ-C4, D-55):**
  - Tagged kernel contract `PendingCloseDocuments` → `PendingDocumentsQuery`. Kernel: manual journals not posted, pending reversal requests, accounting events not posted. Insurance: claim payments not paid, refunds requested. Dates per A-153.
  - The trial balance task soft-locks with a warning and a count; `FiscalPeriodService::lock` refuses `PERIOD_HAS_PENDING_DOCUMENTS` with `details.pending` (JSON) until each is approved, rejected or moved. No override.
  - "Move to next period" for a manual journal pending approval: `ManualJournalService::moveToNextPeriod`, by someone who could approve it now (`ApprovalService::assertMayDecideCurrentStep` / single checker, never the maker), only into the immediately following open period (`NEXT_PERIOD_NOT_OPEN`), audited `journal.moved_to_next_period` (A-154). Route `POST /close/journals/{journal}/move-to-next-period`.
  - close/Run and close/Index list the documents (`components/close/PendingDocuments.vue`) with links and the move button; the lock stays disabled with the reason.
- **2.1b-3 Early lock (CQ-C5, D-56):**
  - Soft lock from the period's last day on the entity's clock (`PERIOD_LAST_DAY_NOT_REACHED`; the trial balance task is blocked with that reason before). Lock only after the period has ended (`PERIOD_NOT_ENDED`), except a holder of `periods.reopen` (the CFO, A-155) with a written reason (`EARLY_LOCK_REASON_REQUIRED`), recorded on `period.locked` with `early_lock: true`. The close checklist asks the CFO for the reason (sent as the lock task's note) and shows others the date the lock opens; Index says when each month can be soft-locked and locked.
- **Reason messages:** `PERIOD_HAS_PENDING_DOCUMENTS`, `NEXT_PERIOD_NOT_OPEN`, `PERIOD_LAST_DAY_NOT_REACHED`, `PERIOD_NOT_ENDED`, `EARLY_LOCK_REASON_REQUIRED`.
- **Tests:** `Platform/BusinessClockTest` (frozen at 2026-09-30 19:30 UTC = 1 Oct 01:30 Dhaka: forms, trial balance, `businessToday`, September earned by the nightly job only after Dhaka midnight, UTC audit timestamps, wizard time zone), `Architecture/BusinessClockTest`, `Close/PendingDocumentsAtCloseTest`, `Close/EarlyLockTest`; vitest `businessDate`, `close-pending`.
- **Existing tests changed to the new rules (equivalent or stricter):**
  - `QuotationWorkbenchTest`, `CoverNotesTest`: the expiry boundary moved from 23:00 / 00:30–01:00 UTC to 17:59 / 18:00 UTC — exactly midnight in Dhaka, the new day boundary.
  - `FiscalPeriodServiceTest`, `MonthEndCloseTest`, `LockRefusesUnreconciledPeriodTest` (beforeEach) and one case each in `SubledgerReconciliationTest` and `CloseReportsJournalsPagesTest`: they soft-lock and lock September, which ran on the real date (14 September, before month end) and is now refused. They run on 1 October 10:00 UTC instead; every assertion is unchanged. The early and last-day refusals themselves are covered by `EarlyLockTest`.
  - `PendingDocumentsAtCloseTest` freezes 2 October for the same reason. Tests that lock July or August, or set period status directly, are unaffected.
- **Not changed / follow-ups:**
  - `scripts/flow-audit.mjs` (not edited) locks September on the 14th; the lock is now disabled there until 1 October or for a CFO with a reason, so a re-measured step 13 shows "lock disabled". `docs/flow-audit.md` "Observations to confirm" marks the three observations resolved.
  - The E2E happy path (A-148) does not lock a month, so it is unaffected.
  - Periods of other entities in a multi-entity tenant follow their own entity's zone; pages that do not name an entity use the first entity (A-151).
- **Result:** Pest 1,391 passed, Vitest 352 passed, PHPStan 0 errors, vue-tsc green.
### Follow-ups H1–H3 — done
Follow-ups to G1–G4 and 2.0d, one commit each (`fix(…): Hn – …`).

- **H1 (authorization): branch filtering on the remaining screens** (D-59, A-136 updated, A-159, A-160).
  - A user whose only role is scoped to a branch now opens these screens, sees only their branch, and gets 403 on another branch's record:
    - suspense (items and the installment picker);
    - the allocation workbench (another branch's receipt is 403; candidates from the user's branches);
    - refunds (refundable policies and the list; "Request" and "Release" show for holders in any scope, and the service checks the branch);
    - agent cash (the agents of the user's branches with their whole position, A-159);
    - the cheque register and dunning notices;
    - the underwriting referral queue and the cover notes queue;
    - the expiry register and its branch filter;
    - printing and downloading quotation and cover note PDFs; the policy and receipt print actions also check the record's branch now.
  - Home work queues and sidebar badges count only rows the user could open: installments due, lapsing policies, quotes, unallocated receipts, claims awaiting reserve, claims to settle, payments to release.
  - Global search limits policies, claims and receipts; the policy and installment lookups are limited and now open to branch-scoped holders (before, every lookup needed a tenant-wide permission). Customers, producers and payees are not branch-bound (A-160).
  - Tenant-wide and entity-wide users see what they saw before. Reports, close checks and the API keep calling the shared queries without a reach (everywhere).
  - The receipt page's "Bounce" action now checks `receipt.allocate` on the receipt's branch, as `ChequeBounceService` does.
  - Commission statements are entity-level (no branch) and unchanged.
  - Tests: `BranchScopedQueuesTest` (new, 8), with every screen group checked for a branch-scoped user and a tenant-wide one. Suite at this commit: 1,383 Pest tests, 348 Vitest tests.
