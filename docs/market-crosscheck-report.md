# Where We Stand — Market Cross-Check and Workflow Guide

## Part A. How an insurer actually uses this software (no accounting knowledge needed)

The mental model: **the accounting is a by-product of insurance work.** Nobody in the company "does journals". People issue policies, collect money, pay claims, pay agents; the system writes the accounting behind each of those actions. The accountant's job is to check that what the system wrote matches the bank and the registers, then lock the month.

### A week in a non-life insurer (motor, fire, marine…)

**Day 1 — Branch officer, Head Office branch**
1. A customer wants motor insurance for a car. Officer opens *Policies → New*, picks the product "Motor Comprehensive", enters the customer (or creates one), vehicle details, sum insured. The premium is calculated (today: entered; see gap G1). VAT and stamp duty are added automatically.
2. Clicks *Issue*. The policy number is allocated (POL-HO-2026-000123). Behind the scenes the system writes: Premium Receivable ↑ 12,000 / Unearned Premium ↑ 10,435 / VAT Payable ↑ 1,565. The officer never sees this unless they click *View accounting*.
3. Customer pays 12,000 by bank transfer. Officer opens *Receipts → New*, enters amount, channel "bank", reference. Allocates to the policy's installment. System writes Bank ↑ / Premium Receivable ↓. Receipt number printed for the customer.
4. If the agent who brought the business is on a commission scheme, the system computes commission on that receipt and accrues it. If the company is under the zero-commission rule, the scheme is "none" and nothing happens.

**Day 2 — Claims officer**
5. Customer reports an accident. *Claims → Register*: policy, date of loss, description, documents. Status: registered. Nothing financial yet.
6. Surveyor estimates 200,000. Officer sets the **reserve** = 200,000. This is the company saying "we expect to pay about this much". System writes Claims Expense ↑ / Outstanding Claims ↑. This is what the finance team and the regulator care about most: how much is owed to claimants.
7. Final settlement agreed at 180,000. Claims manager *approves* (within their limit, otherwise it routes up). System moves 180,000 from Outstanding to Claims Payable. Finance releases the payment (a different person — maker/checker). System writes Claims Payable ↓ / Bank ↓. Claim closed; the leftover 20,000 reserve is released automatically.

**Day 3 — Accountant**
8. Opens the home queue: "5 unallocated receipts", "12 unmatched bank lines", "2 journals awaiting approval". Money that arrived without a clear reference sits in *Suspense* until matched to a policy. Accountant matches it.
9. Imports the bank statement CSV. The system suggests matches (same amount, reference, date). Accepts them. What remains is the exception list to chase.
10. Records office expenses, vendor bills (AP), salary (Phase 2 payroll).

**Month end — Finance manager**
11. Opens *Close → September*. The checklist runs: earn the month's premium (the system moves 1/12 of every annual policy from Unearned to Income), reconcile premium register vs ledger, claims register vs ledger, commissions vs ledger, bank vs ledger, review suspense ageing. Each shows a green tick or a variance to fix.
12. Reviews the trial balance, P&L, balance sheet. Clicks any figure to see which policies/claims made it up.
13. Locks the period. From now on nobody can post into September; corrections go into October as reversals.

**Quarter/year end — Regulatory**
14. Exports the returns the regulator wants: premium register by class, claims outstanding, unearned premium reserve, agency register (see gap G5).

That is the whole product. Everything else — chart of accounts, journals, dimensions — is plumbing that makes step 12 trustworthy and step 14 possible.

### The five ideas worth knowing, in plain words
- **Unearned premium**: money received for cover you haven't provided yet. It's a debt to the customer until time passes.
- **Reserve (outstanding claims)**: money you expect to pay on reported claims. Set early, adjusted, released on close.
- **Receivable / payable**: what customers owe you / what you owe claimants, agents, vendors.
- **Subledger vs general ledger**: registers (policy register, claims register) hold the detail; the ledger holds the totals; they must agree — that's reconciliation.
- **Period lock**: once a month is closed it can't change; the numbers you reported stay reported.

---

## Part B. What the market's insurance systems contain

Sources: Comarch NonLife/Life, Majesco, Duck Creek, BriteCore, SimpleINSPIRE, ZAAX broker ERP, United Software IMS (Pakistan/BD-style), Diceus/ScienceSoft PAS feature sets.

| Layer | Standard modules in real insurer software |
|---|---|
| **Policy administration (front office)** | Product/plan configuration with coverages, riders, rating tables and tariffs; **quotation → proposal → underwriting → issuance**; automatic premium and duties calculation; cover notes; endorsements; renewals with system-generated notices and SMS/email; expiry registers; co-insurance (shared risks between insurers); bulk import of policies; document generation (policy schedule, cover note, receipt) |
| **Billing & collections** | Installments, reminders on non-payment, receivables generated from written premium, multi-channel payment, agent collections |
| **Claims** | Full lifecycle, surveyor/assessor management, documents, reserve history, partial payments, recoveries, litigation tracking, ageing reports |
| **Reinsurance** | Treaty and facultative, automatic cession of premium and claims, bordereaux, reinsurer accounts |
| **Insurance subsidiary ledger → GL** | Every insurance event categorised (taxable/non-taxable) and posted to the GL; unearned premium, technical provisions (reserves incl. IBNR), commission accounting |
| **General accounting** | Multi-level COA, voucher types, AR/AP, bank, fixed assets, tax, budgets, financial statements |
| **Distribution** | Agency/commission scales (first-year, renewal, override), agent statements, licence control, targets |
| **Controls & compliance** | Maker-checker, branch/class-of-business access restrictions, audit logging, regulatory returns, IFRS 17 for larger carriers |
| **Portals** | Customer, agent, and sometimes surveyor portals; self-service renewal and claims |
| **HR/payroll** | Often a separate product; integrated in ERP-style suites |

---

## Part C. Where this product stands today

### Built and market-standard (not imaginary)
- **Accounting core**: the "subsidiary ledger → posting rules → GL" architecture is exactly how Comarch, Majesco and Duck Creek work. Immutable journals, period locks, reconciliation, maker-checker, audit — these are the things auditors and IDRA inspectors test. This part is stronger than most local systems.
- **Policy lifecycle events → accounting**: issue, endorse, cancel, earn, refund. Standard.
- **Receipts, suspense, bank matching**: standard and often missing in local products.
- **Claims with reserves, approvals, payments, recoveries, release on close**: standard.
- **Distribution**: producers, licences, hierarchies, compensation schemes, statements, targets — covers both commission and zero-commission modes; more than most local systems.
- **Month-end close as a workflow**: rare in mid-market products; a genuine differentiator.
- **Branch restriction, roles, SoD, multi-book-ready data model**: standard for the top tier.

### Gaps against the market (in order of importance for a Bangladesh non-life insurer)
| # | Gap | Why it matters | Size |
|---|---|---|---|
| G1 | **Underwriting & rating**: premium is typed in, not calculated from sum insured × tariff/rate tables per class (motor, fire, marine), with loadings/discounts and IDRA tariff rules | This is the daily front-office work; without it the app is a back-office system | Large |
| G2 | **Quotation → proposal → cover note → policy** flow with document generation (schedule, cover note, receipt, renewal notice PDFs) | Customers and regulators expect printed documents; renewals need notices | Medium |
| G3 | **Renewal management**: expiry register, auto-generated renewal notices, SMS/email, renewal conversion | Retention is the main revenue lever in non-life | Medium |
| G4 | **Co-insurance** (leader/follower shares) and **reinsurance** (treaty/fac cession, bordereaux) | Every non-life insurer of size has both; SBC compulsory cession in BD | Large |
| G5 | **Regulatory returns** (IDRA forms), technical provisions incl. IBNR method, UPR by class | Quarterly/annual compliance | Medium |
| G6 | **HR & payroll** | Needed to replace separate tools; BDO salaries/incentives depend on it | Medium |
| G7 | Customer portal / SMS notifications / surveyor management | Service level, not core | Small–Medium |
| G8 | Fixed assets, investments register (insurers hold large deposits/bonds) | Balance sheet completeness | Medium |
| G9 | Guided onboarding: COA template selection, product setup wizard, sample data, in-app "how this works" | Your own confusion is the evidence: first-time users need a guided path | Small but urgent |

### Verdict
The foundation is real and above market standard for its price class. What is missing is the **front office that insurers spend their day in** — rating, quotation, documents, renewals — plus reinsurance and regulatory outputs. Right now it is a very good insurance *accounting and operations back office*. To be a "market killer" for a BD non-life carrier it needs G1–G5; for a broker/agency it needs G2–G3 and much less of G4–G5.

---

## Part D. Recommended order from here
1. **G9 now** (one session): onboarding wizard + a "How this works" panel per module + demo data walkthrough matching Part A. Also lets you demo to prospects.
2. **G1 + G2** (Phase 3a): product rating engine (rate tables per class, sum insured bands, loadings/discounts, minimum premium, tariff versioning), quotation/proposal/cover note, PDF generation.
3. **G3** (Phase 3b): renewals with notices, SMS/email gateway.
4. **G6** (Phase 2 remainder): HR & payroll — design note needed first.
5. **G4 + G5 + G8** (Phase 4): reinsurance/co-insurance, IDRA returns, IBNR, investments, fixed assets.
6. Portals last.
