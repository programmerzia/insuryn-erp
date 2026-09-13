# Phase 1 exit checklist

Checks Phase 1 as built against the product spec's roadmap (spec §11) and the design's test strategy
(design §9.1, §9.2). Every "done" row names the slice and the tests that prove it. Slice details are in
`docs/PROGRESS.md`; assumptions (A-n) are in its register and go to the customer as `customer-questions.md`.

**Phase 1 outcome (spec §11):** "Customer can run daily operations and close a month."

State at exit: slices 0.0 → 1C.11 committed; 956 tests green on PHP 8.5 and PHP 8.4;
PHPStan level 8 with 0 errors; vue-tsc and vite build green. 23 posting rules with 24 golden fixtures; every
emitted event type has exactly one rule and at least one fixture.

Status key: **done** · **partial** (built, but part of the spec line is missing) · **waiting** (built the
cautious way, needs a customer answer) · **gap** (not built and needed before go-live) · **later**
(the design puts it in a later phase).

---

## 1. Phase 1 scope (spec §11, detail from spec §4, §5)

| Scope line | Status | Built in | Evidence (tests) | Not built / notes |
|---|---|---|---|---|
| Party model | done | 1A.1, 1C.8 | `Insurance/PartyApiTest`, `Pages/PartiesProductsPoliciesPagesTest` | KYC documents (no attachments yet, see §4) |
| Product catalogue (effective-dated versions, tax, commission, earning method) | waiting | 1A.2, 1C.8 | `Insurance/ProductCatalogueTest`, `Unit/Insurance/EarningScheduleTest` | 24ths and short-rate refused (A-4, Q8); coverages are a stored list with no per-coverage pricing |
| Policy lifecycle quote → issue → endorse → cancel / lapse / reinstate / renew / expire | done | 1A.3, 1C.8 | `Insurance/PolicyLifecycleTest` | |
| Written / earned / unearned per policy per period | done | 1A.4 | `Insurance/PremiumEarningTest`, `Unit/Insurance/EarningScheduleTest` (datasets: Σ earned = net premium) | |
| Installments, dunning, grace, auto-lapse | waiting | 1A.4, 1C.4 | `Insurance/DunningTest` | Schedule is A-10 (Q10); notices are recorded, not delivered |
| Multi-payer | waiting | 1C.5 | `Insurance/MultiPayerTest` | Refund split between payers (Q9) |
| Pro-rata and short-rate cancellation | partial | 1A.3 | `Insurance/PolicyLifecycleTest` | Pro-rata only (A-4, Q8) |
| Agent cash collection with deposit reconciliation | done | 1C.3, 1C.9 | `Insurance/AgentCashTest` | |
| Payment channels bank, card, mobile money, cheque | partial | 1A.5, 1C.2 | `Insurance/CollectionsTest`, `Insurance/ChequeBounceTest` | Channels are recorded; no card or mobile-money gateway integration |
| Cheque register and bounce handling | done | 1C.2, 1C.9 | `Insurance/ChequeBounceTest`, `Pages/CollectionsBankPagesTest` | Bounce after cancellation is refused (Q12) |
| Billing / collections: receipts, allocations, refunds (maker ≠ checker) | done | 1A.5, 1C.9 | `Insurance/CollectionsTest`, `Platform/SegregationOfDutiesTest` | |
| Suspense | done | 1A.5, 1C.9 | `Insurance/CollectionsTest`, `Insurance/SubledgerReconciliationTest` | |
| Bank: statement import, matching, exception queue | waiting | 1A.6, 1C.9 | `Finance/BankReconciliationTest`, `Pages/CollectionsBankPagesTest` | File format is A-5 (Q11); no bank feeds |
| Commission: earned on receipt, clawback netting, withholding, payout with SoD | waiting | 1A.7, 1C.1, 1C.10 | `Insurance/CommissionTest`, `Insurance/CommissionPayoutTest` | Flat rate only (A-6, A-7, Q13); hierarchy overrides not built |
| Commission DAC | done (MVP) | 1A.7 | `Insurance/CommissionTest` | Expensed on receipt per design §9.2; capitalise + amortise is later |
| Claims: register → reserve → adjust → approve → pay → recover → close, reopen | done | 1B.1, 1C.10 | `Insurance/ClaimsTest`, `Pages/ClaimsCommissionApprovalsPagesTest` | |
| Claims: immutable reserve history | done | 1B.1 | `Insurance/ClaimsTest` ("keeps reserve history immutable in the database") | |
| Claims: approval limits by amount / role | waiting | 1B.1 | `Insurance/ClaimsTest` ("routes approvals … through approval_policies") | No limits seeded (A-2, Q5) |
| Claims: deductibles, co-insurance, batch payments, SLA timers | gap | — | — | Not built. Decide with the customer whether go-live needs them |
| Claims reports: outstanding, loss ratio by dimension, paid register | done | 1B.3, 1C.11 | `Reports/ClaimsReportsTest` | Development triangles not built |
| Claims: IBNR, reinsurance recoverable | later | — | — | Phase 3 (spec §11) |
| Basic tax (rate table, inclusive/exclusive, withholding) | waiting | 0.0, 1A.2, 1A.7 | `Accounting/GoldenRulesTest` (01, 04, 05) | Rates and cancellation refund are A-1 (Q3, Q4) |
| Trial balance, P&L, balance sheet, drill to journals | done | 0.6, 1A.10, 1C.11 | `Reports/ReportsTest`, `Accounting/LedgerPagesTest`, `Pages/CloseReportsJournalsPagesTest` | P&L and BS are cumulative: no year-end close into retained earnings yet |
| Premium register, receivable ageing, suspense ageing, commission statement | done | 1A.10, 1C.9–1C.11 | `Reports/ReportsTest` | Ageing uses current outstanding (A-9) |
| Subledger reconciliation (premium, suspense, commission, claims) | done | 1A.8, 1B.2 | `Insurance/SubledgerReconciliationTest`, `Insurance/ClaimsReconciliationTest` | |
| Month-end close tasks 1–6, 8, 13–16 | done | 1A.9, 1B.2, 1C.11 | `Close/MonthEndCloseTest`, `Close/LockRefusesUnreconciledPeriodTest` | Tasks 7, 9–12 are later (design §9.2). CFO approval on lock not built (Q6) |
| Screens for daily operations | done | 1C.7–1C.11 | the four `tests/Feature/Pages/*` files, `Platform/AccountSecurityTest` | Visual check of every new screen in headless Chrome after 1C.11 |
| Reinsurance (facultative only if needed) | waiting | — | — | Q17 |

## 2. Test strategy (design §9.1)

| Layer | Status | Evidence | Notes |
|---|---|---|---|
| Invariant tests (kernel) | done | `Accounting/InvariantsTest`, `Accounting/SchemaIntegrityTest`, `Platform/SchemaInvariantsTest` | |
| Posting-rule golden tests | done | `Accounting/GoldenRulesTest` over `tests/Fixtures/golden` | 24 fixtures; §4 examples frozen |
| Idempotency tests | done | `Accounting/InvariantsTest`, `Accounting/OutboxRelayTest`, `Accounting/PostingFailureTest`, `Accounting/ReversalRaceTest` | |
| Tenancy tests, every tenant table | done | `Platform/TenantIsolationTest`, `Platform/TenantIsolationEveryTableTest` (57 tables, none exempt) | |
| SoD / permission tests | done | `Platform/SegregationOfDutiesTest`, `Platform/PermissionsTest`, `Insurance/CommissionPayoutTest`, page tests | |
| Close tests | done | `Close/MonthEndCloseTest`, `Close/LockRefusesUnreconciledPeriodTest`, `Accounting/ReversalApprovalTest`, `Accounting/PostingLockRaceTest` | |
| Property tests | partial | `Unit/Insurance/EarningScheduleTest` (datasets over methods and endorsements) | No generator-based test for "reserve lifecycle Σ = 0 at close" |
| Static: PHPStan L8, architecture test | done | `phpstan.neon`, `tests/Architecture/DependencyTest.php` | |
| **E2E (Playwright): issue policy → receive → earn → close month** | **gap** | — | Required by §9.1 and CI-blocking. The HTTP page tests cover the same flow without a browser |
| Performance smoke (k6, 10k events < 60s) | gap | — | Not CI-blocking (§9.1 DECISION) |
| **CI pipeline that blocks merge** | built in slice 2.0b | `.github/workflows/ci.yml` + `scripts/ci/`; required checks to set once hosted | §9.1 DECISION: CI blocks merge on all layers except the performance smoke |

## 3. Kernel non-negotiables (CONTEXT.md)

| # | Rule | Evidence |
|---|---|---|
| 1 | Journals balance | `Accounting/InvariantsTest`, DB trigger `journal_must_balance` (D-01) |
| 2 | Posted journals immutable; corrections by reversal | `Accounting/InvariantsTest`, `Accounting/ReversalApprovalTest` |
| 3 | Locked periods reject; soft-lock needs permission | `Accounting/InvariantsTest`, `Accounting/ReversalSoftLockTest`, `Accounting/PostingLockRaceTest` |
| 4 | Only PostingEngine writes journals | `tests/Architecture/DependencyTest.php`; review pass grep (0 business-module writes) |
| 5 | Money in minor units, no floats | review pass grep; `Unit/Accounting/MinorUnitsTest`, `Unit/Accounting/BasisPointsTest` |
| 6 | Tenant isolation with forced RLS | `Platform/TenantIsolationEveryTableTest` |
| 7 | Idempotent posting | `Accounting/InvariantsTest`, `Accounting/OutboxRelayTest` |
| 8 | Source row, event and outbox in one transaction | `Accounting/SubmitAccountingEventTest`, `Accounting/OutboxRelayTest` |
| 9 | Maker ≠ checker on refunds, claim payments, manual journals, commission payouts | `Platform/SegregationOfDutiesTest`, `Insurance/ClaimsTest`, `Accounting/ManualJournalTest`, `Insurance/CommissionPayoutTest` |
| 10 | Dependency direction | `tests/Architecture/DependencyTest.php` |

## 4. Cross-cutting items outside the Phase 1 scope line

Spec §7 and §9 name these for the platform, but spec §11 does not list them for Phase 1. Decide per item whether
go-live needs them.

| Item | State |
|---|---|
| **User and role administration** (invite users, assign roles by branch) | built in slice 2.0a: `/admin/users` and `/admin/roles` (invite, roles by tenant/entity/branch with SoD checks, deactivate, role permissions) |
| Attachments on transactions (KYC, claim documents) | not built |
| Email / SMS notifications (dunning, claim status) | dunning notices queued on the outbox; no delivery |
| English / Bangla | English only |
| Global search, role dashboards | not built |
| SSO / OIDC (Zitadel) | later (CONTEXT.md); Fortify login with TOTP two-factor is built |
| API keys, per-tenant feature flags, field-level permissions | not built |
| Transaction-mode connection pooling | unsupported (D-02) |

## 5. Exit decision

Phase 1 is **feature-complete for the spec §11 scope** except the claims items marked gap, and the kernel
rules are enforced by tests. Before calling Phase 1 **go-live ready**:

1. **Engineering (no customer input needed):**
   - add user and role administration screens for the Tenant Admin over `RoleAssignmentService`;
   - add the CI pipeline (pest on PHP 8.4, PHPStan, vue-tsc, build) that blocks merge;
   - add the Playwright happy path (issue policy → receive → earn → close month) with a seeded demo data set;
   - add a generator-based property test for the claim reserve lifecycle.
2. **Customer answers:** Q1, Q3, Q5, Q8 and Q16 change postings, approvals or the go-live data and are needed
   before go-live. The rest can be answered during the pilot.
3. **Scope decisions with the customer:** claims deductibles, co-insurance, batch payments and SLA timers; the
   cross-cutting items in §4.

Phase 2 design can start now in parallel: it does not depend on the open answers except Q14 (commission paid through
payroll or payables). See `docs/phase-2/kickoff.md`.
