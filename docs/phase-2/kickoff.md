# Phase 2 kickoff — Finance + People

Spec §11: "AP, AR, expenses, fixed assets, budget, cash flow, HR, payroll engine, full workflow engine, SoD →
replaces separate finance/HR tools." Spec §12 says payroll rules, tax detail and the rest are "designed at the
start of its own phase", so Phase 2 starts with a design addendum, not code. This file is the outline of
that addendum and a **draft** slice list. The slice list is not final until the addendum is reviewed.

## 0. Entry conditions

| Condition | State |
|---|---|
| Phase 1 exit checklist reviewed | `docs/phase-1/exit-checklist.md` |
| Customer questions sent | `docs/phase-1/customer-questions.md` |
| Phase 1 engineering carry-over (CI, Playwright happy path, reserve property test, user/role admin) | slice 2.0, before feature slices |
| Answer to Q14 (commission paid through payroll or payables) | needed before slice 2.14 only |

What Phase 1 already gives Phase 2 (reuse, do not rebuild):
- Kernel: posting engine and rules, reversal, periods, close run with tasks as container tags (`CloseTaskCheck`),
  subledger reconcilers as container tags (`SubledgerReconciler`), bank override of GL accounts.
- Platform: approvals with amount-limit policies and an inbox (`DescribesApprovalSubject`), SodGuard at action time and
  at role assignment, audit, numbering, tax rate table with withholding, import wizard.
- Finance/Bank: statement import and matching, which payment runs will match against.
- Rule `PAYROLL_POSTED` (summary level, D-05) and golden fixture `10_payroll_posted` (design §4.10).
- Screens pattern: `PageSupport`, area permissions, form-error contract, CoreBari components.

## 1. Design addendum outline (written as [`docs/design-addendum-v2.md`](../design-addendum-v2.md), slice 2.1)

Mirror the nine deliverables of spec §12, for Phase 2 only. Tag everything DECISION, INVARIANT or OPEN.

1. **Context map update.** Finance gains AP, AR, Expenses & petty cash, Fixed assets, Budget, Cash flow. People gains
   Employee, Employment, Attendance & leave, Payroll. Platform's approvals grow into the workflow engine. Dependency
   rules stay (business contexts use Platform and `Accounting\Application` only; Finance and People integrate by events
   and read-only queries). Decide whether payroll's link to commission goes People → Insurance read query or event.
2. **ERD.** Tables per context, tenant + RLS on each (the every-table isolation test fails until they are populated):
   - AP: suppliers (party role), bills, bill lines, payment runs, payment run items;
   - AR: customer invoices (non-premium), invoice lines, AR receipts and allocations;
   - expenses, petty cash floats and vouchers;
   - fixed assets, asset classes, depreciation schedule, disposals;
   - budgets and budget lines by account and dimension; cash-flow forecast inputs;
   - employees, employment records (effective-dated), attendance, leave balances and requests;
   - payroll rule sets (versioned by country, company, policy, category, effective date), payroll runs, payslips,
     payslip lines, loans and advances, final settlements;
   - workflow definitions, steps, delegations, SLA timers.
3. **Posting rules and worked examples.** One rule and one golden fixture per event, frozen like §4. At least:
   `AP_BILL_POSTED`, `AP_PAYMENT_RELEASED`, `AR_INVOICE_POSTED`, `AR_RECEIPT_ALLOCATED`, `EXPENSE_CLAIM_APPROVED`,
   `PETTY_CASH_VOUCHER_POSTED`, `ASSET_CAPITALISED`, `DEPRECIATION_POSTED`, `ASSET_DISPOSED`, `PAYROLL_POSTED`
   (per-employee lines with `dim_employee` and `dim_cost_centre`, replacing the D-05 summary rule by a higher version),
   `PAYROLL_PAID`, `FINAL_SETTLEMENT_POSTED`. Each with a worked example in taka like design §4.
4. **State machines** with emitted events: bill, payment run (create → approve → release, spec §5), AR invoice, expense
   claim, asset, payroll run (preview → approve → post → bank file → payslips, spec §6), employment, leave request,
   workflow instance.
5. **Subledger reconciliation.** AP, AR, payroll payables and fixed assets reconcile to their control accounts as
   dated subledgers (the A-8 pattern). Close task 7 (AP/AR recon) and task 9 (depreciation) move from LATER to built.
6. **Permissions and SoD.** New permission codes per context and role templates (for example AP clerk, payroll officer,
   HR officer). SoD pairs at least: bill create ✕ bill approve, payment run approve ✕ release, payroll prepare ✕
   approve, employee master change ✕ payroll approve.
7. **Idempotency, outbox, jobs.** Payroll run posting as one event per run with idempotency key per run; depreciation
   and budget-variance batch jobs; SLA timer job for the workflow engine.
8. **Testing strategy.** Golden fixtures per rule; property tests (Σ payslip nets = bank file total; Σ depreciation =
   cost − residual); isolation, SoD and close tests per module; E2E happy paths: bill → pay → match, payroll run → post
   → bank file → match.
9. **MVP vs LATER per module.** Candidates for LATER: AP OCR (spec §10 AI tier 1, "AI proposes" only), multi-currency AP,
   biometric attendance devices, consolidation.

### Phase 2 open questions (to ask, not guess)

1. Payroll jurisdiction(s) and whose rules: tax slabs, provident fund, gratuity, festival bonus (spec §6 names Bangladesh).
2. Is HR already run elsewhere? Master data source and cut-over.
3. Bank payment file formats for supplier payments and salaries, per bank.
4. Depreciation methods and asset classes; capitalisation threshold.
5. Budget granularity (account × branch × month?) and who approves budgets.
6. AR scope: which non-premium income is invoiced.
7. Approval routing for Phase 2 documents (extends Q5): sequential or parallel, delegation, escalation times.
8. Is OCR on supplier bills needed in Phase 2, or later?
9. Commission payout route (Q14 from Phase 1).

## 2. Draft slice list

Each slice ships its screens with the feature (the lesson of 1C: API-only features left daily work undone) and its
golden fixtures, reconciler and close task where it has them. Same operating rules as Phase 1.

| Slice | Name | Depends on | Notes |
|---|---|---|---|
| 2.0 | Phase 1 carry-over: CI, Playwright happy path, reserve property test, user and role admin screens | — | From the exit checklist |
| 2.1 | Design addendum v2 and Phase 2 customer questions | 2.0 can run in parallel | Done (docs only): [design addendum v2](../design-addendum-v2.md) (Part B proposes changes to this list, §B.18) and [customer questions](customer-questions.md); review both before 2.2 |
| 2.2 | Workflow engine: sequential and parallel steps, delegation, escalation, SLA timers, rework | 2.1 | Grows `ApprovalService`; existing approval policies keep working |
| 2.3 | Suppliers and AP bills, AP subledger reconciler, close task 7 (AP part) | 2.2 | |
| 2.4 | Payment runs create → approve → release, supplier bank file, match to statements | 2.3 | SoD approve ✕ release |
| 2.5 | AR invoices and receipts (non-premium), AR reconciler, close task 7 (AR part) | 2.2 | |
| 2.6 | Expenses and petty cash | 2.2 | |
| 2.7 | Fixed assets register, depreciation batch, close task 9, disposals | 2.1 | |
| 2.8 | Budgets and variance report | 2.1 | |
| 2.9 | Cash-flow forecast | 2.4, 2.5 | |
| 2.10 | Employees and effective-dated employment | 2.1 | Employee ≠ Agent (spec §13) |
| 2.11 | Attendance and leave | 2.10 | Devices LATER |
| 2.12 | Payroll rules engine and the Bangladesh configuration package | 2.10 | Rules are data, not code (spec §6) |
| 2.13 | Payroll run preview → approve → post (per-employee lines) → bank file → payslips; payroll reconciler | 2.12, 2.4 | Replaces the D-05 summary rule by version |
| 2.14 | Agent commission payout through payroll or AP | 2.13 or 2.4, Q14 | Keeps 1C.1 SoD |
| 2.15 | Benefits and final settlement | 2.13 | |
| 2.16 | Employee self-service (payslips, leave) | 2.11, 2.13 | |
| 2.17 | Phase 2 exit pack | all | Same shape as Phase 1's |

Suggested order: 2.0 and 2.1 first; then the Finance track (2.2 → 2.3 → 2.4 → 2.5 → 2.6 → 2.7 → 2.8 → 2.9) and the People
track (2.10 → 2.11 → 2.12 → 2.13 → 2.15 → 2.16) can run in parallel after 2.2; 2.14 after both.
