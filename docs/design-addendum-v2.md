# Insurance ERP — Design Addendum v2

Tags as in `docs/design-package-v1.md`: **DECISION** (settled), **DECISION (proposed)** (new in this addendum, settled only when the review
accepts it; accepted items get a D-id in `docs/DECISIONS.md` when their slice applies them), **INVARIANT** (enforced in code and tests, or to
be, for proposed items), **ASSUMPTION** (proceeding on this; A-ids are in the `docs/PROGRESS.md` register), **OPEN** (unknown — do not
invent), **MVP**, **LATER**.

Readers: the engineers and the customer's reviewers who know design v1. Part A says what changed since v1, as built on `main` after
Phase 1, Distribution D1–D9, onboarding S1–S6, fixes F1–F6, Phase 3 R1–R10 and flow fixes X1–X12. Part B is the Phase 2 design
(Finance + People) that `docs/phase-2/kickoff.md` asks for; it is a proposal for review, and the slice list in kickoff §2 is not final
until it is accepted. Questions for the customer are in `docs/phase-2/customer-questions.md`, referenced here as **CQ-xx**.

Sources of truth when this document and the code disagree: the code and its tests, then `docs/DECISIONS.md`, then the `docs/PROGRESS.md`
slice notes. This addendum does not change any of them.

---

# Part A — What changed since design v1 (as built)

## A.1 Stack and conventions (v1 §0)

| Item | v1 | As built | Ref |
|---|---|---|---|
| Web | Vue 3 + Inertia 2 + TS, Tailwind + shadcn-vue | Vue 3.5 + Inertia 3 + TS 5.9, Tailwind 4, CoreBari theme tokens and the components of `docs/ux-design-brief.md` (QueueView, DataTable, ObjectPage, Drawer, JournalPreviewDialog) | 0.6, U1–U10, `docs/theme.md` |
| Auth | Zitadel OIDC via Socialite | Fortify (email + password, TOTP two-factor, tenant resolved before sign-in); Zitadel is LATER and not built | CONTEXT.md, 1C.7 |
| API tokens | Sanctum | Sanctum, only for producer portal users (`users.kind = portal`); `personal_access_tokens` is a tenant table | D-16, D9 |
| DB schemas | one schema per module | one schema; module ownership is by namespace and the architecture test, not by Postgres schema | migrations |
| Tenant setting | `SET LOCAL` per request | session-level `set_config`; transaction-mode PgBouncer unsupported | D-02 |
| Balance trigger | deferred | immediate; lines are inserted before the status flips to posted | D-01 |
| Job uniqueness | `ShouldBeUnique` on the posting job | `WithoutOverlapping` keyed by event id (a lost dispatch had blocked re-delivery) | D-07 |
| Child tables | some without `tenant_id` | every tenant table has `tenant_id` + forced RLS, no exceptions; `TenantIsolationEveryTableTest` fails for a new tenant table until it holds rows in the scenario | D-08, 1C.6 |
| PDFs | browsershot (headless Chromium) | the Chrome binary in headless mode through Symfony Process, fonts embedded | D-34 |
| XLSX | — | dependency-free `Platform\Exports\XlsxWriter` | D-28 |
| Time | tenant `timezone` | column exists (Asia/Dhaka) and is used for the "generated at" line on PDFs; request-time "today" defaults and the scheduler use the application clock (UTC). Which clock applies is **OPEN** (CQ-H2) | flow audit |

## A.2 Context map as built (v1 §1)

```
PLATFORM   Tenancy · Identity (Fortify, Sanctum portal tokens) · RBAC/SoD · Approvals (+ role steps, per-approval steps, limits screen)
           Audit · Documents (store, templates, generation) · Notifications (log adapter) · Numbering · Tax rates
           Imports · Exports (XLSX) · Setup progress · Preferences
    ▲
ACCOUNTING COA · Periods · Books · Dimensions · AccountingEvent → PostingEngine → Journal · Reversal · Manual journals
           Subledger controls · Reconciliation (tagged SubledgerReconciler) · Close (tagged CloseTaskCheck) · Account role mapping · Setup
    ▲
INSURANCE  Party · Product (+classes, risk schemas, coverages) · Rating · Quotation · Underwriting · CoverNote · Policy
           Collections · Commission (subledger, statement run) · Claims · Renewal · Reports
DISTRIBUTION (new context, D-12)  Channels · Producers · Licences · Hierarchy · Compensation schemes and engine · Advances · Targets/incentives · Portal
FINANCE    Bank only
PEOPLE     not built        COMPLIANCE  not built
```

Dependency rules enforced by `tests/Architecture/DependencyTest.php` (INVARIANT, unchanged in spirit from v1 §1):
- Platform uses nothing above it; Accounting uses no business context.
- Insurance, Finance, People use Platform and `Accounting\Application` only; never journal models, `PostingEngine`, posting internals or `ReversalService`.
- Insurance does not use Finance's Bank domain; Finance does not use Insurance domains.
- Distribution uses Platform and `Accounting\Application` only; no other context uses Distribution's Domain, Infrastructure or Http (D-12).
- `JournalWriter` is used only by `PostingEngine`, `ReversalService` and `ManualJournals`.
- Composition controllers that need two contexts live in `App\Http` (for example `App\Http\Distribution`, `App\Http\Documents`, `App\Http\Setup`, `App\Http\Portal`).
- Extension points are container tags implemented by business contexts: `SubledgerReconciler`, `CloseTaskCheck`, `DocumentDataProvider`, `ApprovalHandler` (+ optional `DescribesApprovalSubject`).

## A.3 Phase 0/1 as built against v1 (short)

Phase 1 detail is in `docs/PROGRESS.md` and `docs/phase-1/exit-checklist.md`. Differences a v1 reader would otherwise miss:

| v1 | As built | Ref |
|---|---|---|
| §2.2 `accounting_events` inserted `received` | inserted `queued`; `received` kept for a future pre-validation stage | D-03 |
| §4.6 reserve decrease as a mirror of `CLAIM_RESERVED` | own event `CLAIM_RESERVE_ADJUSTED` (signed delta); reserve adjustments post system journals linked through `claim_reserves` versions, not `kind=adjustment` journals | D-04, 1B.1 |
| §4.10 per-employee payroll lines | `PAYROLL_POSTED` summary rule; not emitted by any module yet | D-05 |
| §4.4 OPEN VAT on cancellation | flag `refund_tax_on_cancellation`, default true | D-06, A-1 |
| failure codes | trigger rejections map to `PERIOD_CLOSED`, `PERIOD_MISSING`, `UNBALANCED_JOURNAL`, `EMPTY_JOURNAL`, `IMMUTABLE_JOURNAL`, no retry | D-10 |
| §5.2 approvals | reversals and manual journals always maker ≠ checker; approval policy when one matches | D-11 |
| §7.3 SoD rules | `sod_rules.applies_to` `user` or `object`; "(same claim)" rules are object rules checked on the object's audit trail | 0.4 |
| §2.4 `premium_earning_ledger` unique(policy, period) | unique(policy, period, kind), kinds `scheduled`, `cancellation_catch_up` | 1A.4 |
| §5.7 lock guard | `lock` recomputes every subledger variance under the period row lock; postings read the period `FOR SHARE`, so a lock waits for postings in flight | review pass, 1C.6 |
| §5.7 close permissions | no close permissions; each task uses its owner's working permission; CFO approval on lock not built (Phase 1 Q6) | 1A.9 |
| §2.4 `agents` | replaced by `producers` keeping ids; `agent_id` columns and `dim_agent` keep their names and mean producer | D-13 |
| §9.2 close tasks built | 1–6, 8, 13–16 (task 5 claims added in 1B.2) | 1B.2 |
| spec §4 items outside 1A/1B | built in 1C: commission payout (approve → pay), cheque register and bounce, agent cash and deposits, dunning/grace/auto-lapse, multi-payer | 1C.1–1C.5 |

## A.4 Distribution (D1–D9)

Design: `docs/distribution-module-design.md` §7 MVP, built as specified except where noted. Context `App\Modules\Distribution` (D-12).

**Model.**

| Table | Purpose | Notes |
|---|---|---|
| `channels` | agency, bdo, broker, bancassurance, partner, direct | standard channel per producer type created on first use |
| `producers` | agent, agency_org, bdo, broker, partner; applicant → active → suspended → terminated; `employee_id`, `portal_user_id` | Phase 1 `agents` migrated with their ids (D-13); `employee_id` has no foreign key (no employees table yet) |
| `producer_licences`, `producer_licence_alerts` | licence per authority (IDRA), class life / non_life / both, dates, status; one alert per licence and threshold | alerts queued on the outbox, not delivered |
| `hierarchy_levels`, `producer_hierarchy` | levels per scheme; effective-dated parent, `[from, to)` | `producers.parent_agent_id` dropped |
| `compensation_schemes`, `compensation_rules` | mode commission / salary_incentive / hybrid / none; `compliance_profile`; rules by product, type, level, basis, policy years, rate, override, cap, persistency, flags | `product_versions.compensation_scheme_id` |
| `compliance_exceptions` | why a trigger paid nothing | once per trigger, producer, reason |
| `commission_statements` (extended) | the design's `producer_statements` (D-15): draft status, split columns, `period_end`, `paid_via` | one per producer per period |
| `producer_advances`, `producer_advance_recoveries` | advances and their recovery per statement | |
| `targets`, `incentive_plans`, `incentive_awards` | targets by producer / branch / channel, tiered plans, one award per plan, producer and period | |
| `commission_entries` (extended) | `scheme_id`, `rule_id`, `beneficiary_role` direct / override, `level_code`, `hierarchy_snapshot`; `policy_id` null for bonus entries | |
| `personal_access_tokens`, `users.kind` | portal tokens (D-16) | |
| `products.insurance_class` | life / non-life (A-17) | |

**Invariants.**
- INVARIANT one active parent per producer per date (exclusion constraint); no hierarchy cycle on the change date or any later change date (`AGENT_HIERARCHY_CYCLE`).
- INVARIANT a commission entry carries the hierarchy snapshot of its trigger date; later tree changes never change a payout.
- INVARIANT Σ commission rates on a trigger ≤ the compliance cap for product and policy year; a breach blocks the calculation (`COMPLIANCE_CAP_EXCEEDED`), never the policy (D-14).
- INVARIANT no new business for a producer that is not active or has no valid licence of the product's class on the issue date (`PRODUCER_NOT_ACTIVE`, `LICENCE_REQUIRED`); renewals are not new business (A-14, A-133).
- Statement net = gross − withholding − advances recovered ≥ 0; commission.approve ✕ commission.pay on the statement.

**Posting rules added** (golden fixtures in brackets): `PRODUCER_ADVANCE_ISSUED` DR producer_advances / CR bank_main (05d); `PRODUCER_ADVANCE_RECOVERED` (05e); `COMMISSION_PAYOUT_TO_PAYROLL` DR commission_payable / CR salary_payable (05f); `COMMISSION_PAYOUT_TO_AP` DR commission_payable / CR accounts_payable (05g); `INCENTIVE_BONUS_EARNED` DR commission_expense / CR commission_payable / CR commission_withholding_payable (05h). Compensation golden fixtures `tests/Fixtures/compensation/01–06`. New account roles `producer_advances`, `accounts_payable`.

**Payout route.** `paid_via` bank (Phase 1), payroll or ap (A-22: producer with an employee record → payroll, else by type). The payroll and AP routes post their event and write outbox messages `CommissionPayrollEarning` / `CommissionPayableToAp`. **Nothing consumes those messages**: the outbox relay only relays `PostAccountingEvent` rows, so they stay unrelayed. Phase 2 must consume them (B.11).

**Permissions.** No new permission codes; Distribution reuses `agent.manage`, `commission.manage_plans`, `commission.approve`, `commission.pay`, `reports.financial`, `reports.regulatory`. `commission.pay` is in no role template (the demo gives it to a local-only user).

**UI.** Producers queue (needs-attention column), producer page (Overview · Hierarchy · Compensation · Production · Statements · Documents · Audit), hierarchy tree with drag or keyboard transfer and an effective date, scheme editor, statement run workbench (prepare → approve → pay with journal preview), targets grid. Portal: REST under `/api/portal` with OpenAPI (`docs/api/producer-portal.openapi.json`), no portal UI.

**Design note OPEN items:** see A.10.

## A.5 Onboarding (S1–S6)

No business rules were added (D-17): the wizard calls the service that owns each piece of data.
- `setup_progress` (tenant table) records the wizard steps: company and branches → fiscal year and currency → chart of accounts → first product → users and roles → approval limits (F3) → done.
- New thin setup services: `Platform\Setup\CompanySetup`, `Accounting\Application\Setup\FiscalYearSetup`, `ChartOfAccountsSetup` (template `resources/setup/chart-of-accounts/non-life-insurance.csv`, registers control accounts in `subledger_controls`, and since F4 commits only when every role used by rules in force is mapped), `Platform\Tax\TaxRateSetup`.
- `php artisan erp:tenant` (blank tenant) and `php artisan erp:demo` (Part A story tenant `nonlife`).
- Help panel "How this works" (EN/BN Markdown per module), guided tour (nine steps since R10b, state in user preferences), captions under every journal line from the account role (`resources/help/roles.*.md`), empty states with one action.
- Found and fixed: no role template held `accounting.manage_coa` (A-28); a chart imported by file never registered its control accounts.
- ASSUMPTION A-27 (who does each setup step), A-28.

## A.6 Fixes F1–F6

| Fix | Model / rule change | Ref |
|---|---|---|
| F1 | number format per document type in `erp.numbering.formats`; policy `{prefix}-{branch}-{fy}-{seq}`; issued numbers never rewritten | 1A/F1 |
| F2 | `Platform\Documents\DocumentStore` over `stored_documents` (append-only, content-addressed per tenant); attach and download on claims, receipts, policies (later proposals); audited on the object | D-25, A-52, A-53 |
| F3 | Approval limits screen; `ApprovalPolicyService` (effective-dated, never rewritten, overlapping policies refused); steps may name a role (D-26); permission `platform.manage_approvals`; setup-wizard defaults | D-26, A-54, A-55 |
| F4 | Account role mapping screen; remap from a date, never over posted days; unmapped roles banner | D-27, A-56, A-57 |
| F5 | Unearned premium report reconciled to the control; premium register totals by class (product line of business) and branch | A-60, A-61 |
| F6 | Agency register CSV/XLSX from the producers queue | D-28, A-62 |

## A.7 Phase 3 — rating, quotation, underwriting, cover notes, documents, renewals (R1–R10)

Design: `docs/rating-quotation-documents-design.md` §7 MVP, all built. Life, health, fleet, co-insurance, sanctions and tariff import stay LATER.

### A.7.1 Model

| Area | Tables / columns | Notes |
|---|---|---|
| Product | `product_classes` (global, D-18: motor, fire, marine_cargo, misc active; marine_hull, engineering, health, life reserved `later`); `product_versions` += `class_code`, `risk_schema`, `duty_profile`, `document_set_id` (stored only), `allow_short_period`, `min_premium_minor`, `recognise_at`, `allow_credit_issue`, `rating_plan_id`, `endorsement_uses_current_tariff`; `coverages` (tenant table, D-19) | Phase 1 JSON `coverages` list untouched; a version with a class is "rated" (A-115) |
| Risk schema | field list `{key, labels EN/BN, type, required, required_at quote\|proposal (X7), default (X7), options, min, max, max_length}` | inputs normalised to integers / minor units, never floats |
| Rating | `rating_plans` (draft → approved → active → retired, D-21), `rate_tables`, `rate_table_rows`, `rating_steps`, `duties` (vat / stamp / levy, `verify`) | own ExpressionLanguage instance, integer units (D-20) |
| Quotation | `quotations` (draft / issued / expired / converted / declined; frozen `rating_result`; `risk_keys`; producer eligibility; `renewal_of_policy_id`) | D-30, D-40 |
| Underwriting | `proposals` (one per quotation; KYC; underwriting status; referral reasons; manual loading), `underwriting_limits` (role × class × sum insured, effective-dated); `approvals.steps`, `approvals.policy_id` nullable | D-31, D-32 |
| Cover note | `cover_notes` (active / superseded / cancelled / expired; one active per proposal) | posts nothing (D-33) |
| Policy | `policies` += `quotation_id`, `proposal_id`, `cover_note_id`, `risk_inputs`, `risk_keys`, `rating_result`, `rating_plan_code`, `rating_plan_version`, `special_terms`, `stamp_duty_minor`, `issue_basis`, `premium_received_reference`; `policy_transactions` += `stamp_duty_delta_minor`, `rating_result`, `rating_basis` | renewal chain is `renewal_of_policy_id` (D-38) |
| Documents | `document_templates` (versioned, one active per code × class × locale), `generated_documents` (append-only, versioned per object and code) | D-35 |
| Renewals | `expiry_register` (one row per policy), `renewal_notices` (one per policy and offset) | D-42 |
| Notifications | `notifications` (one per idempotency key and channel; log adapter) | D-41 |

### A.7.2 Invariants added

- INVARIANT one active rating plan per class per date (exclusion constraint); approved, active and retired plans, their tables, rows and steps are immutable (trigger `RATING_PLAN_IMMUTABLE`); a change is a new version.
- INVARIANT rating is deterministic: the same plan version, product version, date, inputs and coverages give the same result; `inputs_hash` has no database ids; `RatingEngine::rerate` reproduces a stored result after the tariff changed (D-32). No floats in `Insurance\Rating` (architecture test).
- INVARIANT a quotation out of draft never changes terms, rating, premiums, dates or number (trigger `QUOTATION_FROZEN`); only draft → issued | declined and issued → expired | declined | converted.
- INVARIANT a policy's `rating_result`, `risk_inputs`, plan code and version, `special_terms`, `quotation_id`, `proposal_id`, and an endorsement transaction's premium deltas and rating are frozen once written (`POLICY_RATING_FROZEN`, A-123). Re-rating creates an endorsement.
- INVARIANT gross premium = net + tax + stamp duty on every policy (CHECK).
- INVARIANT one active cover note per proposal; a cover note posts no accounting; `recognise_at = cover_note` is refused (`RECOGNITION_AT_COVER_NOTE_NOT_SUPPORTED`) until a posting design exists (D-33).
- INVARIANT one active document template per (code, class, locale); active and retired bodies immutable; generated documents and stored documents append-only; regeneration is a new version.
- INVARIANT at most one open renewal quotation per policy; one register row per policy; one notice per policy and offset; one notification per idempotency key and channel.
- Template bodies pass a whitelist guard before Blade compiles them (A-100).

### A.7.3 Posting

| Change | Detail | Ref |
|---|---|---|
| `POLICY_ISSUED` and `POLICY_ENDORSED` version 2 | fourth line `stamp_duty_payable` from `payload.stamp_duty ?? 0` / `stamp_duty_delta ?? 0`; events without stamp duty post exactly as version 1 | D-37, fixtures 01d, 01e |
| New account role `stamp_duty_payable` | must be mapped in existing tenants before issuing rated policies with stamp duty | D-37 |
| Duties mapping | net → unearned premium; VAT and levies → `tax_minor` / `premium_tax_payable`; stamp duty → `stamp_duty_minor` / `stamp_duty_payable`; cancellation reverses VAT only | A-118 |
| No events | rating, quotation, proposal, referral, cover note, documents, renewal register and notices post nothing | design §2 |

Rule catalogue now: 28 event types in `resources/posting-rules` (30 files, two of them version 2), 31 golden fixtures, plus 6 compensation and 3 rating golden fixtures.

### A.7.4 Approvals

- `proposal_referral` is an approval object type (D-31). Its steps come from underwriting limits, stored on the approval (`ApprovalService::requestWithSteps`); an approval policy configured for `proposal_referral` still wins when it matches (A-85).
- The handler adds `underwriting.decide` on the branch, SoD on the proposal and the decider's own limit (`UNDERWRITING_LIMIT_EXCEEDED`).
- Configured object types (`erp.approvals.object_types`): claim_payment, claim_payment_release, journal, journal_reversal, claim_reopen, fiscal_period_reopen, proposal_referral. Refunds and commission payouts are maker ≠ checker only (no limits).

### A.7.5 Permissions, role templates, SoD

| Permission | Template(s) | Ref |
|---|---|---|
| `rating.manage_plans`, `rating.approve_plans` | Finance Manager, CFO | A-69 |
| `document.generate` | Branch Officer (+ Branch Manager) | A-101 |
| `document.manage_templates` | Tenant Admin | A-101 |
| `quotation.create` | Branch Officer (+ Branch Manager) | A-83 |
| `underwriting.decide` | Branch Manager, Finance Manager, CFO | A-86 |
| `underwriting.manage_limits` | Tenant Admin | A-87 |
| `cover_note.issue` / `cover_note.cancel` | Branch Officer (+ Manager) / Branch Manager | A-94 |
| `renewal.manage` | Branch Officer (+ Branch Manager) | A-126 |

SoD object rules added: `rating.manage_plans` ✕ `rating.approve_plans` (SOD7), `quotation.create` ✕ `underwriting.decide` (SOD8). Earlier additions: `platform.manage_approvals` (A-54). Gaps: no template holds `policy.endorse` (Phase 1 gap) or `commission.pay`; the Finance Manager has no `reports.regulatory` (flow audit).

### A.7.6 Numbering

| Document | Prefix | Scope | Format |
|---|---|---|---|
| Policy | POL | entity + branch | `{prefix}-{branch}-{fy}-{seq}` (F1) |
| Quotation, proposal, cover note | QUO, PRP, CVN | entity + branch | `{prefix}-{branch}-{fy}-{seq}` |
| Receipt, claim, agent deposit | RCT, CLM, ADP | entity + branch | `{prefix}-{fy}-{seq}` |
| Commission statement | CST | entity | `{prefix}-{fy}-{seq}` |
| Endorsement document | — | — | `<policy number>/E<n>` (A-104), not a sequence |

**Finding (not changed; needs a fix slice):** receipts, claims and agent deposits reserve numbers from a per-branch sequence but their format has no `{branch}` token, while `receipts`, `claims` and `agent_deposits` are unique on (tenant, number). The first receipt of a second branch in a fiscal year gets the same number as the first receipt of the first branch and is refused by the unique constraint. Found by reading `DocumentNumberer`, the three services and their migrations while writing this addendum, not reproduced by a test (docs-only slice): the demo tenants have one branch (HO), and the tests that add a second branch record no receipt, claim or deposit there. The fix is a format change (branch-coded) or entity-level sequences; which one is a customer choice because receipt numbers are printed and checked by regulators (CQ-E5).

### A.7.7 Jobs added

| Job | Queue | Schedule (UTC clock) | Idempotent by |
|---|---|---|---|
| QuotationExpiryJob | batch | 00:15 | status CAS, also run before queues are read |
| CoverNoteExpiryJob | batch | 00:20 | status CAS |
| RenewalRunJob (register → offers at T-45 → notices) | batch | 00:30 | register unique, one open renewal quotation, notice unique |
| LicenceExpiryAlertJob (D2) | batch | 01:45 | alert unique per licence and threshold |

### A.7.8 UI flows

- Home → **New quote** (X4) → quote workbench (risk form from the schema, live breakdown EN/BN, product defaults to the last used, X6) → issue quotation → *Customer accepts: make proposal* → proposal page (KYC, *Enter risk details* for proposal-stage fields, X7) → submit → auto-approved or **Referrals** queue (approve, approve with loading, decline) → optional **cover note** → *Issue policy* with journal preview → "Record the premium receipt?" (X1).
- Policy page: Rating tab (frozen breakdown, special terms, endorsements before / after / charged), *Endorse* drawer re-rates live; Documents tab prints schedule, endorsement, receipt in English or Bangla.
- Renewals queue (expiry register with buckets, offer now, record not renewed, notices with PDFs); reports Expiry register and Renewal conversion.
- Admin: Tariffs (plan page with Tables grid, Steps, Duties, Diff, Timeline, Audit), Templates editor with live preview, Underwriting limits.
- Rated products cannot be quoted from Policies → New (typed premium only for unrated products).

## A.8 Flow fixes X1–X12

Flow fixes changed no posting rule, invariant, permission or role template. Model-visible changes:

| Fix | Change |
|---|---|
| X1 | receipt form prefilled from the policy (`/receipts/create?policy=`); branch and last channel defaults |
| X2 | today as the default on "now" dates (reported on, reserve, approval, paid on, close, manual journal and reversal) — on the application clock, see CQ-H2 |
| X3 | read-only check "may this user approve, within limits and SoD" to offer the next step; Home queues "Claims to settle", "Payments to release" |
| X4 | Home start actions |
| X5 | receipt header "Print receipt" |
| X6 | user preference `drafts.last-product` |
| X7 | risk schema keys `required_at` and `default`; submit refuses while a proposal-stage field is empty; duplicate keys work from the registration alone |
| X8 | `POST /lookup/payee`: party with role vendor or beneficiary, checked on `claim.approve` |
| X9 | `POST /lookup/producer`: party + producer + licence in one transaction (`agent.manage`); code prefixes `erp.distribution.producer_code_prefixes` |
| X10 | `POST /accounting/accounts`: one-row chart-of-accounts import (`accounting.manage_coa`) |
| X11 | account activity Source column (`App\Http\Pages\JournalSources`); statements link to each other |
| X12 | `GET /reports/{report}/export?format=csv\|xlsx` |

Found, not changed: "Pay from" in the claim release drawer is not read by the release endpoint; policy, receipt and receipt-create pages need an area permission held tenant-wide (a branch-scoped role gets 403); no Pest case for an endorsement clearing a proposal-stage field.

## A.9 Tenant tables added since the Phase 1 exit

Phase 1 exit had 57 tenant tables. Added: `channels`, `producers` (replaces `agents`), `producer_licences`, `producer_licence_alerts`, `hierarchy_levels`, `producer_hierarchy`, `compensation_schemes`, `compensation_rules`, `compliance_exceptions`, `producer_advances`, `producer_advance_recoveries`, `targets`, `incentive_plans`, `incentive_awards`, `personal_access_tokens`, `user_preferences`, `setup_progress`, `stored_documents`, `coverages`, `rating_plans`, `rate_tables`, `rate_table_rows`, `rating_steps`, `duties`, `document_templates`, `generated_documents`, `quotations`, `proposals`, `underwriting_limits`, `cover_notes`, `expiry_register`, `renewal_notices`, `notifications`. Global (no tenant, stated reason in `SchemaInvariantsTest`): `product_classes`.

## A.10 OPEN items: handled by an ASSUMPTION or still open

"Verify" = the default is a placeholder that the customer must confirm; the system runs on it until then. No customer answer is recorded in the repository for any item below.

### A.10.1 Design v1

| v1 OPEN | State | Handled by | Status |
|---|---|---|---|
| §0 carrier vs broker/MGA (final list 1) | **OPEN** | v1 §0 ASSUMPTION "non-life carrier" (no A-id); rule sets and Phase 1B assume a carrier | ask (CQ-A1) |
| §4.4 VAT refundable on cancellation / final list 2 tax on premium and refunds | ASSUMPTION | A-1 (VAT refunded), A-66 (every duty of the class applies), A-68 (VAT and levies on net premium), A-118 (stamp duty never refunded), duty rows `verify` | verify (CQ-B1, CQ-B2) |
| §7.3 approval thresholds / final list 3 thresholds and role mapping | ASSUMPTION for thresholds; **OPEN** for role mapping | A-2 (no policies seeded), A-55 (setup defaults, placeholders); role templates are interpretations (A-27, A-28, A-54, A-69, A-83, A-86, A-87, A-94, A-101, A-126) | verify (CQ-C1); ask (CQ-C3) |
| §5.7 CFO approval on the period lock | not built; `periods.lock` suffices | — | ask (CQ-C2) |
| final list 4 earning method, short-rate table | ASSUMPTION | A-4 (daily_365 or monthly; 24ths and short-rate refused, pro-rata cancellations) | verify (CQ-D1) |
| final list 5 regulator report formats | **OPEN** | partial outputs only: agency register CSV/XLSX (A-16, A-62), unearned premium report (A-60, A-61), report exports (X12) | ask (CQ-A2) |
| final list 6 opening balances source | ASSUMPTION | A-3 (CSV, dot decimal, major units, configurable headers) | verify (CQ-H1) |

### A.10.2 Distribution design note

| OPEN | Handled by | Status |
|---|---|---|
| 1 IDRA caps and non-life zero-commission circular | A-18: non-life commission disabled until a scheme's profile allows it; caps as listed in the profile (none by default) | verify (CQ-F1) |
| 2 Life hierarchy level names and override rates | seeded example scheme `LIFE-AGENCY` only; no A-id | **OPEN** (CQ-F2) |
| 3 Renewal commission after termination | A-19: rule flag `pays_after_termination` default false; `renewal_requires_valid_licence` default true | verify (CQ-F1) |

Also unspecified in the note and assumed: A-14 (licence required for every producer type), A-16 (register format), A-20 (Phase 1 plans as flat schemes), A-21 (policy year), A-22 (payout route), A-23 (persistency), A-24 (incentive periods), A-25 (incentive award rules), A-26 (portal access).

### A.10.3 Rating, quotation, documents and renewals design note

| OPEN | Handled by | Status |
|---|---|---|
| 1 IDRA/NBR duty values per class | duties seeded as placeholders `verify = true`, `source = placeholder_verify`; A-66, A-68, A-113 | verify (CQ-B2) |
| 2 Cover note maximum validity per class | A-93: 30 days every class | verify (CQ-D8) |
| 3 Premium recognised at cover note | A-65: `recognise_at = policy`; `cover_note` refused (D-33, known gap) | verify (CQ-D9) |
| 4 Credit issuance rules | A-65, A-117: not allowed unless the product version allows it; premium-received reference otherwise; credit by producer or customer type not built | verify (CQ-D10) |
| 5 Motor NCB scale | seeded 0/10/20/30 in MOTOR-TARIFF; A-128 for how claim-free years move | verify (CQ-D3) |

Tariff values (rates, loadings, minimums, rounding, risk schema options and bounds), underwriting limits (A-90), risk flags (A-89), duplicate keys (A-88), KYC types (A-92), quotation validity (A-80, A-116), endorsement charge (A-119), renewal timings and reasons (A-125–A-135) and all default document wording (A-103, A-104, A-107) are placeholders to verify; the list is in `docs/PROGRESS.md` "Phase 3 (R1–R10) — end state" and in CQ-D and CQ-E.

### A.10.4 Observations from the flow audit (not tagged OPEN before; now OPEN)

- **OPEN** default business dates and scheduled runs follow the application clock (UTC), not the tenant time zone (Asia/Dhaka). Between 18:00 and 24:00 UTC a Dhaka user's "today" is yesterday (CQ-H2).
- **OPEN** the close does not look at manual journals pending approval in the period; a month locked with one pending cannot receive it later (CQ-C4).
- **OPEN** a period can be locked before its last day (CQ-C5).

## A.11 Decisions taken (summary)

| ID | Decision (one line) |
|---|---|
| D-01 | balance trigger immediate; lines before the posted flip |
| D-02 | session-level tenant setting; no transaction-mode pooling |
| D-03 | events inserted `queued` |
| D-04 | `CLAIM_RESERVE_ADJUSTED` with signed delta |
| D-05 | payroll rule summary-level until Phase 2 |
| D-06 | VAT refund on cancellation is a product-version flag, default true |
| D-07 | outbox relay per tenant, no bypass role; `WithoutOverlapping` |
| D-08 | `tenant_id` + RLS on child tables too |
| D-09 | `User` is UUIDv7 and tenant-scoped; tenant resolved before auth |
| D-10 | trigger rejections fail the event with their code, no retry |
| D-11 | reversals and manual journals: approval policy when matched, always maker ≠ checker |
| D-12 | Distribution is its own context; Insurance keeps the commission subledger |
| D-13 | `agents` → `producers` keeping ids and column names |
| D-14 | compensation calculator pure in Distribution; cap on the sum of rates per trigger |
| D-15 | `commission_statements` is the producer statement; advances in Distribution |
| D-16 | Sanctum tokens in a tenant table; portal users by token only |
| D-17 | onboarding adds no business rules; wizard progress is a tenant table |
| D-18 | `product_classes` global catalogue; later classes reserved |
| D-19 | coverages as rows; Phase 1 JSON untouched; rating fields frozen once in use |
| D-20 | rating in `Insurance\Rating`, own evaluator, integer units, half-open bands |
| D-21 | plan statuses draft → approved → active → retired; supersede on request |
| D-25 | one generic append-only, content-addressed `DocumentStore` |
| D-26 | approval steps may name a role; policies effective-dated, overlaps refused |
| D-27 | account role mappings remapped from a date, never over posted days |
| D-28 | dependency-free XLSX writer |
| D-30 | quotation, proposal, cover note are separate Insurance modules; quotation frozen at issue |
| D-31 | referrals through the approval engine with per-approval steps |
| D-32 | `RatingEngine::rerate` reproduces a stored result; manual loading as a calculator step |
| D-33 | cover note posts nothing; recognition at cover note refused (gap) |
| D-34 | PDFs by headless Chrome through Symfony Process, fonts embedded |
| D-35 | one `DocumentGenerator`, tagged data providers, versioned templates |
| D-36 | tariff editor adds no rating rules; rates cross HTTP as integers |
| D-37 | stamp duty on its own role; POLICY_ISSUED/ENDORSED version 2 |
| D-38 | renewal chain is `renewal_of_policy_id` |
| D-39 | issue from proposal and re-rated endorsements live in `PolicyLifecycle`; demo sells through the real path |
| D-40 | renewals module; renewal link on the quotation |
| D-41 | notifications as a Platform service with adapters; system-generated documents |
| D-42 | expiry register is a stored, idempotently rebuilt projection |

IDs D-22–D-24 and D-29 were not used.

## A.12 Gaps carried into Phase 2

From `docs/PROGRESS.md` end states and the exit checklist; none is resolved by this addendum.

| Gap | Phase 2 relevance |
|---|---|
| Outbox messages other than `PostAccountingEvent` are never relayed (`CommissionPayrollEarning`, `CommissionPayableToAp`, `DunningNoticeDue`, `ProducerLicenceExpiring`, period messages) | B.2.6 generic consumers; B.11 |
| Refunds and commission payouts are not routed through the approval engine | B.3 (object types) |
| Parties hold no email or phone | notifications for Phase 2 (payslips, approvals) need contact details |
| 2.0c Playwright happy path and 2.0d claim reserve property test still todo | entry condition for 2.17 |
| Receipt / claim / agent deposit numbering collision across branches (A.7.6) | fix before multi-branch go-live; Phase 2 formats follow the fix |
| No cost centre table although `dim_cost_centre` exists | B.2.2 |
| `producers.employee_id` has no target | B.9 |
| Branch switcher does not filter lists; branch-scoped users get 403 on some pages (X audit) | Phase 2 screens must work for branch-scoped roles |
| Recognition at cover note (D-33), credit by producer/customer type and multi-payer on the proposal path (A-117, A-121), stamp duty column in the premium register, IDRA forms (G5) | Insurance, not Phase 2 |

---

# Part B — Phase 2 design (Finance + People)

## B.0 Scope and entry conditions

Spec §11 Phase 2: AP, AR, expenses, fixed assets, budget, cash flow, HR, payroll engine, full workflow engine, SoD. This part follows the nine deliverables of spec §12 for Phase 2 (kickoff §1). It is organised by module; sections B.13–B.17 collect the cross-module deliverables.

| Entry condition | State |
|---|---|
| Design addendum reviewed | this document; review before slice 2.2 |
| Customer questions sent | `docs/phase-2/customer-questions.md` |
| 2.0 carry-over | 2.0a, 2.0b done; 2.0c, 2.0d todo |
| Answers needed before build | per slice in the customer questions summary table |

Reuse, do not rebuild (kickoff §0): posting engine and versioned rules, reversal, periods, close runs and tagged close checks, tagged reconcilers, bank GL overrides, approvals with inbox and handlers, SodGuard, audit, numbering, tax rates with withholding, import wizard, bank statement import and matching, DocumentStore and DocumentGenerator, Notifier, PageSupport, QueueView/ObjectPage/Drawer/JournalPreviewDialog.

## B.1 Context map update

```
PLATFORM   + Workflow (grows Approvals: parallel steps, delegation, escalation, SLA timers, rework)
           + Outbox consumers (generic relay of non-accounting messages)
ACCOUNTING + rule schema line groups repeated over payload lists (B.2.1)
FINANCE    Bank · Payables (suppliers, bills, payment runs, bank payment files) · Receivables (non-premium invoices, receipts)
           Expenses (claims, petty cash) · FixedAssets · Budget · CashFlow
PEOPLE     Employee (employees, employment, org reference data) · Attendance · Leave · Payroll (components, rule sets,
           inputs, loans, runs, payslips, salary bank files, final settlement) · SelfService
```

Module layout (DECISION proposed PD-1): `app/Modules/Finance/{Bank,Payables,Receivables,Expenses,FixedAssets,Budget,CashFlow}` and
`app/Modules/People/{Employee,Attendance,Leave,Payroll,SelfService}`, each with `Domain`, `Application`, `Infrastructure`, `Http`, and
service providers `Finance\Providers\FinanceServiceProvider` (exists) and `People\Providers\PeopleServiceProvider` (new).

Dependency rules (INVARIANT, existing arch tests stay; additions proposed):
- Finance and People use Platform and `Accounting\Application` only, plus each other's **Application** read contracts where named below; never each other's Domain, Infrastructure or Http.
- People → Finance: `Finance\Bank\Application\BankAccountQuery` (the bank account a salary file is drawn on). Finance → People: `People\Employee\Application\EmployeeDirectory` (payee details of an employee expense claim).
- Neither Finance nor People uses Insurance or Distribution. Insurance → Finance/People only through outbox messages (commission payouts, B.11).
- **PD-2 (proposed):** the payroll ↔ commission link is an event, not a query (kickoff §1.1 asked): People consumes `CommissionPayrollEarning`; People never reads producers or commission tables.
- Cash-flow sources are inverted like close checks: Finance defines `CashFlowSource`; Insurance and People implement and tag it (B.8.2).
- Line-manager steps are inverted: Platform defines `ReportingLineResolver`; People implements it (B.3).
- New arch tests: "people does not use insurance or distribution", "finance does not use people domain", "people does not use finance domain", "no floats in payroll" (like rating).

## B.2 Cross-cutting Phase 2 conventions

### B.2.1 Posting rules with repeated lines (kernel change)

Per-employee payroll lines (kickoff §1.3), bill lines to different accounts and payment runs with many items need a variable number of lines per event. Today a rule has a fixed line list.

**DECISION (proposed) PD-3:** the rule schema gains an optional line group `{"for_each": "payload.<list>", "lines": [...]}`. Inside the group, `item.*` is available to amount expressions and dims; zero lines are dropped as today; balance, rounding residual, dimension requirements and account overrides work per generated line. Rules without `for_each` are evaluated exactly as today (every existing golden fixture unchanged). The alternative — one event per employee or bill line — gives many journals per run and many idempotency keys, and cannot express one bank debit for a whole run. OPEN (engineering, for review): maximum list size per event (default proposal 5,000 items; larger runs split by pay group).

**PD-4 (proposed):** a line may take its account from the item (`item.account_id`) only for roles listed as overridable (`erp.posting.overridable_roles`, today `bank_main`), extended with `ap_expense`, `ar_income`, `expense_claim_expense`, `petty_cash_expense`, `fixed_asset_cost`, `accumulated_depreciation`, `depreciation_expense`. The existing `AccountOverrides` checks apply (active, postable, entity), plus: an overridden account may not be a control account (INVARIANT, same as manual journals without adjustment rights).

### B.2.2 Dimensions

- Typed columns exist for `cost_centre` and `employee`; Phase 2 adds `cost_centres` (tenant table: entity, code, name, parent, status, effective dates) so the dimension has a master (PD-5).
- Supplier, bill, AR receipt, asset, petty cash float and payroll run go in `dims_ext` keys `payee_party`, `ap_bill`, `ar_receipt`, `asset`, `petty_cash_float`, `payroll_run`. Reconcilers already read `dims_ext` (`LedgerQuery::dimensionExpression`). A typed column is added only if a reconciliation or report is measured too slow (LATER).
- AR customers use the existing `customer` column (party id).
- Required dimensions per event are rule `dimensions.required`; Phase 2 rules require `branch` everywhere and `cost_centre` on expense lines (OPEN whether cost centres are mandatory: CQ-I9).

### B.2.3 Parties

Suppliers, AR customers and employees are parties (spec §3). Party role `vendor` already exists (X8 creates vendor parties); `employee` exists. Bank accounts stay on the party (`party_bank_accounts`, encrypted, masked). **INVARIANT (proposed):** a change of a supplier's or employee's paying bank account takes effect only after a second person approves it, and a payment file uses the bank account snapshot stored on the payment item.

### B.2.4 Numbering (proposed formats)

| Document | doc_type | Prefix | Scope | Format |
|---|---|---|---|---|
| AP bill | `ap_bill` | BIL | entity + branch | `{prefix}-{branch}-{fy}-{seq}` |
| Payment run | `payment_run` | PRN | entity | `{prefix}-{fy}-{seq}` |
| AR invoice | `ar_invoice` | INV | entity + branch | `{prefix}-{branch}-{fy}-{seq}` |
| AR credit note | `ar_credit_note` | ACN | entity + branch | `{prefix}-{branch}-{fy}-{seq}` |
| AR receipt | `ar_receipt` | ARR | entity + branch | `{prefix}-{branch}-{fy}-{seq}` |
| Expense claim | `expense_claim` | EXC | entity + branch | `{prefix}-{branch}-{fy}-{seq}` |
| Petty cash voucher | `petty_cash_voucher` | PCV | entity + branch | `{prefix}-{branch}-{fy}-{seq}` |
| Fixed asset tag | `fixed_asset` | FA | entity | `{prefix}-{fy}-{seq}` |
| Asset disposal | `asset_disposal` | ADS | entity | `{prefix}-{fy}-{seq}` |
| Payroll run | `payroll_run` | PRL | entity | `{prefix}-{fy}-{seq}` |
| Payslip | `payslip` | PSL | entity | `{prefix}-{fy}-{seq}` |
| Final settlement | `final_settlement` | FSL | entity | `{prefix}-{fy}-{seq}` |
| Employee loan | `employee_loan` | LOA | entity | `{prefix}-{fy}-{seq}` |

PD-6 (proposed): every branch-scoped sequence is branch-coded, so the A.7.6 collision cannot recur. Formats stay configuration. Whether the customer requires a different receipt/invoice/voucher format is CQ-E5.

### B.2.5 New account roles (proposed, global catalogue)

| Role | Normal side | Used by | Control / subledger |
|---|---|---|---|
| `accounts_payable` (exists, D6) | credit | AP bills, commission payouts to AP, payment runs | control, subledger `ap` |
| `ap_expense` | debit | bill lines (overridable) | — |
| `asset_clearing` | debit | bill lines for assets; capitalisation | control, subledger `asset_clearing` (LATER review task) |
| `input_vat_receivable` | debit | recoverable VAT on bills (if any, CQ-B4) | — |
| `vat_deducted_at_source_payable` | credit | VAT withheld from suppliers | — |
| `supplier_tax_withheld_payable` | credit | income tax withheld from suppliers | — |
| `ar_receivable` | debit | AR invoices | control, subledger `ar` |
| `ar_income` | credit | invoice lines (overridable) | — |
| `output_vat_payable` | credit | VAT on AR invoices | — |
| `tax_deducted_by_customers` | debit | tax withheld by AR customers | — |
| `ar_unapplied_receipts` | credit | AR money not yet allocated | control, subledger `ar_unapplied` |
| `employee_claims_payable` | credit | approved expense claims | control, subledger `employee_claims` |
| `expense_claim_expense`, `petty_cash_expense` | debit | claim and voucher lines (overridable) | — |
| `petty_cash` | debit | floats | control, subledger `petty_cash` |
| `petty_cash_over_short` | debit | count differences | — |
| `fixed_asset_cost` | debit | capitalised assets (overridable per class) | control, subledger `fixed_asset_cost` |
| `accumulated_depreciation` | credit | depreciation (overridable per class) | control, subledger `accumulated_depreciation` |
| `depreciation_expense` | debit | depreciation (overridable per class) | — |
| `asset_disposal_gain_loss` | credit | disposals | — |
| `asset_disposal_receivable` | debit | sale proceeds not received in cash (LATER) | — |
| `salary_expense`, `employer_pf_expense`, `salary_payable`, `employee_tax_payable`, `pf_payable` (exist) | | payroll | `salary_payable` control, subledger `payroll` |
| `bonus_expense`, `allowance_expense`, `overtime_expense` | debit | payroll components mapped to them | — |
| `employee_loans_receivable` | debit | loans and salary advances | control, subledger `employee_loans` |
| `gratuity_provision`, `gratuity_expense` | credit / debit | gratuity accrual and settlement (CQ-J4) | — |
| `leave_encashment_expense` | debit | final settlement, encashment | — |
| `final_settlement_payable` | credit | approved settlements | control, subledger `final_settlement` |

Each new role gets a caption in `resources/help/roles.*.md`, a row in the chart-of-accounts template and the unmapped-roles banner (F4) for existing tenants. Whether the customer keeps output VAT on premium and non-premium income in one GL account is a mapping, not a design choice (two roles may map to the same non-control account).

### B.2.6 Outbox consumers

**DECISION (proposed) PD-7:** a Platform `OutboxConsumer` contract (message type → handler, container-tagged) and a relay job that delivers unrelayed messages of each consumed type per tenant, marking `relayed_at` after the handler's transaction commits; handlers are idempotent by the source id in the payload (unique constraints, INVARIANT). Messages of a type with no consumer stay unrelayed, as today. Messages written before Phase 2 (for example `CommissionPayableToAp` since D6) are consumed on the first run, so no separate backfill is needed; a migration check lists paid statements with `paid_via` ap or payroll whose message is missing, and refuses to continue until they are resolved (never guessed).

### B.2.7 Approval object types (proposed additions to `erp.approvals.object_types`)

`ap_bill`, `supplier_bank_change`, `payment_run`, `ar_credit_note`, `expense_claim`, `petty_cash_replenishment`, `asset_disposal`, `budget`, `employee_change`, `leave_request`, `payroll_rule_set`, `payroll_run`, `final_settlement`, `employee_loan`; and, closing the A.12 gap, `refund` and `commission_statement` (limit screen for them).

### B.2.8 Close catalogue additions (proposed)

| # | Code | Kind | Depends | Owner | Permission | Blocks if |
|---|---|---|---|---|---|---|
| 7 | `ap_reconciliation` | reconciliation (`ap`) | — | accounting | `periods.soft_lock` | variance ≠ 0 |
| 7 | `ar_reconciliation` | reconciliation (`ar`, `ar_unapplied`) | — | accounting | `periods.soft_lock` | variance ≠ 0 |
| 7 | `expense_reconciliation` | reconciliation (`employee_claims`, `petty_cash`) | — | accounting | `periods.soft_lock` | variance ≠ 0 |
| 8 | `pending_documents_review` | check | — | accounting | `accounting.create_manual_journal` | depends on CQ-C4: bills, invoices, claims, vouchers, manual journals dated in the period and not posted |
| 9 | `depreciation` | check (runs the batch) | — | system | `periods.soft_lock` | an asset in service in the period without a depreciation row |
| 9 | `fixed_asset_reconciliation` | reconciliation (`fixed_asset_cost`, `accumulated_depreciation`) | `depreciation` | accounting | `periods.soft_lock` | variance ≠ 0 |
| 9 | `payroll_reconciliation` | reconciliation (`payroll`, `employee_loans`, `final_settlement`) | — | payroll | `payroll.approve` | variance ≠ 0 |
| 9 | `payroll_posted` | check | — | payroll | `payroll.approve` | a pay group with employees active in the period has no posted run (CQ-J8 may make it a warning) |

`order_no` is not unique in `period_close_tasks`; tasks sharing an order run independently. Task 13 depends on every earlier task that exists (as today). The task list per tenant follows the modules a tenant uses: **OPEN (engineering)** whether catalogue entries are conditional on module flags or always present and skippable with a reason; proposal: conditional on `erp.modules.*` flags, so a tenant without payroll has no payroll task (PD-8).

### B.2.9 Dates and time zone

Every Phase 2 date rule (due dates, SLA hours, attendance days, payroll period cut-off, depreciation month, leave days) reads "today" from one place. PD-9 (proposed): a Platform `BusinessClock::today()` for the tenant; which zone it uses is CQ-H2, and the change of existing defaults (X2) and schedules is its own small slice before 2.2, not part of a feature slice.

---

## B.3 Workflow engine — growth of ApprovalService (slice 2.2)

### Scope

| MVP | LATER |
|---|---|
| sequential steps (today) and parallel steps: all of / any of / quorum n of the step's members | graphical workflow designer |
| step members by role (D-26), by permission, or the requester's line manager (reporting line) | conditional branches inside a workflow beyond amount/kind conditions |
| delegation: a user delegates their approval authority for a date range, optionally per object type | delegation chains; delegation of underwriting limits |
| SLA per step in hours; reminder at a configured share of the SLA; escalation adds a role as extra decider when the SLA passes | business-hour calendars (CQ-C8), auto-decisions |
| rework: a decider returns the object to the requester with a reason; resubmission starts a new approval | partial rework of one line |
| withdraw by the requester while pending | |
| inbox: due / overdue badges, "on behalf of" | mobile push |

### Data model sketch

```sql
approval_policies   -- unchanged columns; steps jsonb grows (validated by ApprovalPolicyService):
  steps: [{ mode: 'sequential'|'all'|'any'|'quorum', quorum: int null,
            members: [{role}|{permission}|{reporting_line: 1}], permission,   -- permission = the SoD/audit duty (D-26)
            sla_hours: int null, remind_at_bp: int null, escalate_to_role: text null }]
  + allow_rework bool default false, allow_withdraw bool default true

approvals           -- + round smallint default 1, previous_approval_id null, due_at timestamptz null,
                    --   status += 'returned' | 'withdrawn'; steps snapshot always stored at request (PD-10)
approval_decisions  -- + round, member_key text, on_behalf_of_user_id null,
                    --   decision += 'returned'; unique(approval_id, step_no, decided_by) replaces unique(approval_id, step_no)
approval_delegations(id, t, delegator_user_id, delegate_user_id, object_types text[] null, starts_on date, ends_on date,
                     reason, created_by, revoked_by null, revoked_at null)
                     CHECK delegator ≠ delegate; EXCLUDE overlapping ranges per (delegator, object type)
approval_step_timers(id, t, approval_id, step_no, due_at, reminded_at null, escalated_at null, escalated_to_role null)
                     unique(approval_id, step_no)
```

**DECISION (proposed) PD-10:** from 2.2 every approval stores its step snapshot at request (D-31 made it optional). A policy changed while an approval is pending never changes that approval. Existing pending approvals without a snapshot keep reading their policy (data migration copies the policy steps into them).

**DECISION (proposed) PD-11:** rework closes the approval as `returned` and the owning handler moves the object back to draft; resubmission requests a new approval with `previous_approval_id`. One approval row is one round, so the existing unique constraints and audit sentences hold.

### State machine (workflow instance = approval)

```
pending ─decide(step complete, more steps)─▶ pending(next step)
pending ─decide(last step complete)─▶ approved        → handler.onApproved
pending ─reject(reason)─▶ rejected                     → handler.onRejected
pending ─return(reason)─▶ returned                     → handler.onReturned (object → draft)   [allow_rework]
pending ─withdraw(requester)─▶ withdrawn               → handler.onWithdrawn (object → draft)  [allow_withdraw]
timers: due_at passed ─▶ reminded / escalated (flags on the timer; status unchanged)
```

Emitted: audit `approval.requested | decided | returned | withdrawn | escalated | delegated`; `ApprovalDecided` domain event (v1 §1) on final decisions; notifications through `Notifier` for assignment, reminder, escalation, return.

### Invariants

- INVARIANT existing policies (no `mode`, no members, no SLA) behave exactly as today; every existing approval test passes unchanged.
- INVARIANT maker ≠ checker for every step and every member; nobody decides two steps of one approval; nobody decides twice in a parallel step.
- INVARIANT a delegate decides only with the delegator's authority for that step; SodGuard checks the delegate **and** the delegator against the object; a delegate is never the requester; delegations do not chain.
- INVARIANT escalation and SLA never approve or reject; they only add deciders and notify.
- INVARIANT a quorum is between 1 and the number of eligible members at request; a step with no eligible member refuses the request (`APPROVAL_STEP_UNSTAFFED`) instead of creating an approval nobody can decide.
- INVARIANT approvals whose authority is a limit outside the engine (`proposal_referral`) cannot be delegated in MVP.

### Jobs

`ApprovalSlaJob` (queue `default`, every 5 minutes, per tenant, D-07): CAS on `reminded_at` / `escalated_at`, so reruns send nothing twice.

### Permissions and SoD

- `platform.manage_approvals` (exists) edits workflow policies.
- New `approval.delegate` (any staff user may delegate their own authority; held by every staff template) and `approval.manage_delegations` (Tenant Admin, on behalf of an absent user, audited with reason).
- No new SoD pair; the engine's own rules apply.

### Screens

Admin → Approval limits becomes **Approval workflows** (same queue; the drawer gains step mode, members, SLA, escalation, rework); **My delegations** on the account page; inbox shows due/overdue, "on behalf of", Return with reason.

### Dependencies

Platform only; `ReportingLineResolver` implementation arrives with 2.10 (until then a reporting-line step is refused as unstaffed). Answers: CQ-C6, CQ-C7, CQ-C8.

---

## B.4 Accounts payable (slices 2.3, 2.4)

### Scope

| MVP | LATER |
|---|---|
| suppliers (party role vendor) with terms, tax profile, on-hold / blocked | supplier portal, supplier self-onboarding |
| bills with lines to accounts, cost centres, assets; VAT, VAT deducted at source and supplier tax withheld per tax code | OCR "AI proposes" (spec §10, CQ-I3), purchase orders and three-way match |
| bill approval (maker ≠ checker) and posting; cancellation of an unpaid posted bill by mirror | debit notes on part-paid bills, recurring bills |
| payment runs create → approve → release; bank payment file per format; one-to-many bank matching | multi-currency AP, payment returned by the bank |
| payables from commission statements (route ap) and approved expense claims | early-payment discounts |
| AP subledger reconciler, close task 7 (AP) | withholding certificates and returns |

### Data model sketch

```sql
suppliers(id pk, t, party_id unique, code, status 'active'|'on_hold'|'blocked', payment_terms_days smallint,
          default_account_id null, tax_profile jsonb {vat_registered, bin, tin, vds_applies, tds_code},
          default_party_bank_account_id null, created_by, updated_by)                       unique(t, code)
supplier_bank_changes(id pk, t, supplier_id, party_bank_account_id, requested_by, approval_id, status 'pending'|'applied'|'rejected')
ap_bills(id pk, t, e, branch_id, number null, supplier_id, supplier_reference, bill_date, due_date, accounting_date null,
         currency, net_minor, vat_minor, vds_minor, tds_minor, gross_minor, payable_minor, paid_minor,
         status 'draft'|'pending_approval'|'posted'|'partially_paid'|'paid'|'cancelled',
         source_type 'manual'|'commission_statement'|'expense_claim', source_id null, approval_id null,
         created_by, approved_by null, cancelled_reason null)
         unique(t, supplier_id, supplier_reference) where status <> 'cancelled'      -- duplicate bill INVARIANT
         unique(t, source_type, source_id) where source_id is not null
         CHECK payable = gross − vds − tds; CHECK paid ≤ payable
ap_bill_lines(id pk, t, bill_id, line_no, description, account_id, cost_centre_id null, branch_id, asset_class_id null,
              net_minor, tax_code null, vat_minor, vds_minor, tds_minor)
payment_runs(id pk, t, e, number, bank_account_id, pay_date, currency, total_minor, item_count,
             status 'draft'|'pending_approval'|'approved'|'released'|'cancelled',
             created_by, approval_id null, released_by null, released_at null)
payment_run_items(id pk, t, run_id, payable_type 'ap_bill'|'expense_claim', payable_id, payee_party_id,
                  bank_account_snapshot jsonb (masked + encrypted number, bank, branch/routing), amount_minor,
                  status 'included'|'removed'|'released')
                  unique(payable_type, payable_id) where status in ('included','released')   -- never paid twice INVARIANT
bank_payment_files(id pk, t, run_id, format_code, version, stored_document_id, sha256, total_minor, item_count,
                   generated_by, generated_at)                                          -- append-only
```

Tax codes: `Platform\Tax\TaxRates` holds VAT and withholding rates by jurisdiction and type; PD-12 (proposed) adds tax types `vds` and `tds_<code>` rows rather than a second tax table. Which codes and rates apply is CQ-B3, CQ-B4.

### Posting

| Event | Lines | Key | Source |
|---|---|---|---|
| `AP_BILL_POSTED` | for_each line: DR `ap_expense` (item account) / `asset_clearing` (asset lines) net (+ VAT when not recoverable) and DR `input_vat_receivable` (recoverable VAT); CR `accounts_payable` payable; CR `vat_deducted_at_source_payable` vds; CR `supplier_tax_withheld_payable` tds. Dims branch, cost_centre, payee_party, ap_bill | `AP_BILL_POSTED:{ap_bill_id}` | on approval |
| `AP_BILL_CANCELLED` | mirror of the bill (nothing paid) | `AP_BILL_CANCELLED:{ap_bill_id}` | cancellation |
| `AP_PAYMENT_RELEASED` | for_each item: DR `accounts_payable` (ap_bill) or `employee_claims_payable` (expense_claim); CR `bank_main` (override = run's bank GL account) total | `AP_PAYMENT_RELEASED:{payment_run_id}` | release |
| none | bill from a commission statement: liability already posted by `COMMISSION_PAYOUT_TO_AP` | — | B.11 |

Worked example (illustrative rates; which taxes apply is OPEN, CQ-B3/B4). Office rent bill: net 100,000, VAT 15% 15,000 treated as not recoverable, all VAT deducted at source, rent tax withheld 5% of net 5,000. Payable to the landlord 95,000.

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | ap_expense (Rent) | 115,000 | | B, CC, payee, bill |
| 2 | accounts_payable | | 95,000 | B, payee, bill |
| 3 | vat_deducted_at_source_payable | | 15,000 | B |
| 4 | supplier_tax_withheld_payable | | 5,000 | B |

Payment run PRN-2026-000004 pays that bill and an employee expense claim of 3,500:

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | accounts_payable | 95,000 | | B, payee, bill |
| 2 | employee_claims_payable | 3,500 | | B, EMP |
| 3 | bank_main (run's bank account) | | 98,500 | B |

### Invariants

- INVARIANT a bill posts only after a second person approves it (`ap.bill_create` ✕ `ap.bill_approve` on the bill); an approval policy by amount adds steps, never removes the checker.
- INVARIANT one open bill per (supplier, supplier reference); Σ lines = bill totals; payable = gross − VAT deducted at source − tax withheld.
- INVARIANT a payable is in at most one included or released payment item; Σ items = run total = bank file total = `AP_PAYMENT_RELEASED` credit.
- INVARIANT a run is released only after approval by someone other than its creator, and released by someone other than its approver (`payment_run.create` ✕ `approve` ✕ `release`, object rules).
- INVARIANT a payment item's bank account is the snapshot taken when the item was added; a supplier bank change pending approval blocks adding that supplier's items (`SUPPLIER_BANK_CHANGE_PENDING`).
- INVARIANT a blocked or on-hold supplier's bills cannot be added to a run.
- INVARIANT a bill created from a commission statement submits no accounting event and cannot be edited; cancelling it is refused (the statement owns the liability).
- The bank file is generated from the released run only and stored append-only with its hash; regeneration is a new version, the payment total cannot change.

### State machines

```
bill:        draft ─submit─▶ pending_approval ─approve─▶ posted ─pay(part)─▶ partially_paid ─pay─▶ paid
               └─delete         └─reject/return─▶ draft      └─cancel(nothing paid, reason)─▶ cancelled
payment run: draft ─submit─▶ pending_approval ─approve─▶ approved ─release─▶ released
               └─cancel          └─reject/return─▶ draft       └─cancel(reason)─▶ cancelled (items freed)
```

Emitted: `ApBillPosted`, `PaymentRunReleased` (domain events); accounting events as above.

### Reconciliation and close

`PayablesReconciler` (subledger `ap`, control `accounts_payable`, item dimension `payee_party`), dated per A-8: Σ payable of posted bills by `accounting_date` − Σ released items by `pay_date` − Σ cancelled bills by cancellation date, plus commission payables to AP by statement `paid_on` (from their AP bill rows). `COMMISSION_PAYOUT_TO_AP` version 2 adds `payee_party` to its lines; version 1 journals lack it, which matters only for drill-down when a variance exists (1A.8 behaviour). Close task 7 `ap_reconciliation`. Bank matching: released runs appear as ledger lines on the bank's GL account; the existing matcher matches one statement line to one or many ledger lines.

### Permissions and SoD

`supplier.manage`, `supplier.approve_bank_change`, `ap.bill_create`, `ap.bill_approve`, `ap.payment_run_create`, `ap.payment_run_approve`, `ap.payment_run_release`. SoD: `ap.bill_create` ✕ `ap.bill_approve` (object); `ap.payment_run_create` ✕ `ap.payment_run_approve` (object); `ap.payment_run_approve` ✕ `ap.payment_run_release` (object); `ap.payment_run_create` ✕ `ap.payment_run_release` (object); `supplier.manage` ✕ `supplier.approve_bank_change` (object); `supplier.approve_bank_change` ✕ `ap.payment_run_release` (user — the person who approves payee bank details never releases money). Templates in B.14.

### Screens

Suppliers queue and supplier page (Overview · Bills · Payments · Bank accounts · Documents · Audit); Bills queue (awaiting approval, due this week, overdue), bill drawer/page with lines grid and journal preview, attachments (supplier invoice PDF); Payment runs: create from due bills and approved claims (filters: due by, supplier, bank account), review, approve, release with confirmation, *Download bank file*; home queues "Bills to approve", "Runs to release"; AP ageing report and supplier statement.

### Dependencies

2.2 (object types, rework); 2.4 needs CQ-I1 (bank payment file formats) for anything beyond a generic CSV; commission route consumer needs PD-7; expense claim items need 2.6.

---

## B.5 Accounts receivable, non-premium (slice 2.5)

### Scope

| MVP | LATER |
|---|---|
| invoices to parties with role customer for non-premium income (which income: CQ-I4) | recurring invoices, dunning for AR, customer portal |
| VAT on invoice lines; credit notes with approval | reinsurance, co-insurance and investment income (Phase 3/4 subledgers) |
| AR receipts by bank or cheque, allocation to invoices, unapplied money, tax deducted by the customer | allocation of premium suspense to AR invoices |
| printed invoice and credit note (DocumentGenerator provider) | multi-currency |
| AR and AR-unapplied reconcilers, close task 7 (AR); AR ageing | |

**DECISION (proposed) PD-13:** AR receipts are Finance's own (`ar_receipts`), not Insurance Collections' `receipts`, so Finance does not depend on Insurance and premium suspense rules stay untouched. Money for an AR invoice that lands in premium suspense is refunded or allocated manually in MVP (manual journal pair with reason); cross-allocation is LATER.

### Data model sketch

```sql
ar_invoices(id pk, t, e, branch_id, number null, party_id, invoice_date, due_date, accounting_date null, currency,
            net_minor, vat_minor, gross_minor, allocated_minor, credited_minor,
            status 'draft'|'posted'|'partially_paid'|'paid'|'credited'|'cancelled', created_by, posted_by null)
            CHECK allocated + credited ≤ gross
ar_invoice_lines(id pk, t, invoice_id, line_no, description, account_id, cost_centre_id null, net_minor, tax_code null, vat_minor)
ar_credit_notes(id pk, t, e, branch_id, number null, invoice_id, credit_date, net_minor, vat_minor, reason,
                status 'draft'|'pending_approval'|'posted'|'rejected', approval_id null, created_by)
ar_receipts(id pk, t, e, branch_id, number, party_id, received_on, amount_minor, tax_deducted_minor, bank_account_id,
            reference, cheque_no null, cheque_bank null, cheque_date null,
            status 'unallocated'|'partially_allocated'|'allocated'|'bounced')
ar_receipt_allocations(id pk, t, receipt_id, invoice_id, amount_minor, allocated_on, allocated_by, reversed_on null)
```

### Posting

| Event | Lines | Key |
|---|---|---|
| `AR_INVOICE_POSTED` | DR `ar_receivable` gross (CU); for_each line CR `ar_income` (item account) net; CR `output_vat_payable` VAT | `AR_INVOICE_POSTED:{ar_invoice_id}` |
| `AR_CREDIT_NOTE_POSTED` | mirror for the credited amounts | `AR_CREDIT_NOTE_POSTED:{ar_credit_note_id}` |
| `AR_RECEIPT_RECORDED` | DR `bank_main` (override) amount; DR `tax_deducted_by_customers` tax; CR `ar_unapplied_receipts` amount + tax | `AR_RECEIPT_RECORDED:{ar_receipt_id}` |
| `AR_RECEIPT_ALLOCATED` | DR `ar_unapplied_receipts` / CR `ar_receivable` (CU, invoice) | `AR_RECEIPT_ALLOCATED:{ar_receipt_allocation_id}` |
| `AR_RECEIPT_BOUNCED` / `AR_ALLOCATION_REVERSED` | mirrors (as 1C.2) | per receipt / allocation |

PD-14 (proposed): every AR receipt passes through `ar_unapplied_receipts`, even when allocated at once (two journals), so there is one receipt rule and one reconciler shape; the premium side keeps its Phase 1 rules.

Worked example (illustrative; taxes OPEN): survey fee invoice net 20,000 + VAT 15% 3,000 = 23,000. The customer pays 22,000 and deducts 1,000 tax.

| Event | Line | Role | DR | CR | Dims |
|---|---|---|---|---|---|
| AR_INVOICE_POSTED | 1 | ar_receivable | 23,000 | | B, CU |
| | 2 | ar_income (Survey fee income) | | 20,000 | B, CC |
| | 3 | output_vat_payable | | 3,000 | B |
| AR_RECEIPT_RECORDED | 1 | bank_main | 22,000 | | B |
| | 2 | tax_deducted_by_customers | 1,000 | | B |
| | 3 | ar_unapplied_receipts | | 23,000 | B, ar_receipt |
| AR_RECEIPT_ALLOCATED | 1 | ar_unapplied_receipts | 23,000 | | B, ar_receipt |
| | 2 | ar_receivable | | 23,000 | B, CU |

### Invariants

- INVARIANT an invoice posts once with its number; a posted invoice is corrected only by a credit note (approved, maker ≠ checker) or, while nothing is allocated, by cancellation posting the mirror.
- INVARIANT Σ allocations ≤ receipt amount + tax deducted; allocated + credited ≤ invoice gross.
- INVARIANT a bounced cheque reverses its allocations in one transaction (as 1C.2).

### State machines

```
invoice:     draft ─post─▶ posted ─allocate(part)─▶ partially_paid ─allocate─▶ paid
                            ├─credit(part/full, approval)─▶ credited (full)   └─cancel(no allocation)─▶ cancelled
credit note: draft ─submit─▶ pending_approval ─approve─▶ posted;  └─reject─▶ rejected
receipt:     unallocated ─allocate─▶ partially_allocated ─▶ allocated;  any ─bounce─▶ bounced
```

### Reconciliation and close

`ReceivablesReconciler` (`ar`, control `ar_receivable`, item dimension `customer`) and `UnappliedReceiptsReconciler` (`ar_unapplied`, item dimension `ar_receipt`), dated by accounting date, allocation date, credit date, bounce date. Close task 7 `ar_reconciliation`.

### Permissions and SoD

`ar.invoice_create` (create, post), `ar.credit_note_request`, `ar.credit_note_approve`, `ar.receipt_create`, `ar.receipt_allocate`. SoD: `ar.credit_note_request` ✕ `ar.credit_note_approve` (object); `ar.invoice_create` ✕ `ar.credit_note_approve` (object — the person who raised the invoice does not approve its credit).

### Screens

Invoices queue (drafts, overdue), invoice page with Documents tab and *Print invoice*; credit note drawer; AR receipts and allocation workbench (reuse the allocation pattern of U7); AR ageing and customer statement reports.

### Dependencies

2.2; CQ-I4 (which income), CQ-B5 (VAT and tax deducted by customers on non-premium income). OPEN (engineering): whether posting an invoice needs approval (proposal: no approval, maker-only, credit notes approved), to confirm at review.

---

## B.6 Expenses and petty cash (slice 2.6)

### Scope

| MVP | LATER |
|---|---|
| employee expense claims with categories, receipts attached (DocumentStore), approval by line manager then finance | per diem tables, mileage, corporate cards |
| reimbursement through AP payment runs | reimbursement through payroll (CQ-I6) |
| petty cash floats per branch (imprest), vouchers, replenishment with approval, cash counts | multi-currency floats |
| reconcilers for claims payable and petty cash | |

### Data model sketch

```sql
expense_categories(id pk, t, code, name, account_id, receipt_required_above_minor, per_claim_limit_minor null, status)
expense_claims(id pk, t, e, branch_id, number null, employee_id, claim_date, currency, total_minor,
               status 'draft'|'submitted'|'approved'|'rejected'|'returned'|'paid'|'cancelled',
               approval_id null, approved_on null, payment_run_item_id null)
expense_claim_lines(id pk, t, claim_id, line_no, spent_on, category_id, account_id, cost_centre_id null, amount_minor,
                    stored_document_id null, note)
petty_cash_floats(id pk, t, e, branch_id, code, custodian_user_id, imprest_minor, status 'active'|'closed')
petty_cash_vouchers(id pk, t, float_id, number, voucher_date, payee_text, category_id, account_id, cost_centre_id null,
                    amount_minor, stored_document_id null, status 'posted'|'voided', void_reason null, replenishment_id null)
petty_cash_replenishments(id pk, t, float_id, number null, amount_minor, bank_account_id,
                          status 'draft'|'pending_approval'|'approved'|'paid'|'rejected', approval_id null, paid_on null)
petty_cash_counts(id pk, t, float_id, counted_on, counted_minor, expected_minor, difference_minor, counted_by, note,
                  status 'recorded'|'pending_approval'|'posted', approval_id null)
```

### Posting

| Event | Lines | Key |
|---|---|---|
| `EXPENSE_CLAIM_APPROVED` | for_each line DR `expense_claim_expense` (item account) (B, CC, EMP); CR `employee_claims_payable` total (EMP) | `EXPENSE_CLAIM_APPROVED:{expense_claim_id}` |
| (payment) | through `AP_PAYMENT_RELEASED` item type expense_claim | — |
| `PETTY_CASH_FLOAT_ISSUED` | DR `petty_cash` (float) / CR `bank_main` | `PETTY_CASH_FLOAT_ISSUED:{float_id}` |
| `PETTY_CASH_VOUCHER_POSTED` | DR `petty_cash_expense` (item account) / CR `petty_cash` (float) | `PETTY_CASH_VOUCHER_POSTED:{voucher_id}` |
| `PETTY_CASH_VOUCHER_VOIDED` | mirror | `PETTY_CASH_VOUCHER_VOIDED:{voucher_id}` |
| `PETTY_CASH_REPLENISHED` | DR `petty_cash` / CR `bank_main` | `PETTY_CASH_REPLENISHED:{replenishment_id}` |
| `PETTY_CASH_COUNT_DIFFERENCE` | shortage DR `petty_cash_over_short` / CR `petty_cash`; surplus mirrored | `PETTY_CASH_COUNT_DIFFERENCE:{count_id}` |

Worked example: travel claim 2,000 (fares) + 1,500 (meals) = 3,500 approved: DR expense 2,000 and 1,500, CR employee_claims_payable 3,500 (EMP). Petty cash float 10,000; vouchers stationery 1,200 and courier 800 (DR petty_cash_expense, CR petty_cash each); replenishment 2,000 (DR petty_cash / CR bank_main); count 9,950 against expected 10,000 → shortage 50 (DR petty_cash_over_short 50 / CR petty_cash 50).

### Invariants

- INVARIANT a claimant never approves their own claim (the engine's maker ≠ checker on the claim; the claimant is the requester).
- INVARIANT a claim line above its category's receipt threshold needs an attached document before submission.
- INVARIANT petty cash balance (imprest − unreplenished vouchers + count differences) is never negative; a voucher above the float's cash on hand is refused.
- INVARIANT replenishment amount = Σ vouchers not yet replenished (a replenishment covers named vouchers; no free amount) — **OPEN** whether the customer tops up to imprest or reimburses vouchers (CQ-I7); the proposal follows the imprest system.
- INVARIANT a voucher is voided only before its replenishment, with a reason.

### State machines

```
expense claim: draft ─submit─▶ submitted ─approve(all steps)─▶ approved ─payment run released─▶ paid
                                  ├─return─▶ returned ─resubmit─▶ submitted   └─reject─▶ rejected
replenishment: draft ─submit─▶ pending_approval ─approve─▶ approved ─pay─▶ paid
```

### Reconciliation and close

`EmployeeClaimsReconciler` (`employee_claims`, control `employee_claims_payable`, dimension `employee`); `PettyCashReconciler` (`petty_cash`, control `petty_cash`, dimension `petty_cash_float`). Close task 7 `expense_reconciliation`; an optional check "petty cash counted in the period" (CQ-I7).

### Permissions and SoD

`expense.claim_submit` (own claims; every staff employee via self-service), `expense.claim_approve` (finance step; line manager step by reporting line), `petty_cash.manage_floats`, `petty_cash.voucher_create` (custodian), `petty_cash.replenish_request`, `petty_cash.replenish_approve`, `petty_cash.count`. SoD: `petty_cash.voucher_create` ✕ `petty_cash.replenish_approve` (user); `petty_cash.replenish_request` ✕ `petty_cash.replenish_approve` (object); `petty_cash.voucher_create` ✕ `petty_cash.count` on the same count (object — the custodian does not certify their own cash).

### Screens

My expense claims (self-service), claim page with receipt uploads and approval timeline; claims to approve (inbox); petty cash float page (vouchers, balance, counts, *Request replenishment*); expense reports by category and cost centre.

### Dependencies

2.2 (reporting-line step needs 2.10); employees (2.10) — until 2.10, claims cannot be built (claimant is an employee); AP runs (2.4) for reimbursement. Kickoff lists 2.6 after 2.2 only; PD-15 (proposed): 2.6 depends on 2.10 and 2.4 as well, or petty cash ships first in 2.6a and claims in 2.6b after 2.10.

---

## B.7 Fixed assets (slice 2.7)

### Scope

| MVP | LATER |
|---|---|
| asset classes with method (straight line; reducing balance), useful life, residual, capitalisation threshold, GL accounts | revaluation, impairment, component accounting |
| register: capitalise from AP bill lines (asset clearing), from opening balances, manually | transfers between entities; asset counts by barcode |
| monthly depreciation batch per period; close task 9 | tax depreciation book (IFRS/LOCAL multi-book) |
| disposal by sale or write-off with approval | insurance-specific investment property |
| transfer between branches and cost centres (dated) | |
| register, depreciation schedule and NBV reports; reconcilers for cost and accumulated depreciation | |

### Data model sketch

```sql
asset_classes(id pk, t, code, name, method 'straight_line'|'reducing_balance', useful_life_months null, rate_bp null,
              residual_bp, capitalisation_threshold_minor, depreciation_start 'capitalisation_month'|'following_month',
              disposal_month 'depreciate'|'skip', cost_account_id, accumulated_account_id, expense_account_id,
              status, effective_from, effective_to)
fixed_assets(id pk, t, e, branch_id, cost_centre_id null, number, class_id, description, serial_no null, location null,
             custodian_employee_id null, acquired_on, capitalised_on null, cost_minor, residual_minor,
             useful_life_months null, rate_bp null, method, source_type 'ap_bill_line'|'opening'|'manual', source_id null,
             opening_accumulated_minor default 0,
             status 'draft'|'in_service'|'fully_depreciated'|'disposed', created_by)
             unique(t, source_type, source_id) where source_id is not null
asset_depreciation(id pk, t, asset_id, period_id, amount_minor, accumulated_minor, nbv_minor, run_id)
             unique(asset_id, period_id)                                            -- rerun no-op INVARIANT
asset_movements(id pk, t, asset_id, moved_on, from_branch_id, to_branch_id, from_cost_centre_id, to_cost_centre_id, reason, moved_by)
asset_disposals(id pk, t, asset_id, number null, disposal_date, kind 'sale'|'write_off', proceeds_minor, bank_account_id null,
                nbv_minor, gain_loss_minor, reason, status 'draft'|'pending_approval'|'posted'|'rejected', approval_id null)
```

### Posting

| Event | Lines | Key |
|---|---|---|
| `ASSET_CAPITALISED` | DR `fixed_asset_cost` (class cost account) / CR `asset_clearing` (from a bill) or CR the opening-balance account role for `opening` assets (via approved opening journal, not this event — PD-16) | `ASSET_CAPITALISED:{fixed_asset_id}` |
| `DEPRECIATION_POSTED` | DR `depreciation_expense` / CR `accumulated_depreciation` (class accounts), dims B, CC, asset | `DEPRECIATION_POSTED:{fixed_asset_id}:{period_id}` |
| `ASSET_TRANSFERRED` | DR/CR cost and accumulated with new vs old branch/cost centre dims (net zero per account) | `ASSET_TRANSFERRED:{asset_movement_id}` |
| `ASSET_DISPOSED` | DR `accumulated_depreciation` accumulated; DR `bank_main` proceeds (sale); DR or CR `asset_disposal_gain_loss`; CR `fixed_asset_cost` cost | `ASSET_DISPOSED:{asset_disposal_id}` |

PD-16 (proposed): assets brought in with the opening balances are registered with `opening_accumulated_minor` and post no capitalisation event; their GL cost and accumulated depreciation come from the opening journal (A-3). The reconciler includes them from the cut-over date.

Worked example. Laptop bought on a bill for 120,000 in September 2026, class IT equipment: straight line, 36 months, residual 0, depreciation from the capitalisation month.
- Bill line: DR asset_clearing 120,000 / CR accounts_payable 120,000.
- `ASSET_CAPITALISED`: DR fixed_asset_cost 120,000 / CR asset_clearing 120,000.
- Monthly depreciation 120,000.00 ÷ 36 = 3,333.33 (half-even on minor units); months 1–35 post 3,333.33 each (116,666.55), month 36 posts 3,333.45 so Σ = cost − residual exactly.
- Sold after 14 months of depreciation for 70,000 by bank; the disposal month is not depreciated (class `disposal_month = skip`, CQ-I8). Accumulated 14 × 3,333.33 = 46,666.62; NBV 73,333.38; loss 3,333.38.

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | accumulated_depreciation | 46,666.62 | | B, CC, asset |
| 2 | bank_main | 70,000.00 | | B |
| 3 | asset_disposal_gain_loss | 3,333.38 | | B, asset |
| 4 | fixed_asset_cost | | 120,000.00 | B, CC, asset |

### Invariants

- INVARIANT Σ depreciation over an asset's life = cost − residual (opening accumulated included); accumulated never exceeds cost − residual; the last period absorbs the rounding residual (property test).
- INVARIANT one depreciation row per asset and period; the batch posts only into open periods; rerunning a period inserts and posts nothing.
- INVARIANT no depreciation for periods after disposal or before capitalisation; a disposed asset is immutable.
- INVARIANT an asset below its class capitalisation threshold cannot be capitalised (the bill line is expensed) — threshold values CQ-I8.
- INVARIANT a class's method, life and accounts are effective-dated; a change applies to assets capitalised from its start date (existing assets keep theirs) — **OPEN** whether a change of life re-plans existing assets prospectively (CQ-I8).

### State machine

```
draft ─capitalise─▶ in_service ─depreciate(period)*─▶ in_service ─accumulated = cost − residual─▶ fully_depreciated
                        │                                                                           │
                        └─────────────── dispose (approval) ─▶ disposed ◀───────────────────────────┘
```

### Reconciliation and close

PD-17 (proposed): two reconcilers, `fixed_asset_cost` (control `fixed_asset_cost`) and `accumulated_depreciation` (control `accumulated_depreciation`), item dimension `asset`, because the kernel compares one normal-side balance per subledger and NBV is a difference of two. Close task 9 `depreciation` (runs `DepreciationRun` for the period, blocks on missing rows), then `fixed_asset_reconciliation`. An `asset_clearing` ageing check (clearing balance older than N days) is LATER.

### Jobs

`DepreciationJob` (queue `batch`, nightly after premium earning, per tenant): depreciates every open period that has ended, like `PremiumEarningJob`.

### Permissions and SoD

`asset.manage_classes`, `asset.register` (capitalise, move), `asset.dispose_request`, `asset.dispose_approve`. SoD: `asset.dispose_request` ✕ `asset.dispose_approve` (object); `asset.register` ✕ `asset.dispose_approve` (object — whoever registered the asset does not approve writing it off).

### Screens

Asset classes; asset register queue (in service, fully depreciated, disposed; filters by class, branch, custodian); asset page (Overview · Depreciation schedule · Movements · Documents · Accounting · Audit); *Capitalise* from a posted bill line with an asset class; disposal drawer with gain/loss preview; reports fixed asset register as at, depreciation for a period, additions and disposals.

### Dependencies

2.1 only for the model; capitalisation from bills needs 2.3; answers CQ-I8.

---

## B.8 Budgets and cash-flow forecast (slices 2.8, 2.9)

### B.8.1 Budgets

| MVP | LATER |
|---|---|
| annual budget per entity and fiscal year, lines by account × month × branch × cost centre (granularity CQ-I9) | budget control that blocks spending, commitments |
| versions: draft → submitted → approved; revisions as new versions | driver-based and headcount budgets from People |
| import from CSV through the import wizard (validate → dry run → commit) | MGMT book postings |
| variance report actual (GL) vs budget, by month and year to date, drill to account activity | variance alerts by notification |

```sql
budgets(id pk, t, e, book_id, fiscal_year smallint, code, name, version smallint,
        status 'draft'|'submitted'|'approved'|'superseded', approval_id null, prepared_by, approved_by null, approved_at null)
        unique(t, e, fiscal_year, code, version)
        EXCLUDE one approved version per (e, fiscal_year, code)
budget_lines(id pk, t, budget_id, account_id, period_no smallint 1..12, branch_id null, cost_centre_id null,
             amount_minor bigint)   -- normal-side amount
             unique(budget_id, account_id, period_no, coalesce(branch_id), coalesce(cost_centre_id))
```

- DECISION (proposed) PD-18: budgets post nothing and are not a book; variance reads posted journals of the primary book through `Accounting\Application` queries (extending `FinancialStatementsQuery::roleMovementByDimension` to accounts).
- INVARIANT an approved budget version is immutable (trigger); a revision is a new version, and approving it supersedes the previous approved version.
- INVARIANT budget lines reference postable income or expense accounts of the entity (balance-sheet budgets LATER).
- Approval: object type `budget`; permissions `budget.prepare`, `budget.approve`; SoD object rule prepare ✕ approve. Who approves budgets is CQ-I9.
- Screens: budgets queue, budget grid (accounts × months, like the targets grid of D8), import, variance report with drill.
- No close task; no reconciler.

### B.8.2 Cash-flow forecast

| MVP | LATER |
|---|---|
| weekly forecast for a horizon (default proposal 13 weeks, CQ-I10) from due items and manual inputs, per entity and bank account | scenario comparison, statistical collection curves |
| actual vs forecast for past weeks from bank ledger lines | |

Sources through a Finance contract `CashFlowSource` (PD-19), tagged by the owning contexts:

| Source | Owner | Items |
|---|---|---|
| bank balances today | Finance\Bank | GL balance of each bank account |
| AP bills due, approved runs | Finance\Payables | due date, payable − paid |
| AR invoices due | Finance\Receivables | due date, outstanding |
| premium installments due | Insurance\Collections | due date, outstanding (A-9 current outstanding) |
| approved unpaid claim payments | Insurance\Claims | approved date |
| approved commission statements not paid | Insurance\Commission | period end |
| payroll next pay day | People\Payroll | last posted run net by pay group |
| manual inputs | Finance\CashFlow | tax payments, investments, capital |

```sql
cash_forecast_inputs(id pk, t, e, bank_account_id null, week_start date, category, direction 'in'|'out', amount_minor, note,
                     recurring 'none'|'weekly'|'monthly', until date null, created_by)
cash_forecast_snapshots(id pk, t, e, taken_on, horizon_weeks, lines jsonb, taken_by)   -- to compare actual vs forecast
```

No posting, no invariant beyond "forecast lines never change posted data"; permission `cashflow.manage` (inputs), read with `reports.financial`. Depends on 2.4, 2.5 (and 2.13 for payroll); a source not yet built contributes nothing.

---

## B.9 People: employees, employment, attendance and leave (slices 2.10, 2.11)

### B.9.1 Scope

| MVP | LATER |
|---|---|
| employees as parties; effective-dated employment (branch, department, cost centre, designation, grade, category, type, line manager, pay group, basic pay) | org chart editor, positions and vacancies, recruitment |
| hire, transfer, promotion, pay change, bank account change, separation as change requests with approval | performance, training |
| reference data: departments, designations, grades, employee categories, holiday calendars | biometric device integration (CQ-J6) |
| attendance by day: manual entry and CSV import | shift rosters, overtime rules beyond a payroll input |
| leave types, entitlements, accrual, carry forward, requests with approval, balances as a ledger | leave encashment outside final settlement |
| HR data import for cut-over (import wizard) | |

Spec §13: **Employee ≠ Agent.** A BDO is a producer (Distribution) and may also be an employee (People); `producers.employee_id` links them. PD-20 (proposed): People adds the foreign key `producers.employee_id → employees.id` in its migration; existing non-null values without an employee are listed and the migration stops (cut-over data, CQ-J2), never guessed.

### B.9.2 Data model sketch

```sql
employees(id pk, t, party_id unique, code, status 'onboarding'|'active'|'separated', joined_on, separated_on null,
          date_of_birth null, tin_enc null, nid_enc null, user_id null (self-service login, users.kind staff), created_by)
          unique(t, code)
employments(id pk, t, employee_id, entity_id, branch_id, department_id, cost_centre_id, designation_id, grade_id,
            category_code, employment_type 'permanent'|'probation'|'contract'|'intern', manager_employee_id null,
            pay_group_id, basic_minor, currency, effective_from, effective_to null, change_request_id)
            EXCLUDE (employee_id WITH =, daterange(effective_from, effective_to) WITH &&)   -- one record per day INVARIANT
employee_change_requests(id pk, t, employee_id null (hire), kind 'hire'|'transfer'|'promotion'|'pay_change'|'bank_account'|'separation',
                         payload jsonb, effective_from, status 'draft'|'pending_approval'|'approved'|'rejected'|'applied',
                         approval_id null, requested_by, applied_at null)
departments, designations, grades(id, t, code, name, status)          employee_categories(code, name) per tenant
holiday_calendars(id, t, code, name) · holidays(id, t, calendar_id, date, name, branch_id null)
attendance_days(id pk, t, employee_id, work_date, status 'present'|'absent'|'half_day'|'leave'|'holiday'|'weekly_off',
                in_at null, out_at null, source 'manual'|'import', recorded_by)       unique(employee_id, work_date)
leave_types(id pk, t, code, name_en, name_bn, paid bool, entitlement_half_days int, accrual 'annual_grant'|'monthly',
            carry_forward_max_half_days int, encashable bool, document_required_after_half_days null, applies_to_categories text[] null,
            effective_from, effective_to null)
leave_ledger(id pk, t, employee_id, leave_type_id, leave_year smallint, entry_date, kind 'opening'|'accrual'|'taken'|'cancelled'|
             'carry_forward'|'lapse'|'encashed'|'adjustment', half_days int, source_id null, reason null, created_by)  -- append-only
leave_requests(id pk, t, employee_id, leave_type_id, from_date, to_date, half_days int, reason, stored_document_id null,
               status 'draft'|'submitted'|'approved'|'rejected'|'returned'|'cancelled', approval_id null)
```

### B.9.3 Invariants

- INVARIANT one employment record per employee per day; history is never updated: a change ends the record in force the day before the new one starts (like D-27).
- INVARIANT master data changes that affect pay (pay change, bank account, pay group, category, separation) apply only through an approved change request; the requester never approves it (`employee.manage` ✕ `employee.approve_change`, object).
- INVARIANT leave and attendance quantities are integer half-days (no floats); balance = Σ ledger; a request above the balance is refused unless the type allows negative balance (CQ-J5).
- INVARIANT an approved leave request writes `taken` ledger rows and `leave` attendance days in one transaction; cancelling writes `cancelled` rows (ledger append-only).
- INVARIANT a day in a closed payroll period (run posted) cannot change attendance or leave; corrections go to the next period as payroll inputs.
- Personal identifiers (TIN, NID) encrypted like party bank accounts; salary and identifiers visible only with `employee.view_confidential` (CQ-J9).

### B.9.4 State machines

```
employee:        onboarding ─first employment effective─▶ active ─separation applied─▶ separated
change request:  draft ─submit─▶ pending_approval ─approve─▶ approved ─effective date reached / immediately─▶ applied
leave request:   draft ─submit─▶ submitted ─approve─▶ approved ─cancel(before start, or approval)─▶ cancelled
                                   ├─return─▶ returned   └─reject─▶ rejected
```

Emitted: `EmployeeHired`, `EmploymentChanged`, `EmployeeSeparated` (People domain events; payroll invalidates previews on them), `LeaveApproved`.

### B.9.5 Posting, reconciliation, close

No accounting events from employment, attendance or leave. PD-21 (proposed): leave liability accrual (unused paid leave as a provision) is LATER unless the customer's accounting policy requires it (CQ-J5).

### B.9.6 Permissions and SoD

`employee.manage`, `employee.approve_change`, `employee.view_confidential`, `org.manage_reference` (departments, grades, calendars), `attendance.record`, `attendance.import`, `leave.manage_types`, `leave.approve` (HR step; line manager step by reporting line), `self.request_leave`, `self.view_profile`. SoD: `employee.manage` ✕ `employee.approve_change` (object); `attendance.record` ✕ `payroll.approve` (user, conservative; CQ-C3).

### B.9.7 Screens

Employees queue; employee page (Overview · Employment history · Leave · Attendance · Payslips · Documents · Audit) with *Change…* drawers creating change requests; change requests in the inbox; attendance grid per branch × month with import; leave calendar and requests; leave balances report; self-service pages (B.10.8).

### B.9.8 Dependencies

2.1; 2.2 for approvals; CQ-J2 (HR source and cut-over), CQ-J5 (leave policy), CQ-J6 (devices, LATER), CQ-H2 (time zone for attendance days).

---

## B.10 Payroll (slices 2.12, 2.13, 2.15, 2.16)

### B.10.1 Scope

| MVP | LATER |
|---|---|
| components (earnings, deductions, employer contributions, information) mapped to account roles | multi-country packages |
| rule sets versioned by country, company, policy, category and effective date; draft → approved → active → retired; the Bangladesh package as data (tax slabs, investment rebate if applicable, PF, gratuity, festival bonus) with `verify` flags | tax return filing, PF trust accounting (if a separate trust, CQ-J4) |
| payroll inputs: manual, attendance/leave deductions, commission (B.11), loan recoveries, one-off earnings | arrears recalculation across closed periods |
| monthly runs per pay group: preview → approve → post (per-employee lines) → bank file → payslips | off-cycle runs other than supplementary |
| loans and salary advances with installment recovery | loan interest (CQ-J7) |
| payslips as PDFs (DocumentGenerator, EN/BN), published to self-service | email delivery of payslips (needs contact details, A.12) |
| final settlement with approval and posting | |
| payroll reconcilers, close tasks | |

### B.10.2 Rule sets

PD-22 (proposed): payroll rule sets follow the rating pattern (D-20, D-21, D-32) rather than posting rules: a pure, deterministic calculator in `People\Payroll\Domain` with its own Symfony ExpressionLanguage instance, integer minor units and basis points, half-even division, no floats (arch test), and an explanation trace stored per payslip so a net pay can be re-explained later. Rules are data (spec §6): `payroll_rule_sets.definition` holds ordered steps and tables.

```sql
payroll_components(id pk, t, code, name_en, name_bn, kind 'earning'|'deduction'|'employer_contribution'|'information',
                   taxable bool, pf_base bool, gratuity_base bool, account_role, sort_order, status)       unique(t, code)
payroll_rule_sets(id pk, t, code, country char(2), entity_id null, category_code null, policy_code null, version smallint,
                  effective_from, effective_to null, status 'draft'|'approved'|'active'|'retired', verify bool,
                  definition jsonb {steps: [{order, code, component, expression, condition}], tables: [{code, kind 'slab'|'band'|'rate', rows}]},
                  created_by, approved_by null, activated_by null)
                  EXCLUDE one active set per (country, coalesce(entity), coalesce(category), coalesce(policy)) per date
                  -- immutable once approved (trigger), like rating plans
```

Calculation order (spec §6): earnings → gross → pre-tax deductions → taxable income (annualised by the tax year, CQ-J1) → tax from slabs → post-tax deductions (loans, advances) → net; employer contributions alongside. Selection: the most specific active set on the period end date (entity + category + policy > entity + category > entity > country), ambiguity refused (`PAYROLL_RULE_SET_AMBIGUOUS`), as posting rules do.

The Bangladesh configuration package is a seeded draft rule set with every value `verify = true`, never activated by a seeder in a real tenant; its values come from CQ-J1, CQ-J3, CQ-J4. **OPEN:** tax slabs, rebate rules, PF rates and base, gratuity formula, festival bonus rules — none are assumed in this design.

### B.10.3 Data model sketch (runs)

```sql
pay_groups(id pk, t, e, code, name, frequency 'monthly', pay_day smallint, bank_account_id, cut_off_day smallint null, status)
payroll_inputs(id pk, t, employee_id, period_year, period_month, component_code, amount_minor null, quantity_half_days null,
               source_type 'manual'|'attendance'|'leave'|'commission_statement'|'loan'|'expense_claim'|'bonus', source_id null,
               pre_accrued bool default false, status 'open'|'consumed'|'cancelled', consumed_by_run_id null, created_by null)
               unique(t, source_type, source_id, component_code) where source_id is not null
employee_loans(id pk, t, employee_id, number, kind 'loan'|'salary_advance', principal_minor, issued_on, installment_minor,
               first_recovery_period, balance_minor, status 'pending_approval'|'active'|'settled'|'written_off', approval_id)
employee_loan_recoveries(id pk, t, loan_id, payslip_id null, final_settlement_id null, amount_minor, recovered_on)
payroll_runs(id pk, t, e, pay_group_id, number null, kind 'regular'|'supplementary', period_year, period_month, period_id,
             status 'draft'|'calculated'|'pending_approval'|'approved'|'posted'|'paid'|'cancelled',
             inputs_hash null, rule_set_versions jsonb, employee_count, gross_minor, deductions_minor, employer_minor, net_minor,
             calculated_at null, prepared_by, approval_id null, posted_at null)
             unique(t, pay_group_id, period_year, period_month) where kind = 'regular' and status <> 'cancelled'
payslips(id pk, t, run_id, employee_id, employment_snapshot jsonb, number null, gross_minor, taxable_minor, tax_minor,
         deductions_minor, employer_minor, net_minor, bank_account_snapshot jsonb, trace jsonb, status 'calculated'|'published')
         unique(run_id, employee_id)
payslip_lines(id pk, t, payslip_id, component_code, kind, amount_minor, account_role, pre_accrued bool)
salary_bank_files(id pk, t, run_id, format_code, version, stored_document_id, sha256, total_minor, item_count, generated_by, generated_at)
final_settlements(id pk, t, employee_id, number null, separation_date, lines jsonb, net_minor,
                  status 'draft'|'pending_approval'|'approved'|'posted'|'paid'|'rejected', approval_id null, paid_on null)
```

### B.10.4 Posting

| Event | Lines | Key |
|---|---|---|
| `PAYROLL_POSTED` version 2 | for_each payslip: DR component expense roles (not pre-accrued) (B, EMP, CC); DR `employer_pf_expense`; CR `salary_payable` net − pre-accrued earnings (EMP); CR `employee_tax_payable`; CR `pf_payable` (employee + employer); CR `employee_loans_receivable` recoveries (EMP); DR `salary_payable` / CR `employee_tax_payable` for tax on pre-accrued earnings | `PAYROLL_POSTED:{payroll_run_id}` |
| `PAYROLL_PAID` | DR `salary_payable` for_each payslip net (EMP); CR `bank_main` (pay group bank) total | `PAYROLL_PAID:{payroll_run_id}` |
| `EMPLOYEE_LOAN_ISSUED` | DR `employee_loans_receivable` (EMP) / CR `bank_main` | `EMPLOYEE_LOAN_ISSUED:{employee_loan_id}` |
| `FINAL_SETTLEMENT_POSTED` | DR `leave_encashment_expense`, DR `gratuity_provision` (or `gratuity_expense`, CQ-J4), DR `salary_expense` for unpaid days; CR `employee_loans_receivable`; CR `employee_tax_payable`; CR `final_settlement_payable` | `FINAL_SETTLEMENT_POSTED:{final_settlement_id}` |
| `FINAL_SETTLEMENT_PAID` | DR `final_settlement_payable` / CR `bank_main` | `FINAL_SETTLEMENT_PAID:{final_settlement_id}` |
| `GRATUITY_ACCRUED` (if a provision, CQ-J4) | DR `gratuity_expense` / CR `gratuity_provision` per employee | `GRATUITY_ACCRUED:{employee_id}:{period_id}` |

`PAYROLL_POSTED` version 2 replaces the D-05 summary rule by a higher version from its effective date; version 1 stays on record; fixture `10_payroll_posted` stays green against version 1 and a new fixture `10b_payroll_posted_per_employee` freezes version 2.

Worked example (illustrative amounts; no Bangladesh rule is assumed). September run, two employees:

| | E1 | E2 | Total |
|---|---|---|---|
| Gross | 80,000 | 50,000 | 130,000 |
| Employee income tax | 4,000 | 0 | 4,000 |
| Employee PF | 8,000 | 5,000 | 13,000 |
| Employer PF | 8,000 | 5,000 | 13,000 |
| Loan recovery | 2,000 | 0 | 2,000 |
| Net | 66,000 | 45,000 | 111,000 |

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | salary_expense | 80,000 | | B, EMP E1, CC |
| 2 | salary_expense | 50,000 | | B, EMP E2, CC |
| 3 | employer_pf_expense | 8,000 | | B, EMP E1, CC |
| 4 | employer_pf_expense | 5,000 | | B, EMP E2, CC |
| 5 | salary_payable | | 66,000 | EMP E1 |
| 6 | salary_payable | | 45,000 | EMP E2 |
| 7 | employee_tax_payable | | 4,000 | EMP E1 |
| 8 | pf_payable | | 16,000 | EMP E1 |
| 9 | pf_payable | | 10,000 | EMP E2 |
| 10 | employee_loans_receivable | | 2,000 | EMP E1 |

Debits 143,000 = credits 143,000 (E2's zero tax line is dropped). `PAYROLL_PAID` with the bank file: DR salary_payable 66,000 (E1) and 45,000 (E2) / CR bank_main 111,000.

Final settlement example (illustrative): leave encashment 12,000, gratuity from provision 150,000, loan balance recovered 30,000, tax on encashment 1,200 → payable 130,800. DR leave_encashment_expense 12,000; DR gratuity_provision 150,000; CR employee_loans_receivable 30,000; CR employee_tax_payable 1,200; CR final_settlement_payable 130,800 (162,000 = 162,000).

### B.10.5 Invariants

- INVARIANT calculation is deterministic: the same rule-set versions, employment snapshots and inputs give the same payslips; the run stores the versions and an `inputs_hash`.
- INVARIANT approval is refused when any input, employment record, leave or attendance day of the period changed after calculation (`PAYROLL_INPUTS_CHANGED`, hash mismatch); the run returns to draft for recalculation.
- INVARIANT `payroll.prepare` ✕ `payroll.approve` (object); whoever approved a pay-affecting employee change since the previous posted run of the pay group does not approve the run, and whoever made one does not approve it either (`employee.manage` ✕ `payroll.approve`; kickoff §1.6) — PD-23 (proposed) enforces the second as a user rule (block) because SodGuard checks one object's trail and the change sits on the employee.
- INVARIANT Σ payslip nets = salary bank file total = `PAYROLL_PAID` credit (property test); every payslip with a positive net has a bank account snapshot (or a cash/cheque method, CQ-J3), otherwise approval is refused.
- INVARIANT one regular run per pay group and period; a posted run is immutable; corrections are payroll inputs in a supplementary run or the next period, never an edit (a reversal of the whole run is allowed only before `PAYROLL_PAID`, through the reversal workflow D-11).
- INVARIANT a pre-accrued input (commission paid through payroll) is shown on the payslip and counts for tax, but never posts its earning again (its liability was posted by `COMMISSION_PAYOUT_TO_PAYROLL`).
- INVARIANT loan recoveries never exceed the loan balance; a separation settles or writes off (approved) every open loan.
- INVARIANT payroll posts only into an open period; a run's period must be open when it is approved.

### B.10.6 State machines

```
payroll run: draft ─calculate─▶ calculated ─submit─▶ pending_approval ─approve─▶ approved ─post─▶ posted
               ▲    (recalculate)    │                   ├─return/reject─▶ draft
               └─────────────────────┘                   └── inputs changed ─▶ refused (back to draft)
             posted ─bank file generated (versioned)─▶ posted ─mark paid (PAYROLL_PAID)─▶ paid ─publish payslips─▶ paid
             draft|calculated ─cancel─▶ cancelled
rule set:    draft ─approve (≠ drafter)─▶ approved ─activate─▶ active ─retire/supersede─▶ retired
final settlement: draft ─submit─▶ pending_approval ─approve─▶ approved ─post─▶ posted ─pay─▶ paid
```

PD-24 (proposed): posting happens at approval in one step (approve → post in one transaction) unless CQ-J8 says finance posts separately; the diagram keeps them separate so either works.

### B.10.7 Reconciliation and close

Reconcilers: `payroll` (control `salary_payable`, dimension `employee`: posted nets + commission payouts to payroll − paid), `employee_loans` (control `employee_loans_receivable`), `final_settlement` (control `final_settlement_payable`). `COMMISSION_PAYOUT_TO_PAYROLL` version 2 adds the `employee` dimension (from `producers.employee_id`); version 1 journals lack it (drill-down only). Statutory liabilities (`employee_tax_payable`, `pf_payable`) are settled by AP bills to the tax authority or PF trust in MVP; a remittance register is LATER. Close tasks `payroll_reconciliation` and `payroll_posted` (B.2.8).

### B.10.8 Self-service (2.16)

Staff users linked to an employee (`employees.user_id`) see their own payslips (published runs only), leave balances and requests, expense claims, and profile. PD-25 (proposed): self-service is a staff web area, not the portal token model of D-16; access is "own records" checked by the service (the user's employee id), not a new scope type. Permissions `self.view_payslips`, `self.request_leave`, `expense.claim_submit`, held by a new `employee` role template assigned to every employee user.

### B.10.9 Jobs

| Job | Queue | When | Idempotent by |
|---|---|---|---|
| PayrollCalculateJob | batch | on demand per run, chunked by 500 employees | run status CAS, payslip unique |
| LeaveAccrualJob | batch | daily; accrues on each type's schedule | ledger unique (employee, type, year, kind accrual, entry date) |
| PayslipPublishJob | default | on demand after paid | generated document version per payslip |
| GratuityAccrualJob (if provision) | batch | month end, close | unique (employee, period) |

Posting through the outbox as for every event: one `PAYROLL_POSTED` event per run (key per run).

### B.10.10 Permissions and SoD

`payroll.manage_components`, `payroll.manage_rules`, `payroll.approve_rules`, `payroll.manage_inputs`, `payroll.prepare`, `payroll.approve`, `payroll.release` (bank file and mark paid), `payroll.view_reports`, `loan.request`, `loan.approve`, `settlement.prepare`, `settlement.approve`. SoD: `payroll.manage_rules` ✕ `payroll.approve_rules` (object); `payroll.prepare` ✕ `payroll.approve` (object); `payroll.approve` ✕ `payroll.release` (object); `employee.manage` ✕ `payroll.approve` (user, PD-23); `loan.request` ✕ `loan.approve` (object); `settlement.prepare` ✕ `settlement.approve` (object); `platform.manage_roles` ✕ `payroll.*` (user).

### B.10.11 Screens

Components and rule sets (plan-page pattern of R10a: Steps, Tables, Diff, Timeline, Audit, verify banner); pay groups; payroll inputs grid per period; run workbench: calculate → preview per employee with trace (like the rating breakdown) and differences from the previous month → submit → approve (with journal preview) → *Generate bank file* → *Mark paid* (journal preview) → *Publish payslips*; loans; final settlement drawer with computed components; reports payroll register, payroll summary by cost centre, tax deducted, PF contributions, bank advice.

### B.10.12 Dependencies

2.12 needs 2.10 and CQ-J1, CQ-J3, CQ-J4 for the Bangladesh package values (the engine can be built and tested on fixtures without them); 2.13 needs 2.12, 2.2, CQ-J3 (salary bank file format), PD-3 (for_each), and for the commission route PD-7; 2.15 needs 2.13 and CQ-J4; 2.16 needs 2.11 and 2.13.

---

## B.11 Commission payout route (slice 2.14)

What exists (D6, A-22): statements are paid by route `bank` (COMMISSION_PAID), `payroll` (COMMISSION_PAYOUT_TO_PAYROLL + message `CommissionPayrollEarning`) or `ap` (COMMISSION_PAYOUT_TO_AP + message `CommissionPayableToAp`). The route is chosen per producer (employee record → payroll, else by type). Phase 1 Q14 (customer) is unanswered; CQ-F3.

### Design

**Kept:** `commission.approve` ✕ `commission.pay` on the statement (1C.1 SoD); the liability moves at `pay`; Distribution and Insurance never use People or Finance.

**AP route** (needs 2.3, 2.4):
- `CommissionPayableToAp` consumer (Finance\Payables, PD-7) creates an `ap_bills` row: supplier = the producer's party (supplier record created on first use with status `active`, no bank account until one is approved), `source_type = commission_statement`, `payable_minor = net`, status `posted`, **no accounting event** (INVARIANT, B.4).
- It is paid in a payment run: `AP_PAYMENT_RELEASED` DR accounts_payable / CR bank_main.
- Example: statement net 4,750 → COMMISSION_PAYOUT_TO_AP (DR commission_payable 4,750 / CR accounts_payable 4,750) → AP bill BIL-HO-2026-000031 (no journal) → payment run releases 4,750 (DR accounts_payable / CR bank_main).

**Payroll route** (needs 2.13):
- `CommissionPayrollEarning` consumer (People\Payroll) creates a `payroll_inputs` row: employee = `employee_id` from the message, component `commission`, `pre_accrued = true`, period of `period_end`, unique by statement id.
- A message whose employee is unknown or separated is parked with reason `EMPLOYEE_UNKNOWN` / `EMPLOYEE_SEPARATED` and shown on the payroll inputs screen; it is never dropped or guessed.
- Example: BDO E2 statement net 10,000 → COMMISSION_PAYOUT_TO_PAYROLL (DR commission_payable 10,000 / CR salary_payable 10,000, EMP E2 in version 2). E2's September payslip shows commission 10,000; if the tax rules make it taxable and add 1,000 tax, `PAYROLL_POSTED` adds DR salary_payable 1,000 / CR employee_tax_payable 1,000 for E2 and the other lines stay as in B.10.4; net pay 45,000 + 10,000 − 1,000 = 54,000; `PAYROLL_PAID` DR salary_payable 54,000 for E2; E2's salary_payable balance: +45,000 +10,000 −1,000 −54,000 = 0.
- **OPEN** withholding: commission already carries withholding at accrual (`commission_withholding_payable`, A-6/D4); taxing it again as salary would withhold twice. Whether commission to employees is salary income, commission income with withholding, or both is CQ-F4. Until answered, 2.14's payroll route is blocked (no default is proposed).

**Invariants.**
- INVARIANT a commission statement produces at most one AP bill and one payroll input (unique by statement id).
- INVARIANT GL `salary_payable` per employee and `accounts_payable` per payee reconcile including commission payouts (B.4, B.10.7).
- INVARIANT a statement's route is fixed at approval (`paid_via`); changing the route after payment needs a reversal of the payout event (D-11 workflow).

**Screens.** Statement workbench shows the route and, once consumed, links to the AP bill / payroll input; the payroll inputs grid marks commission rows as "already in the accounts".

---

## B.13 State machines (index)

| Object | Section |
|---|---|
| workflow instance (approval) | B.3 |
| AP bill, payment run | B.4 |
| AR invoice, credit note, AR receipt | B.5 |
| expense claim, petty cash replenishment | B.6 |
| fixed asset | B.7 |
| budget version | B.8.1 (draft → submitted → approved → superseded) |
| employee, employment change request, leave request | B.9.4 |
| payroll rule set, payroll run, final settlement | B.10.6 |
| supplier bank change | B.4 (pending → applied \| rejected) |

## B.14 Permissions, role templates and SoD (proposed)

### B.14.1 Role templates

New templates are added by migration to existing tenants' role sets only as new roles; existing roles are not changed except where "+" says so, and an existing tenant's edited role keeps its edits (as for R-slice grants). Every assignment is still checked by `HeldPermissionsPolicy`.

| Template | New / changed | Permissions (Phase 2 only) |
|---|---|---|
| AP Clerk | new | `supplier.manage`, `ap.bill_create`, `ap.payment_run_create`, `ar.invoice_create`, `ar.receipt_create`, `ar.receipt_allocate` |
| Accountant | + | `ar.invoice_create`, `ar.receipt_create`, `ar.receipt_allocate`, `ar.credit_note_request`, `asset.register`, `petty_cash.replenish_request`, `budget.prepare`, `cashflow.manage` |
| Finance Manager | + | `ap.bill_approve`, `ap.payment_run_approve`, `supplier.approve_bank_change`, `ar.credit_note_approve`, `expense.claim_approve`, `petty_cash.replenish_approve`, `asset.manage_classes`, `asset.dispose_approve`, `payroll.approve_rules`, `loan.approve`, `reports.regulatory` (flow audit, CQ-C3) |
| CFO | + | `budget.approve`, `settlement.approve` |
| Treasury | new | `ap.payment_run_release`, `payroll.release`, `bank.match`, `bank.import` |
| Petty Cash Custodian | new | `petty_cash.voucher_create` |
| HR Officer | new | `employee.manage`, `org.manage_reference`, `attendance.record`, `attendance.import`, `leave.manage_types` |
| HR Manager | new | HR Officer + `employee.approve_change`, `employee.view_confidential`, `leave.approve`, `settlement.prepare` |
| Payroll Officer | new | `payroll.manage_components`, `payroll.manage_rules`, `payroll.manage_inputs`, `payroll.prepare`, `loan.request`, `payroll.view_reports` |
| Payroll Manager | new | Payroll Officer + `payroll.approve`, `employee.view_confidential` |
| Employee | new | `self.view_payslips`, `self.request_leave`, `self.view_profile`, `expense.claim_submit`, `approval.delegate` |
| Tenant Admin | + | `approval.manage_delegations` |
| Auditor | + (read-only list) | `payroll.view_reports` only if CQ-J9 allows auditors to see individual pay |

Conflicts inside the proposal that SoD resolves per object (so templates may hold both): Finance Manager holds `ap.bill_approve` and, through Accountant, nothing that creates bills; Payroll Manager holds `payroll.prepare` and `payroll.approve` (object rule). Treasury is separate from Finance Manager so approve ✕ release can be user-level if the customer wants it (CQ-C3).

### B.14.2 SoD rules to seed

| A | B | applies_to | Why |
|---|---|---|---|
| `ap.bill_create` | `ap.bill_approve` | object | kickoff §1.6 |
| `ap.payment_run_create` | `ap.payment_run_approve` | object | spec §5 create → approve → release |
| `ap.payment_run_approve` | `ap.payment_run_release` | object | kickoff §1.6 |
| `ap.payment_run_create` | `ap.payment_run_release` | object | spec §5 |
| `supplier.manage` | `supplier.approve_bank_change` | object | payee bank fraud |
| `supplier.approve_bank_change` | `ap.payment_run_release` | user | payee bank fraud |
| `ar.credit_note_request` | `ar.credit_note_approve` | object | |
| `ar.invoice_create` | `ar.credit_note_approve` | object | |
| `petty_cash.replenish_request` | `petty_cash.replenish_approve` | object | |
| `petty_cash.voucher_create` | `petty_cash.replenish_approve` | user | custodian does not approve own cash |
| `asset.dispose_request` | `asset.dispose_approve` | object | |
| `budget.prepare` | `budget.approve` | object | |
| `employee.manage` | `employee.approve_change` | object | |
| `employee.manage` | `payroll.approve` | user | kickoff §1.6, PD-23 |
| `payroll.manage_rules` | `payroll.approve_rules` | object | like rating plans |
| `payroll.prepare` | `payroll.approve` | object | kickoff §1.6 |
| `payroll.approve` | `payroll.release` | object | |
| `loan.request` | `loan.approve` | object | |
| `settlement.prepare` | `settlement.approve` | object | |
| `platform.manage_roles` | `ap.*`, `ar.*`, `payroll.*`, `employee.*` | user | extends v1 "admins don't post" |

Which object rules the customer wants as user rules (stricter) is CQ-C3.

## B.15 Idempotency, outbox, jobs (Phase 2)

- Every event's idempotency key is derived from its source row id (tables above); batch events use natural uniques (`asset_depreciation`, leave ledger accruals, gratuity accruals) so reruns are no-ops.
- Source row + accounting event + outbox row commit in one transaction (non-negotiable #8); bank files and payslip PDFs are generated after commit and stored append-only with their hash.
- Payroll posting is one event per run; the calculation is a batch job but the posting is not split.
- Outbox consumers (PD-7) for `CommissionPayableToAp`, `CommissionPayrollEarning`; later candidates `DunningNoticeDue`, `ProducerLicenceExpiring` (notifications).
- New scheduled jobs: `ApprovalSlaJob` (every 5 min), `DepreciationJob` (nightly, batch), `LeaveAccrualJob` (daily, batch), `OutboxConsumerRelayJob` (every few seconds, default), optional `BudgetVarianceJob` (LATER). All loop over tenants per D-07 and run on the clock chosen in CQ-H2.

## B.16 Testing strategy (Phase 2)

| Layer | Additions |
|---|---|
| Golden fixtures | one per new rule: `11_ap_bill_posted`, `11b_ap_bill_cancelled`, `12_ap_payment_released`, `13_ar_invoice_posted`, `13b_ar_credit_note_posted`, `14_ar_receipt_recorded`, `14b_ar_receipt_allocated`, `15_expense_claim_approved`, `16_petty_cash_voucher_posted`, `16b_petty_cash_replenished`, `17_asset_capitalised`, `18_depreciation_posted`, `19_asset_disposed`, `10b_payroll_posted_per_employee`, `10c_payroll_paid`, `20_final_settlement_posted`, version 2 fixtures for the two commission payout rules; kernel fixture for `for_each` with zero-line items |
| Pure calculators | payroll calculator golden fixtures `tests/Fixtures/payroll` (worked by hand, like rating); depreciation schedule unit tests |
| Property tests | Σ payslip nets = bank file total; Σ depreciation = cost − residual across random lives, residuals and disposal months; Σ payment items = run total = bank file total; leave balance = Σ ledger after random request/cancel sequences; claim reserve lifecycle (2.0d) |
| Invariant tests | duplicate bill refused; payable never in two runs; approved rule set and budget immutable (trigger); posted run immutable; inputs changed after calculation refuse approval |
| Isolation | every new tenant table populated in `TenantIsolationEveryTableTest` |
| SoD | every B.14.2 pair in both directions; delegation cannot bypass maker ≠ checker |
| Close | AP/AR/expense/asset/payroll reconcilers clean over three month ends with activity; a stray manual posting to each control blocks its task; lock refused with variance |
| Workflow | existing approval tests unchanged; parallel all/any/quorum; SLA reminder and escalation once; rework round trip; withdraw; unstaffed step refused |
| E2E (Playwright, after 2.0c) | bill → approve → payment run → release → bank file → statement import → match; payroll run → approve → post → bank file → mark paid → statement match |
| Architecture | B.1 new rules; no floats in payroll and fixed assets |

## B.17 MVP vs LATER per module (summary)

| Module | MVP | LATER |
|---|---|---|
| Workflow | parallel/quorum, reporting-line steps, delegation, SLA reminders and escalation, rework, withdraw | designer, business-hour calendars, auto-decisions |
| AP | suppliers, bills with VAT/VDS/TDS, approval, payment runs, bank files, commission and claim payables, reconciler | OCR, POs and matching, debit notes on part-paid bills, multi-currency, payment returns |
| AR | invoices, credit notes, receipts, allocation, printed invoices, reconcilers | recurring invoices, AR dunning, cross-allocation from premium suspense |
| Expenses | claims with receipts, petty cash imprest, counts | per diem, cards, payroll reimbursement |
| Fixed assets | classes, register, depreciation, disposal, transfer, reconcilers | revaluation, impairment, tax book, barcode counts |
| Budget | annual versions by account × month × branch × cost centre, import, variance | budget control, alerts, driver-based |
| Cash flow | 13-week forecast from due items and manual inputs, actual vs forecast | scenarios, curves |
| People | employees, effective-dated employment, change requests, attendance manual/import, leave | devices, rosters, recruitment, performance |
| Payroll | components, rule sets, Bangladesh package as data, inputs, loans, runs, bank files, payslips, final settlement, reconcilers | tax returns, PF trust accounting, arrears across closed periods, loan interest |
| Self-service | payslips, leave, claims, profile | mobile app, payslip email |
| Commission route | AP and payroll consumers | — |

## B.18 Slice list: proposed changes to kickoff §2

| Slice | Change | Why |
|---|---|---|
| new 2.1b | business clock and time zone for defaults and schedules; close warning/block for pending documents; early lock rule | CQ-H2, CQ-C4, CQ-C5; small, cross-cutting, before 2.2 |
| new 2.1c | numbering fix for receipts, claims, agent deposits across branches | A.7.6; before multi-branch go-live |
| 2.2 | + outbox consumer relay (PD-7) | needed by 2.14 and useful for notifications |
| 2.3 | + kernel `for_each` line groups (PD-3, PD-4) and cost centres (PD-5) | first slice that needs variable lines |
| 2.6 | split: 2.6a petty cash (after 2.2), 2.6b expense claims (after 2.4 and 2.10) | claimant is an employee (PD-15) |
| 2.7 | capitalisation from bills after 2.3 | asset clearing |
| 2.9 | payroll source after 2.13 | cash-flow source contract |
| 2.14 | AP route after 2.4; payroll route after 2.13 **and** CQ-F4 | double withholding OPEN |

## B.19 OPEN items for Phase 2 (do not invent)

Every item is a customer question; defaults are proposed only where the kickoff or existing design already implies a conservative one.

| # | OPEN | Blocks | CQ |
|---|---|---|---|
| 1 | Payroll jurisdiction, tax year, slabs, rebates, PF, gratuity, festival bonus | 2.12 values, 2.13, 2.15 | J1, J4 |
| 2 | HR master data source and cut-over | 2.10 go-live data | J2 |
| 3 | Bank payment and salary file formats per bank | 2.4, 2.13 | I1, J3 |
| 4 | Depreciation methods, classes, lives, residuals, threshold, disposal month | 2.7 | I8 |
| 5 | Budget granularity and approvers | 2.8 | I9 |
| 6 | Non-premium income invoiced; its VAT and customer withholding | 2.5 | I4, B5 |
| 7 | Approval routing: sequential/parallel, delegation, escalation, SLA calendar | 2.2 | C6, C7, C8 |
| 8 | OCR on supplier bills now or later | scope of 2.3 | I3 |
| 9 | Commission payout route and the tax treatment of commission paid through payroll | 2.14 | F3, F4 |
| 10 | Supplier VAT recoverability, VAT deducted at source, tax withheld at source | 2.3 | B3, B4 |
| 11 | Petty cash policy (imprest, limits, count frequency) | 2.6a | I7 |
| 12 | Expense reimbursement route and limits | 2.6b | I6 |
| 13 | Leave policy (types, accrual, carry forward, negative balance, leave liability) | 2.11 | J5 |
| 14 | Who may see individual pay; auditor access | 2.10, 2.13 | J9 |
| 15 | Posting of payroll by payroll or by finance; month-end payroll task blocking or warning | 2.13, close | J8 |
| 16 | Loan and salary advance rules | 2.13 | J7 |
| 17 | Line-manager approvals for leave and claims | 2.2, 2.11, 2.6b | C6 |
| 18 | Maximum payroll size per run (engineering, for review) | PD-3 | — |
