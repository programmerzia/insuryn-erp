# Insurance ERP — Product & Architecture Spec v2

Merged from: Feature Spec v1 + Production-Grade Review. Items marked **[NEW]** come from the review and are adopted; **[CHANGED]** means I disagree with the review or v1 and corrected it; **[ADDED]** is new in v2 (missing from both).

Positioning (adopted from review): *An insurance-native financial and operations platform where policy, premium, claims, commission, reinsurance, payroll and finance share one auditable accounting foundation.*

---

## 0. Decisions that shape everything

| Question | Decision |
|---|---|
| Carrier vs broker? | **Must be confirmed with customer before Phase 1.** Broker → reinsurance, reserves, IFRS 17 mostly drop out. Spec below assumes carrier (non-life) unless stated. |
| Architecture | Modular monolith, strict module boundaries, one database with schema-per-module. Extract services only when load proves it. **[NEW]** |
| Stack | .NET 8/9, EF Core, PostgreSQL (row-level security for tenancy), React/Next.js, Redis, Hangfire/Quartz for jobs. **[ADDED — pick your stack, don't let the agent pick]** |
| Accrual basis | Accrual accounting only; no cash-basis mode. **[ADDED]** |
| Tenancy | Tenant → Legal Entity → Branch hierarchy modelled from day 0, even if multi-entity consolidation UI ships late. **[CHANGED — review put multi-entity in Phase 4; the data model can't wait]** |

---

## 1. Layered structure **[NEW]**

```
Business domains:  Insurance | Finance | People | Compliance
                              ↓ accounting events
Accounting kernel: Posting Engine · Journal · Periods · Dimensions · Reconciliation
                              ↓
Platform kernel:   Tenancy · Identity/RBAC · Workflow · Audit · Documents · Config · Tax
```

Rule: business modules **never** touch journal tables. They emit an accounting event; the posting engine turns it into balanced journal lines via versioned posting rules.

---

## 2. Accounting kernel

### Invariants (enforced in code, tested, non-negotiable)
1. Every journal balances (per currency, per entity).
2. Posted journals are immutable; corrections by reversal only.
3. Locked periods reject postings; reopening is a workflow with audit.
4. Every line carries the dimensions its transaction type requires.
5. Posting is idempotent — same event ID posted twice yields one journal. **[NEW]**
6. Every journal traces to an accounting event → business transaction → source document.
7. Config (COA mappings, posting rules, tax, commission, payroll) is versioned and effective-dated. **[NEW]**
8. Maker-checker and segregation of duties cannot be bypassed, including by AI or integrations. **[NEW]**

### Three dates on every journal **[ADDED]**
- Transaction date (when it happened), Posting date (which period), Effective date (for earning/accrual).
- Back-dating policy: allowed only into open periods and only with a permission.

### Posting engine
- Input: `AccountingEvent { Id, Type, EntityId, OccurredAt, Amounts, Dimensions, SourceRef }`.
- Posting rule = event type + product/package + effective range → line template (account role, DR/CR, amount expression).
- Accounts referenced by **role** (e.g. `UnearnedPremium`) mapped to COA per tenant/entity — packages never reference GL codes directly.
- Outbox pattern: event persisted with source transaction, posted by a worker; failures land in an exception queue, never lost. **[ADDED]**
- Journal batch groups lines from one event; multi-book produces one journal per book from the same event.

### Explicit event catalogue **[NEW]** (subset; the full list is the review's §4)
Policy: ISSUED, ENDORSED, INSTALLMENT_DUE, PREMIUM_RECEIVED, PREMIUM_EARNED, CANCELLED, REINSTATED, LAPSED, RENEWED, REFUND_ISSUED
Claims: REGISTERED, RESERVED, RESERVE_ADJUSTED, APPROVED, PAID, RECOVERED, CLOSED
Commission: EARNED, CLAWBACK, PAID · Reinsurance: CEDED, RECOVERABLE, SETTLED · Payroll: CALCULATED, APPROVED, POSTED, PAID

### Subledgers → control accounts **[NEW, trimmed]**
Phase-1 subledgers: Premium (receivable), Claims (payable/reserves), Commission (payable), Bank, Customer, Agent. Phase-2+: AP, AR, Fixed Assets, Payroll, Investments, Reinsurance.
Each subledger has a nightly + on-demand reconciliation job: subledger total − GL control balance = variance → exception queue. **[CHANGED — review listed 11 subledgers up front; build 6, add the rest with their modules]**

### Dimensions
Mandatory per transaction type (configurable): Entity, Branch, Product/Package, Line of Business, Channel, Agent, Policy, Claim, Cost Centre, Employee, Customer, Reinsurer. Stored as typed columns for the fixed set + a JSONB/extension slot for tenant-defined ones.

### Suspense / unallocated receipts **[ADDED — real-life insurer pain point]**
Money arrives before it can be matched to a policy (agent deposits, bank transfers with bad references). Post to Suspense, surface in an allocation queue, auto-suggest matches, re-post on allocation. Ageing report on suspense.

### Document numbering **[ADDED]**
Per-entity, per-branch, per-document-type sequences with fiscal-year reset and no gaps (regulators check receipt numbering).

---

## 3. Party model **[NEW]**
`Party (Individual | Organization)` with roles: Customer, Policyholder, Insured, Beneficiary, Agent, Broker, Employee, Vendor, Reinsurer. One person can hold several roles. KYC documents and bank accounts attach to the Party, not the role. Agents are **not** employees.

---

## 4. Insurance domain

### Product / package catalogue
Effective-dated versions; pricing, term, coverages, tax rules, commission schedule, posting rule set, earning method (365ths / monthly / 24ths / custom).

### Policy & premium lifecycle
Quote → Issue → Endorse → Bill → Collect → Earn → Renew | Cancel | Lapse | Expire. Every transition emits events. Written/Earned/Unearned kept per policy per period. Installments, dunning, grace, auto-lapse. Multi-payer. Pro-rata and short-rate cancellation tables. Agent cash collection with deposit reconciliation. Payment channels: bank, card, mobile money, cheque (with cheque register and bounce handling **[ADDED]**).

### Claims
Register → Reserve → Adjust → Approve → Pay (partial/batch) → Recover (salvage, subrogation, third party) → Close. Deductibles, co-insurance, reinsurance recoverable auto-calc. Reserve history immutable. IBNR from actuarial input or configurable ratio. Approval limits by amount/role, SLA timers. Reports: outstanding claims, loss ratio by any dimension, development triangles.

### Commission
Hierarchy with overrides; rules per product/term/year/tier; earned on receipt; clawback netting; DAC amortized with earned premium; withholding tax via tax engine; payout via payroll or AP; agent portal. **[unchanged — review rated this strong]**

### Reinsurance **[NEW depth, phased]**
Phase 1: none (or facultative only if customer needs it). Phase 3: treaty versions with effective periods, lines, retention, cession, limits/layers, commission, profit commission, reinstatements; premium and claim cession per treaty; bordereaux; reinsurer AR/AP netting and settlement; recoverable ageing; disputes.

### Investments (Phase 3)
Register, accrued interest, valuation, maturity; posts via events like everything else.

---

## 5. Finance domain
Bank (feeds/statement import, matching, exception queue) — **Phase 1, not Phase 2** **[CHANGED — you cannot close a month without bank]**. AP with OCR, AR (non-premium), expenses & petty cash, fixed assets, budgeting & variance, cash-flow forecast, close checklist. Payment runs with Create → Approve → Release under SoD.

---

## 6. People domain (Phase 2)
Employee model and effective-dated employment lifecycle **[NEW]**. Attendance, leave, benefits, final settlement.
Payroll rules engine **[NEW]**: earnings → gross → pre-tax deductions → taxable → tax (slabs from tax engine) → post-tax deductions (loans, advances) → net. Rules versioned by country/company/policy/effective date/employee category. Bangladesh rules (tax slabs, PF, gratuity, bonus) are a configuration package, not code. Payroll run: preview → approve → post (configuration-driven journal) → bank file → payslips. Agent commission payout rides the same run. Employee self-service.

---

## 7. Platform kernel

- **Tax engine** **[NEW, MVP-sized]**: Phase 1 = effective-dated rate table by jurisdiction/tax type with inclusive/exclusive and withholding; reused by premium, AP, AR, payroll, commission. Full exemption/reporting engine later. **[CHANGED — review's full engine is Phase 3 material]**
- **Workflow engine**: Phase 1 = maker-checker + amount-threshold approval with delegation. Phase 2 = sequential/parallel routing, escalation, SLA, rework. **[CHANGED — review put the full engine in Phase 0]**
- **Segregation of duties** **[NEW]**: role conflicts defined as policy (e.g. creator ≠ approver ≠ releaser); enforced at action time.
- **Audit**: two layers **[NEW]** — technical (user, IP, device, request, before/after) and business (Reserve increased, Journal reversed, Period reopened, Treaty amended). Business audit is what auditors read.
- **Security**: tenant isolation (RLS), RBAC + field-level, MFA, SSO/OIDC, API keys, rate limits, secrets management, feature flags per tenant, retention policies, backups + PITR, full export.
- **Documents & notifications**: attachments on every transaction; email/SMS templates for dunning, payslips, claim status.
- **Import**: upload → parse → validate → errors → dry-run → preview → approve → commit. Never silently create imbalance.
- **Integrations**: REST + webhooks + scheduled imports; policy admin, CRM, banks, gateways, mobile money, biometric devices, actuarial/IFRS 17 engines. All inbound financial data goes through the posting engine.

---

## 8. Compliance
- **IFRS 17** **[NEW boundary]**: Insurance Subledger → PAA (built in, non-life short-term) | GMM (integrate an actuarial/IFRS 17 engine, import results) → IFRS results → GL via multi-book. Concepts: groups of contracts, LRC, LIC, risk adjustment, CSM (GMM only), onerous contracts, acquisition cash flows, reinsurance held.
- **Regulatory framework** **[NEW]**: Country → Regulator → Report definition (versioned, effective-dated) → data mapping → validation → submission format. IDRA returns as a Bangladesh package.
- Financial statements with drill-down to source; custom report builder over read models (never raw transactional tables) **[NEW]**; scheduled/saved reports.

---

## 9. UX principles
Users act on Policy, Claim, Customer, Agent, Payment, Employee, Payroll, Treaty — never on journals. Accounting is invisible by default, visible on demand (`[View Accounting]` on every object), drillable Report → GL → Journal → Event → Transaction → Document. Role dashboards, exception-driven screens, global search, keyboard-first entry, mobile approvals and agent collections, English/Bangla.

---

## 10. AI
AI proposes → deterministic validation → workflow → posting engine. Never posts directly. **[NEW rule adopted]**
Tier 1: bank matching, OCR, duplicate payment, anomaly alerts. Tier 2: NL reports, close assistant, variance explanation. Tier 3: later.

---

## 11. Roadmap **[CHANGED]**

| Phase | Scope | Outcome |
|---|---|---|
| 0 Kernel (6–8 wks) | Tenancy/entity/branch, identity, RBAC, audit, COA, periods, currencies, dimensions, posting engine + rules, journals, maker-checker, import, doc numbering | Can book balanced, immutable, traceable entries |
| 1 Insurance core | Party, product catalogue, policy & premium, billing/collections, **bank & reconciliation**, suspense, commission, claims, basic tax, TB/P&L/BS | Customer can run daily operations and close a month |
| 2 Finance + People | AP, AR, expenses, fixed assets, budget, cash flow, HR, payroll engine, full workflow engine, SoD | Replaces separate finance/HR tools |
| 3 Insurance depth | Reinsurance, investments, IBNR/DAC, IFRS 17 PAA, actuarial integration, regulatory packs, full tax engine | Compliance-grade |
| 4 Enterprise + AI | Consolidation UI, advanced analytics, AI tiers 1–2, advanced integrations | Differentiation |

---

## 12. Pre-implementation deliverables **[CHANGED — review asked for 25; do these 9]**
1. Bounded-context map with module ownership
2. Core ERD: Tenant/Entity/Branch, Party, Account, Journal, JournalLine, Dimension, Period, AccountingEvent, PostingRule
3. Posting rule DSL and 10 worked event → journal examples
4. Policy, claim, commission state machines with emitted events
5. Subledger reconciliation design
6. Permission/SoD model
7. Idempotency + outbox + background job design
8. Testing strategy (invariant tests, posting-rule golden tests, period-lock tests, tenant-isolation tests)
9. MVP vs later boundary per module

Everything else (payroll rules, tax detail, reinsurance model, IFRS 17) is designed at the start of its own phase, not before Phase 0.

---

## 13. What not to do
Business modules → JournalRepository · editing posted journals · hard-coded tax/payroll/product rules · Agent = Employee · AI as accounting authority · IFRS 17 as a checkbox · microservices for fashion · **25 design documents before a line of code** · **bank reconciliation after claims**.
