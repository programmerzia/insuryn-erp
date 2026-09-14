# Questions for the customer — before Phase 2 build

These are decisions only you can make. They replace the Phase 1 list (`docs/phase-1/customer-questions.md`) as the one list to work
from: every Phase 1 question is carried here, updated for what has been built since, together with the values the rating, quotation and
renewal work uses as placeholders, three decisions the flow audit surfaced, and the questions Phase 2 (finance and people) needs answered.

Until you answer, the system uses the most cautious choice listed under "Default today". Where a question says "Setting", an answer
changes configuration, not code. Where it names Phase 2 slices, those slices cannot be finished (or cannot start) until it is answered;
slice numbers are from `docs/phase-2/kickoff.md` and the proposed additions in `docs/design-addendum-v2.md` §B.18. References such as
A-55 are entries of the assumption register in `docs/PROGRESS.md`; "addendum §…" is `docs/design-addendum-v2.md`.

**Status of earlier answers:** no answer to any Phase 1 question is recorded in the repository, so every carried question below is
marked *unanswered*. If you already answered one outside the repository, tell us and we will mark it.

Please answer in the "Your answer" line, or tell us which option you pick.

---

## Summary

"Before" says when the answer is needed: **build** = before the named slice starts; **finish** = the slice can start but cannot be
completed or switched on; **go-live** = no slice is blocked, but the system should not go live on the default.

| # | Question | Default today | Blocks | Before |
|---|---|---|---|---|
| A1 | Carrier, broker or MGA | carrier (design v1 §0) | posting rule sets, claims | go-live |
| A2 | Regulator returns and formats | none (registers and exports only) | IDRA forms (not in Phase 2) | go-live |
| A3 | Facultative reinsurance at go-live | none | — | go-live |
| A4 | Which producers need a licence; register file format | every type; CSV/XLSX as built (A-14, A-16) | — | go-live |
| B1 | Taxes on premium and refund on cancellation | VAT refunded (A-1) | — | go-live |
| B2 | VAT, stamp duty and levy values per class; stamp duty on cancellation | placeholders marked verify (A-66, A-68, A-118) | — | go-live |
| B3 | VAT deducted at source and income tax withheld from suppliers | none assumed | 2.3 | build |
| B4 | Is VAT on purchases recoverable | none assumed | 2.3 | build |
| B5 | VAT and customer tax deduction on non-premium income | none assumed | 2.5 | build |
| B6 | Withholding tax on commission | only when a rate is set | — | go-live |
| C1 | Approval limits | setup defaults, placeholders (A-55) | — | go-live |
| C2 | CFO approval of the month lock | not required | — | go-live |
| C3 | Role mapping and strictness of segregation | templates as shipped | 2.3–2.16 role templates | finish |
| C4 | Close with documents pending approval: block or warn | neither | 2.1b; close tasks of 2.3, 2.5, 2.6 | build |
| C5 | May a month be locked before it ends | allowed | 2.1b | build |
| C6 | Approval routing: sequential, parallel, line manager | sequential by role or permission | 2.2, 2.6b, 2.11 | build |
| C7 | Delegation of approvals | none | 2.2 | build |
| C8 | Escalation times and SLA calendar | none | 2.2 | build |
| C9 | Limits on premium refunds and commission payouts | maker-checker only | 2.2 (object types) | finish |
| D1 | Earning method and short-rate table | 365ths or monthly, pro-rata only (A-4) | — | go-live |
| D2 | Tariff values per class | placeholders marked verify | — | go-live |
| D3 | No-claim bonus scale and how it moves | 0/10/20/30%, +1 year per claim-free year (A-128) | — | go-live |
| D4 | Risk details captured per class | illustrative schemas | — | go-live |
| D5 | Underwriting limits | demo placeholders; none in a new tenant (A-90) | — | go-live |
| D6 | Referral risk flags and duplicate-risk keys | A-89, A-88 | — | go-live |
| D7 | KYC identity document types | NID, passport, birth certificate, trade licence, TIN (A-92) | — | go-live |
| D8 | Cover note maximum validity | 30 days (A-93) | — | go-live |
| D9 | Premium recognised at cover note or policy | at policy; cover-note recognition refused (A-65, D-33) | future Insurance slice | go-live |
| D10 | Issuing policies on credit | not allowed unless the product allows it (A-117) | — | go-live |
| D11 | Quotation validity | 15 days including issue day (A-80, A-116) | — | go-live |
| D12 | Premium on a risk change (endorsement) | full annual difference (A-119, A-120) | — | go-live |
| E1 | Refund split between payers | policyholder | — | go-live |
| E2 | Reminder and lapse schedule | 7 and 21 days, lapse after 30 (A-10) | — | go-live |
| E3 | Bank statement file format | CSV as configured (A-5) | — | go-live |
| E4 | Cheque bounced after cancellation | refused, handled by hand | — | go-live |
| E5 | Number formats for receipts, claims, deposits and Phase 2 documents | branch-coded for policies and quotes only | 2.1c; numbering in 2.3–2.15 | build |
| E6 | Renewal timings, notices and non-renewal reasons | A-125, A-127, A-131, A-132, A-135 | — | go-live |
| E7 | Wording of printed documents, Bangla, number style | placeholders (A-103, A-104, A-107) | — | go-live |
| E8 | Notification channels and customer contact details | email and SMS, log only (A-131) | payslip and approval notices in 2.2, 2.16 | finish |
| E9 | Document upload limits and who may attach | 10 MB, listed types (A-52, A-53) | — | go-live |
| F1 | IDRA commission caps, non-life rule, renewal commission after termination | non-life commission off; no caps; no pay after termination (A-18, A-19) | — | go-live |
| F2 | Life agency levels and override rates | seeded example only | — | go-live (life) |
| F3 | How commission is paid, and who pays | payroll for employees, else by type (A-22); no role holds `commission.pay` | 2.14 | build |
| F4 | Tax on commission paid through payroll | none assumed | 2.14 payroll route | build |
| F5 | Commission schedule | as configured per scheme | — | go-live |
| F6 | Persistency and incentive periods | A-23, A-24, A-25 | — | go-live |
| F7 | Producer portal access | one portal user per active producer (A-26) | — | go-live |
| G1 | Reopening closed claims | claim approvers (limit optional) | — | go-live |
| G2 | Deductibles, co-insurance, batch payments, SLA timers | not built | scope decision | go-live |
| H1 | Opening balances and chart of accounts source | CSV import (A-3) | 2.7, 2.10 cut-over data | finish |
| H2 | Which clock sets "today": server (UTC) or Dhaka | server clock (UTC) | 2.1b; 2.2 SLA, 2.11, 2.13 | build |
| I1 | Supplier payment file formats per bank | none | 2.4 | finish |
| I2 | Supplier master data and payment terms | none | 2.3 | finish |
| I3 | OCR of supplier bills now or later | later | 2.3 scope | build |
| I4 | Which non-premium income is invoiced | none | 2.5 | build |
| I5 | Payment methods for suppliers, and who releases payments | none | 2.4 | build |
| I6 | Expense claim reimbursement and limits | none | 2.6b | build |
| I7 | Petty cash policy | none | 2.6a | build |
| I8 | Fixed asset classes, methods, lives, threshold | none | 2.7 | build |
| I9 | Budget granularity, cost centres and approvers | none | 2.8 | build |
| I10 | Cash-flow forecast horizon and categories | none | 2.9 | finish |
| J1 | Payroll jurisdiction, tax year and tax rules | none | 2.12, 2.13 | build |
| J2 | Where HR data lives today; cut-over | none | 2.10 | build |
| J3 | Salary payment methods and bank file formats | none | 2.13 | finish |
| J4 | Provident fund, gratuity, festival bonus | none | 2.12, 2.15 | build |
| J5 | Leave policy | none | 2.11 | build |
| J6 | Attendance recording and devices | manual entry and import | 2.11 | build |
| J7 | Staff loans and salary advances | none | 2.13 | finish |
| J8 | Who posts payroll; payroll in the month-end close | none | 2.13, close tasks | build |
| J9 | Who may see individual pay | payroll roles only | 2.10, 2.13 | build |
| J10 | Employee self-service scope | payslips, leave, claims, profile | 2.16 | finish |

---

## A. Business model and regulation (management, compliance)

### A1. Are you the insurer (carrier), a broker, or an MGA?
- **Why it matters:** decides whose money premium is, which accounts premiums and claims post to, and whether claims are paid from your bank. (Design v1 OPEN #1.)
- **Default today:** books as a non-life carrier (design v1 §0 assumption, no register id): premium is your revenue, claims your expense.
- **Blocked until answered:** no Phase 2 slice. Go-live on the carrier rule set.
- **Status:** unanswered (Phase 1 Q1).
- **Your answer:**

### A2. Which regulator returns do you file, and in what format?
- **Why it matters:** the system has registers and exports (premium register by class and branch, outstanding claims, unearned premium, agency register CSV/XLSX, CSV/XLSX export of every report) but no IDRA return forms. (Design v1 OPEN #5, market cross-check G5.)
- **Default today:** no regulator-specific formats.
- **What we need:** a copy of each return you file, how often, and who signs it.
- **Blocked until answered:** IDRA returns (not in Phase 2).
- **Status:** unanswered (Phase 1 Q2).
- **Your answer:**

### A3. Do you need facultative reinsurance at go-live?
- **Why it matters:** the specification allows facultative reinsurance in the first phase only if you need it.
- **Default today:** no reinsurance.
- **Blocked until answered:** none in Phase 2; a "yes" adds work before go-live.
- **Status:** unanswered (Phase 1 Q17).
- **Your answer:**

### A4. Which producers need a licence, and what file does IDRA want for the agency register?
- **Why it matters:** a producer without a valid licence of the product's class cannot sell new business.
- **Default today:** every producer type (agent, agency, BDO, broker, partner) needs a licence (A-14); the register is a CSV or XLSX, one row per licence, with its status on the chosen date (A-16, A-62).
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

---

## B. Tax (finance, tax adviser)

### B1. Which taxes apply to premium, and are they refunded when a policy is cancelled?
- **Why it matters:** VAT on returned premium changes the refund and the tax liability. (Design v1 OPEN #2.)
- **Default today:** VAT on the returned premium **is refunded** on cancellation (A-1). Stamp duty is **not** refunded (A-118).
- **What we need:** for each product, the taxes on premium, whether premium is quoted with tax included, and which are refunded on cancellation.
- **Blocked until answered:** none. Go-live.
- **Status:** unanswered (Phase 1 Q3), extended by stamp duty.
- **Your answer:**

### B2. What are the VAT, stamp duty and levy values per class?
- **Why it matters:** every rated premium adds these duties; the values in the system are placeholders, marked "verify" on the tariff screens.
- **Default today (placeholders, not confirmed IDRA/NBR figures):**
  - VAT 15% of net premium, every class, worked on net premium only, never on stamp duty (A-68);
  - stamp duty motor 50.00 per policy; fire 200.00 / 500.00 / 1,000.00 for sums insured below 10 million / below 50 million / above; marine cargo and miscellaneous 100.00 per policy;
  - every duty in force for a class applies unless the product excludes it (A-66).
- **Blocked until answered:** none. Go-live of rated products.
- **Status:** new since Phase 1 (rating design OPEN 1).
- **Your answer:**

### B3. Do you deduct VAT at source or income tax at source when you pay suppliers?
- **Why it matters:** supplier bills must split what you owe the supplier from what you pay the tax authority, and a payment run must pay only the supplier's part.
- **Default today:** none; no supplier bills exist yet. The design reserves a VAT-at-source line and a tax-withheld line on each bill (addendum §B.4).
- **What we need:** which supplier types or expense kinds carry VAT at source and tax at source, the rates, the thresholds, and when the deduction is made (at bill or at payment).
- **Blocked until answered:** 2.3 (build).
- **Status:** new (Phase 2).
- **Your answer:**

### B4. Is the VAT you pay on purchases recoverable?
- **Why it matters:** recoverable VAT goes to an asset account; non-recoverable VAT is part of the expense or asset cost.
- **Default today:** none assumed.
- **Blocked until answered:** 2.3 (build), 2.7 (asset cost).
- **Status:** new (Phase 2).
- **Your answer:**

### B5. On income you invoice outside premium, do you charge VAT, and do customers deduct tax when they pay you?
- **Why it matters:** decides the invoice lines and how a short payment is recorded (tax deducted by the customer, not a bad debt).
- **Default today:** none assumed.
- **Blocked until answered:** 2.5 (build).
- **Status:** new (Phase 2).
- **Your answer:**

### B6. Is withholding tax deducted from agent commission, and at what rate?
- **Why it matters:** the system withholds tax from commission only when a rate is set; it never assumes zero.
- **Default today:** a compensation scheme or commission plan names its withholding tax; a missing rate refuses the commission rather than paying it without tax.
- **Blocked until answered:** none. Also affects F4.
- **Status:** unanswered (Phase 1 Q4).
- **Your answer:**

---

## C. Approvals, roles and the month-end close (finance manager, CFO, HR, internal audit)

### C1. What are your approval limits, and who approves above them?
- **Why it matters:** items above a limit go to more senior approvers. (Design v1 §7.3 OPEN.)
- **Default today:** Admin → Approval limits sets them. The setup wizard offers these placeholders (A-55): claim payment approval from 500,000 → Finance Manager, then CFO; claim payment release from 500,000 → CFO; manual journal and journal reversal, any amount → Finance Manager. Underwriting referrals follow underwriting limits (D5). Without a limit, the preparer can still never approve their own item.
- **What we need:** for each item the bands and approvers in order:

  | Item | Up to (BDT) | Approved by | Above | Approved by (in order) |
  |---|---|---|---|---|
  | Claim payment | | | | |
  | Claim payment release | | | | |
  | Claim reopen | | | | |
  | Manual journal | | | | |
  | Journal reversal | | | | |
  | Reopening a closed month | | | | |
  | Supplier bill (Phase 2) | | | | |
  | Supplier payment run (Phase 2) | | | | |
  | Credit note (Phase 2) | | | | |
  | Expense claim (Phase 2) | | | | |
  | Asset disposal (Phase 2) | | | | |
  | Payroll run (Phase 2) | | | | |
  | Final settlement (Phase 2) | | | | |

- **Blocked until answered:** none (settings). Go-live.
- **Status:** unanswered (Phase 1 Q5).
- **Your answer:**

### C2. Who locks the month, and must the CFO approve the lock?
- **Why it matters:** the design names the Finance Manager as owner with CFO approval.
- **Default today:** anyone holding "lock period" locks once every close task is done and every reconciliation balances; no separate CFO approval.
- **Blocked until answered:** none.
- **Status:** unanswered (Phase 1 Q6).
- **Your answer:**

### C3. How do our roles map to your staff, and how strict should segregation of duties be?
- **Why it matters:** each role carries permissions. Some pairs of duties are blocked for the same person on the same item only ("object" rules: e.g. a Finance Manager may prepare one payment run and approve another, never the same); others can be blocked for a person altogether ("user" rules).
- **Default today:** roles Branch Officer, Branch Manager, Claims Officer, Claims Manager, Accountant, Finance Manager, CFO, Auditor, Tenant Admin, and a local-only portal role. Gaps found: no role holds "endorse policy", no role holds "pay commission", and the Finance Manager cannot export regulatory registers (the auditor does). Phase 2 proposes AP Clerk, Treasury, Petty Cash Custodian, HR Officer, HR Manager, Payroll Officer, Payroll Manager and Employee (addendum §B.14).
- **What we need:** for each job title the roles it holds; who endorses policies, pays commission and exports regulatory registers; whether you have a separate treasury function that releases payments; which duty pairs in addendum §B.14.2 must be user rules.
- **Blocked until answered:** role templates of 2.3–2.16 (finish).
- **Status:** unanswered (Phase 1 Q7), extended.
- **Your answer:**

### C4. At month end, should manual journals (and later bills, invoices, claims and vouchers) still waiting for approval block the close, or only warn?
- **Why it matters:** in the flow audit September was locked while the office rent journal dated 14 September was still waiting for approval. Once the month is locked, approving it cannot post into September.
- **Default today:** the close does not look at items pending approval.
- **Options:** (a) block the lock until they are approved, rejected or re-dated; (b) warn and let the Finance Manager continue with a reason; (c) as today.
- **Blocked until answered:** 2.1b and the close tasks of 2.3, 2.5, 2.6 (build).
- **Status:** new (flow audit).
- **Your answer:**

### C5. May a month be locked before its last day?
- **Why it matters:** the flow audit locked September before it ended. Anything dated later in that month (receipts, earning, bank lines) could then not be posted.
- **Default today:** allowed when all close tasks are done.
- **Options:** (a) never before the last day; (b) allowed with a reason and a second approval; (c) as today.
- **Blocked until answered:** 2.1b (build).
- **Status:** new (flow audit).
- **Your answer:**

### C6. How should approvals of Phase 2 documents be routed?
- **Why it matters:** the approval engine grows in Phase 2 (slice 2.2). Today steps run one after another, each decided by a role or permission holder.
- **Default today:** sequential steps only.
- **What we need:** per document (bill, payment run, expense claim, leave, payroll run, budget, employee change): steps in order or in parallel; for parallel steps, all approvers or any one or a number of them; which steps belong to the requester's line manager.
- **Blocked until answered:** 2.2, 2.6b, 2.11 (build).
- **Status:** new (Phase 2 kickoff question 7, extends Phase 1 Q5).
- **Your answer:**

### C7. May approvers delegate their approvals while away?
- **Why it matters:** delegation must never let one person prepare and approve the same item.
- **Default today:** no delegation.
- **What we need:** who may delegate, to whom (same role only?), for how long, and whether an administrator may set a delegation for an absent person.
- **Blocked until answered:** 2.2 (build).
- **Status:** new (Phase 2).
- **Your answer:**

### C8. How long may an approval wait before a reminder and an escalation, and do weekends and holidays count?
- **Why it matters:** escalation adds a senior approver when the time passes; the system never approves anything by itself.
- **Default today:** no reminders or escalation.
- **What we need:** per document, hours to remind, hours to escalate, to which role; whether hours count on the calendar or on working days (and your weekly off-days and holiday calendar).
- **Blocked until answered:** 2.2 (build).
- **Status:** new (Phase 2).
- **Your answer:**

### C9. Do premium refunds and commission payouts need amount limits?
- **Why it matters:** both need a second person today, but neither goes through the approval engine, so no limit can be set for them.
- **Default today:** maker-checker only.
- **Blocked until answered:** adding them as approval object types in 2.2 (finish).
- **Status:** unanswered (Phase 1 Q5 note).
- **Your answer:**

---

## D. Products, rating and underwriting (underwriting, actuarial)

### D1. How is premium earned for each product, and do you use a short-rate table on cancellation?
- **Why it matters:** the earning method changes monthly revenue; short-rate changes refunds. (Design v1 OPEN #4.)
- **Default today:** by day (365ths) or by month; 24ths and short-rate tables refused, so cancellations are pro-rata (A-4).
- **Blocked until answered:** none. Go-live.
- **Status:** unanswered (Phase 1 Q8).
- **Your answer:**

### D2. What are your tariffs per class?
- **Why it matters:** the rating engine calculates premium from these tables; every value today is a placeholder marked "verify".
- **Default today (placeholders):**

  | Where | Value |
  |---|---|
  | Motor own damage (‰ of sum insured) | private 20.00 / 22.50 / 25.00; commercial 27.50 / 30.00 / 32.50; motorcycle 15.00 / 17.50 / 20.00 for ≤1300 / 1301–1800 / >1800 cc |
  | Motor third-party liability | private 2,500; commercial 4,000; motorcycle 900 |
  | Passenger liability | 45.00 per seat |
  | Motor loadings | driver under 25 +10%; built 2015 or earlier +15% |
  | Minimum premiums | motor private 5,000 / commercial 7,500 / motorcycle 1,500; fire 1,000; marine cargo 500; misc 500; the higher of plan and product minimum wins (A-67) |
  | Fire (‰) | dwelling 0.80, shop 1.50, warehouse 2.00, factory 2.50; construction class 3 +25% |
  | Marine cargo (‰) | import 1.50 / 1.00 / 1.20, export 1.20 / 0.80 / 1.00, inland 1.80 / 1.20 / 2.00 for sea / air / road |
  | Miscellaneous | 3.00‰ of sum insured |
  | Rounding | nearest 1.00, half-even |

- **What we need:** your tariff (or the IDRA tariff you follow) per class, with its effective date.
- **Blocked until answered:** none. Go-live of rated products.
- **Status:** new since Phase 1.
- **Your answer:**

### D3. What is your motor no-claim bonus scale, and how does it move at renewal?
- **Default today:** 0 years 0%, 1 year 10%, 2 years 20%, 3 or more 30% (rating design OPEN 5). At renewal +1 claim-free year when the expiring policy had no claim with a loss date in its period; back to 0 after any claim not rejected, including one only registered (A-128).
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

### D4. Which risk details must be captured per class, and when?
- **Why it matters:** the quote form is generated from these fields; some are required only at the proposal (the chassis number, X7).
- **Default today (illustrative):** motor vehicle type (private by default), registration number, chassis number (at proposal), engine cc 50–10,000, seats 1–60, year of manufacture, driver age 18–99, sum insured, claim-free years 0–50; fire occupancy, construction class, address, sum insured; marine cargo voyage type, conveyance, commodity, from/to, sum insured; miscellaneous description and sum insured.
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

### D5. What are your underwriting limits by role and class, and who sets them?
- **Default today:** a new company has none, so every proposal is referred. The demo companies use placeholders (A-90): branch officer motor 2,000,000 / fire 5,000,000 / marine 2,000,000 / misc 1,000,000; branch manager 10m / 25m / 10m / 5m; finance manager 50m each; CFO 250m each. The Tenant Admin sets limits (A-87); a user's limit is the highest of their roles (A-85).
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

### D6. Which risks must be referred, and what identifies "the same risk" insured twice?
- **Default today:** referred when a motor vehicle is older than 15 years or a fire risk is construction class 3 (A-89); the same risk is the same motor registration or chassis number, or fire address, on another open quotation, proposal or policy in force (A-88, A-122).
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

### D7. Which identity documents count for KYC, and who may waive KYC?
- **Default today:** NID, passport, birth certificate, trade licence, TIN; an underwriter may waive with a reason (A-92).
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

### D8. For how long may a cover note be valid, per class?
- **Default today:** 30 days for every class, both ends included, not back-dated (A-93; rating design OPEN 2).
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

### D9. Do you recognise premium when the cover note is issued, or only when the policy is issued?
- **Why it matters:** recognising at the cover note needs its own accounting design; today it is refused for any product set that way (D-33).
- **Default today:** at policy (A-65).
- **Blocked until answered:** a future Insurance slice if you recognise at cover note; no Phase 2 slice.
- **Status:** new since Phase 1 (rating design OPEN 3).
- **Your answer:**

### D10. When may a policy be issued before the premium is received?
- **Default today:** only when the product allows credit issue; otherwise the officer confirms the premium was received and gives its reference (A-117). Credit by producer or customer type is not built. The demo products allow credit.
- **Blocked until answered:** none; credit by producer or customer type would be new work.
- **Status:** new since Phase 1 (rating design OPEN 4).
- **Your answer:**

### D11. How long does a quotation hold its price?
- **Default today:** 15 days including the issue day; a policy must be issued within that time or the customer is quoted again (A-80, A-116).
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

### D12. When a risk changes mid-term, how is the premium change charged?
- **Default today:** the whole annual difference between the new rating and the rating in force (A-119), on the original tariff unless the product uses the current tariff (A-120); pro rata is available as a setting.
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

---

## E. Collections, documents and renewals (collections, operations, customer service)

### E1. When several people pay one policy, who gets the refund on cancellation?
- **Default today:** installments and credits are split by share, but a refund goes to the policyholder. Payer shares are not offered when issuing from a proposal (A-121).
- **Options:** (a) policyholder; (b) each payer by share; (c) each payer by what they paid.
- **Blocked until answered:** none.
- **Status:** unanswered (Phase 1 Q9).
- **Your answer:**

### E2. What is your reminder and lapse schedule for unpaid installments?
- **Default today:** reminders at 7 and 21 days overdue; an active policy lapses after 30 days unpaid (counted again from reinstatement); reminders recorded, not sent (A-10).
- **Blocked until answered:** none.
- **Status:** unanswered (Phase 1 Q10).
- **Your answer:**

### E3. What format do your banks' statement files use?
- **Default today:** CSV with a header row, dates `2026-09-30`, one signed amount column in taka (money in positive); column names configurable (A-5).
- **What we need:** one sample statement per bank (numbers may be blanked out).
- **Blocked until answered:** none; also used to match Phase 2 payment runs and salaries.
- **Status:** unanswered (Phase 1 Q11).
- **Your answer:**

### E4. What happens when a cheque bounces after the policy was cancelled?
- **Default today:** the bounce is refused and handled by hand.
- **Blocked until answered:** none.
- **Status:** unanswered (Phase 1 Q12).
- **Your answer:**

### E5. What number formats must receipts, claims, agent deposits and the new Phase 2 documents use?
- **Why it matters:** while writing the Phase 2 design we found that receipts, claims and agent deposits are numbered per branch but their numbers carry no branch code (`RCT-2026-000001`), while numbers must be unique across the company. In a company with two branches, the second branch's first receipt of the year would repeat the first branch's number and be refused. Policies, quotations, proposals and cover notes already carry the branch (`POL-HO-2026-000123`).
- **Default today:** since fix G5, option (a): receipt, claim and deposit numbers carry the branch code (`RCT-HO-2026-000001`), set per document type in the numbering settings; numbers issued before stay as they were (A-150).
- **Options:** (a) add the branch code to receipt, claim and deposit numbers (`RCT-HO-2026-000001`), as for policies; (b) one company-wide sequence per document type; (c) a format you specify (regulators check receipt numbering).
- **Also:** formats for supplier bills, payment runs, invoices, credit notes, AR receipts, expense claims, petty cash vouchers, asset tags, payroll runs, payslips and final settlements (proposal in addendum §B.2.4).
- **Blocked until answered:** 2.1c (build); numbering in 2.3–2.15 follows the answer.
- **Status:** new (found in design 2.1).
- **Your answer:**

### E6. What are your renewal timings, notices and reasons for non-renewal?
- **Default today (placeholders):** expiry register buckets 60 / 30 / 15 / 7 days (A-125); renewal quotation offered 45 days before expiry, valid until the expiry date (A-127); notice when offered and reminders at 30, 15 and 7 days, in English (A-131); a policy not renewed the day after expiry closes as "no response" (A-132); reasons price, service, sold asset, moved to a competitor, no response, other (A-132); the nightly run at 00:30 retries a quotation that could not be offered (A-135). Manual loadings are not copied to the renewal quotation (A-129).
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

### E7. Is the wording of your printed documents correct, in English and Bangla?
- **Why it matters:** the default templates' closing sentences, signature lines, titles and every Bangla label are placeholders (A-107).
- **Default today:** Latin digits and English month abbreviations also on Bangla documents (A-103); endorsement number `<policy number>/E<n>` (A-104); the "Reference" on the footer is the start of the content hash (A-105).
- **What we need:** your legal wording for the schedule, cover note, quotation, endorsement, receipt, renewal notice, claim acknowledgement and discharge voucher; a Bangla-speaking underwriter to check the Bangla; whether Bangla documents use Bengali digits.
- **Blocked until answered:** none. Go-live of printed documents.
- **Status:** new since Phase 1.
- **Your answer:**

### E8. How should notices reach customers and staff, and where do we get their contact details?
- **Why it matters:** notices today are written to a log; parties have no email address or phone number, so real SMS or email needs contact details first. Phase 2 adds approval reminders and payslip notices.
- **Default today:** email and SMS channels enabled with the log-only adapter (A-131).
- **What we need:** your SMS gateway and email provider; whether customers, producers and employees get SMS, email or both; where contact details come from at cut-over.
- **Blocked until answered:** notices in 2.2 and 2.16 (finish).
- **Status:** new (dunning delivery was Phase 1 Q10).
- **Your answer:**

### E9. Are the document upload limits and attachment rights right?
- **Default today:** 10 MB per file; PDF, JPG, PNG, DOC(X), XLS(X) (A-52); claims staff attach to claims, receipt staff to receipts, policy staff to policies; nobody removes a document (A-53).
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

---

## F. Commission and distribution (sales operations, finance)

### F1. What are the IDRA commission caps, the current non-life zero-commission rule, and does renewal commission continue after a producer leaves?
- **Default today:** commission on non-life products is off until a scheme's compliance profile allows it; caps are only those you enter (A-18); renewal commission stops at termination and needs a valid licence (A-19).
- **Blocked until answered:** none.
- **Status:** new since Phase 1 (distribution design OPEN 1 and 3).
- **Your answer:**

### F2. For life business: what are your agency levels and override rates?
- **Default today:** only a seeded example (FA < UM < BM, 25% first year, 5% renewal, overrides 5%/1% and 2%, caps 35% and 10%). Life rating is not built.
- **Blocked until answered:** none (life products are later).
- **Status:** new since Phase 1 (distribution design OPEN 2).
- **Your answer:**

### F3. How do you pay commission, and who pays it?
- **Why it matters:** decides whether commission is paid from the bank, through supplier payments (accounts payable) or through salaries (payroll).
- **Default today:** a producer with an employee record is paid through payroll; otherwise by producer type, accounts payable by default, BDOs payroll (A-22). Payroll and accounts payable do not exist yet, so those payouts are recorded as owed and wait. No role holds "pay commission".
- **Options:** (a) bank, as Phase 1; (b) payroll; (c) accounts payable; per producer type.
- **Blocked until answered:** 2.14 (build).
- **Status:** unanswered (Phase 1 Q14 and Phase 2 kickoff question 9).
- **Your answer:**

### F4. When commission is paid through payroll, how is it taxed?
- **Why it matters:** commission already has tax withheld when it is earned (B6). Treating it again as salary would withhold tax twice; not withholding may be wrong.
- **Default today:** none; we do not propose one.
- **Options:** (a) commission income with its own withholding only; payroll shows it but does not tax it again; (b) salary income taxed in payroll, and no withholding at commission; (c) another treatment your tax adviser names.
- **Blocked until answered:** 2.14 payroll route (build).
- **Status:** new (Phase 2).
- **Your answer:**

### F5. What is your commission schedule?
- **Why it matters:** first-year and renewal rates, overrides up the hierarchy and caps are now built as compensation schemes (Phase 1's "new work" note no longer applies).
- **Default today:** whatever is configured; Phase 1 flat plans still work (A-20); when both the product and the producer name a plan, the product's wins (A-7).
- **Blocked until answered:** none.
- **Status:** unanswered (Phase 1 Q13), now a setting.
- **Your answer:**

### F6. How do you measure persistency, and what periods do targets and incentives use?
- **Default today:** 13th-month persistency = share of the producer's new policies from 25 to 13 months earlier still in force (A-23); calendar months, quarters and years (A-24); no target → no incentive, highest tier reached pays (A-25).
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

### F7. Who may use the producer portal, and what may they do?
- **Default today:** one portal user per active producer, read-only except recording cash collections for their own policies (A-26). No staff screen grants portal access yet.
- **Blocked until answered:** none.
- **Status:** new since Phase 1.
- **Your answer:**

---

## G. Claims (claims manager)

### G1. When may a closed claim be reopened, and who decides?
- **Default today:** claim approvers reopen; a limit can route it higher (C1).
- **Blocked until answered:** none.
- **Status:** unanswered (Phase 1 Q15).
- **Your answer:**

### G2. Do you need deductibles, co-insurance, batch claim payments or claim SLA timers at go-live?
- **Default today:** not built.
- **Blocked until answered:** a scope decision; not Phase 2 slices.
- **Status:** unanswered (Phase 1 exit checklist scope decision).
- **Your answer:**

---

## H. Go-live data and system settings (finance, IT)

### H1. Where do opening balances, the chart of accounts and the registers come from?
- **Default today:** CSV import with a header row, amounts in taka with a dot decimal, configurable column names; never an unbalanced ledger (A-3).
- **What we need:** the current system's name, exports of the chart of accounts and a trial balance, open policies, unpaid installments, open claims with reserves, and the cut-over month. For Phase 2 also: the fixed asset register with cost and accumulated depreciation, supplier and customer balances by document, employee master data, leave balances, loans.
- **Blocked until answered:** cut-over data for 2.7 and 2.10 (finish).
- **Status:** unanswered (Phase 1 Q16), extended.
- **Your answer:**

### H2. Should "today" follow the server clock (UTC) or the company's time zone (Asia/Dhaka)?
- **Why it matters:** in the flow audit, at about 21:00 UTC the trial balance opened "as of 13 Sep 2026" while it was already 14 September in Dhaka. Dates offered by default (receipt value date, claim reported on, approval and payment dates, journal dates) and the night runs (quotation and cover note expiry, renewals, earning, dunning, reconciliation) follow the server clock. Between 18:00 and 24:00 UTC (midnight to 06:00 in Dhaka) a user's "today" is yesterday. Phase 2 adds attendance days, payroll cut-offs and approval SLA hours.
- **Default today:** server clock (UTC). Printed documents already show their "generated at" time in the company's time zone.
- **Options:** (a) the company's time zone for every business date and night run; (b) as today; (c) another rule (for example per branch).
- **Blocked until answered:** 2.1b (build); SLA timers in 2.2, attendance in 2.11, payroll periods in 2.13.
- **Status:** new (flow audit).
- **Your answer:**

---

## I. Phase 2 — finance (finance manager, treasury, accountant)

### I1. Which file formats do your banks accept for supplier payments?
- **Why it matters:** a released payment run produces a bank file; each bank and payment method (BEFTN, RTGS, internal transfer) may need its own layout.
- **Default today:** none.
- **What we need:** per bank, the file specification or a sample, and whether the bank debits one total or one line per payment.
- **Blocked until answered:** 2.4 (finish; a generic file can be built first).
- **Status:** new (Phase 2 kickoff question 3).
- **Your answer:**

### I2. What supplier details and payment terms do you keep?
- **What we need:** the fields you record per supplier (BIN, TIN, bank account, contact), payment terms, whether suppliers can be put on hold, and who may change a supplier's bank account (the design requires a second person to approve that change).
- **Default today:** none.
- **Blocked until answered:** 2.3 (finish).
- **Status:** new (Phase 2).
- **Your answer:**

### I3. Do you need supplier bills read automatically (OCR) in Phase 2, or later?
- **Why it matters:** automatic reading would only propose a bill for a person to check (specification §10); it is a sizeable addition.
- **Default today:** later.
- **Blocked until answered:** scope of 2.3 (build).
- **Status:** new (Phase 2 kickoff question 8).
- **Your answer:**

### I4. Which income outside premium do you invoice?
- **Why it matters:** decides whether an invoicing module is needed and what it prints (for example survey fees, rent from sub-let space, service charges).
- **Default today:** none.
- **What we need:** each kind of income, how often, to whom, with VAT or not (see B5).
- **Blocked until answered:** 2.5 (build).
- **Status:** new (Phase 2 kickoff question 6).
- **Your answer:**

### I5. How do you pay suppliers, and who releases payments?
- **What we need:** payment methods (bank transfer, cheque, cash), how often payment runs happen, and who prepares, approves and releases them (spec: three different people).
- **Default today:** none.
- **Blocked until answered:** 2.4 (build).
- **Status:** new (Phase 2).
- **Your answer:**

### I6. How are staff expense claims reimbursed, and what are the limits?
- **What we need:** categories, limits per claim or per day, when a receipt is required, who approves (line manager, finance), and whether reimbursement is paid with supplier payments or with salary.
- **Default today:** none; the design proposes reimbursement with supplier payment runs (addendum §B.6).
- **Blocked until answered:** 2.6b (build).
- **Status:** new (Phase 2).
- **Your answer:**

### I7. How do you run petty cash?
- **What we need:** floats per branch and their amounts; top-up to a fixed amount (imprest) or reimbursement of vouchers; voucher limit; how often cash is counted and who counts; who approves top-ups; how a shortage is treated.
- **Default today:** none; the design proposes an imprest float (addendum §B.6).
- **Blocked until answered:** 2.6a (build).
- **Status:** new (Phase 2).
- **Your answer:**

### I8. How do you depreciate fixed assets?
- **What we need:** asset classes; method per class (straight line or reducing balance); useful lives or rates; residual values; the capitalisation threshold below which a purchase is expensed; depreciation in the month of purchase and of disposal; what happens to existing assets when a class's life changes; whether you keep a separate tax depreciation.
- **Default today:** none.
- **Blocked until answered:** 2.7 (build).
- **Status:** new (Phase 2 kickoff question 4).
- **Your answer:**

### I9. How detailed is your budget, and who approves it?
- **What we need:** budget by account × month, and also by branch and/or cost centre; your cost centres (and whether every expense must carry one); who prepares and who approves; whether revisions are allowed during the year.
- **Default today:** none.
- **Blocked until answered:** 2.8 (build).
- **Status:** new (Phase 2 kickoff question 5).
- **Your answer:**

### I10. What cash-flow forecast do you need?
- **What we need:** horizon (the design proposes 13 weeks), weekly or monthly, per bank account or in total, and the categories you forecast by hand (tax payments, investments, capital).
- **Default today:** none.
- **Blocked until answered:** 2.9 (finish).
- **Status:** new (Phase 2).
- **Your answer:**

---

## J. Phase 2 — people and payroll (HR, payroll, finance)

### J1. Under which jurisdiction and tax rules is payroll run?
- **Why it matters:** payroll rules are data in the system, not code, but nobody may invent them. The specification names Bangladesh.
- **What we need:** the income year (for example July–June), tax slabs and their effective dates, rebates or allowances that reduce tax, tax-free components, how monthly tax is spread, and any employees taxed differently (for example foreign staff).
- **Default today:** none. A Bangladesh package would be seeded as a draft with every value marked "verify" and never switched on by us.
- **Blocked until answered:** values of 2.12 and runs of 2.13 (build).
- **Status:** new (Phase 2 kickoff question 1).
- **Your answer:**

### J2. Is HR already run in another system? Where is the employee master data, and when do we cut over?
- **What we need:** the source system, an export of employees with current employment (branch, department, designation, grade, basic pay, line manager), leave balances, loans, and the cut-over month. Also: which producers (BDOs) are employees — their producer records already point to an employee id that must match.
- **Default today:** none.
- **Blocked until answered:** 2.10 (build).
- **Status:** new (Phase 2 kickoff question 2).
- **Your answer:**

### J3. How are salaries paid, and in which bank file format?
- **What we need:** bank transfer, cheque or cash per employee group; the salary file layout per bank; pay day; whether any employee is paid from more than one account.
- **Default today:** none.
- **Blocked until answered:** 2.13 (finish; a generic file can be built first).
- **Status:** new (Phase 2 kickoff question 3).
- **Your answer:**

### J4. What are your provident fund, gratuity and festival bonus rules?
- **What we need:** PF employee and employer rates and the base (basic or gross); whether the PF is a separate trust with its own accounts; gratuity eligibility and formula, and whether you accrue a gratuity provision monthly or expense it on payment; festival bonuses (how many, amount as a share of basic, eligibility, which months).
- **Default today:** none.
- **Blocked until answered:** 2.12 and 2.15 (build).
- **Status:** new (Phase 2 kickoff question 1).
- **Your answer:**

### J5. What is your leave policy?
- **What we need:** leave types (annual, sick, casual, maternity, other), entitlement per year and per employee category, monthly accrual or annual grant, carry forward and its limit, encashment, whether a balance may go negative, half-day leave, documents required, who approves (line manager, HR), and whether unused leave must be provided for in the accounts.
- **Default today:** none.
- **Blocked until answered:** 2.11 (build).
- **Status:** new (Phase 2).
- **Your answer:**

### J6. How is attendance recorded?
- **What we need:** manual entry, a spreadsheet import, or biometric devices (which make and model); weekly off-days per branch; holiday calendar; how absence affects pay.
- **Default today:** the design proposes manual entry and import in Phase 2, devices later.
- **Blocked until answered:** 2.11 (build).
- **Status:** new (Phase 2 kickoff candidate LATER item).
- **Your answer:**

### J7. Do you give staff loans or salary advances?
- **What we need:** kinds, maximum amounts, interest (if any), recovery by monthly installment, who approves, and what happens to a balance when someone leaves.
- **Default today:** none.
- **Blocked until answered:** 2.13 (finish).
- **Status:** new (Phase 2).
- **Your answer:**

### J8. Who posts payroll to the accounts, and must payroll be done before the month is closed?
- **What we need:** whether the payroll manager's approval posts the payroll, or finance posts it after approval; whether the month-end close must block until every pay group's payroll is posted or only warn.
- **Default today:** none.
- **Blocked until answered:** 2.13 and its close tasks (build).
- **Status:** new (Phase 2).
- **Your answer:**

### J9. Who may see an individual employee's pay and personal details?
- **What we need:** which roles may see salaries, payslips, national ID and tax numbers; whether auditors and the Finance Manager see individual pay or only totals.
- **Default today:** the design limits individual pay to payroll roles and the HR Manager (addendum §B.9, §B.14).
- **Blocked until answered:** 2.10 and 2.13 (build).
- **Status:** new (Phase 2).
- **Your answer:**

### J10. What should employees be able to do for themselves?
- **Default today:** the design proposes viewing payslips and leave balances, requesting leave, submitting expense claims and viewing their profile, on the staff web app (addendum §B.10.8).
- **Blocked until answered:** 2.16 (finish).
- **Status:** new (Phase 2).
- **Your answer:**
