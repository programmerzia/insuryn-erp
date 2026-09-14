# Flow audit — Part A "a week in a non-life insurer"

Walks market cross-check Part A steps 1–14 (docs/market-crosscheck-report.md) in a real browser, as the role each step belongs to, on the Part A demo company, and **measures the work** (UX brief §1 throughput).
For each step it records:
- the starting screen;
- screens, drawers and dialogs;
- clicks and keystrokes;
- every point where the user must leave the flow to create or look up something;
- fields without a sensible default;
- required fields that cannot be known at that moment;
- next steps not offered on completion.

## How to run

```bash
composer db:fresh && php artisan erp:demo     # fresh story; the audit issues, pays, reserves and locks September for real
npm run build
php artisan serve --port=8765 &
composer worker &                             # posts accounting events; restart it after code changes (php artisan queue:restart)
node scripts/flow-audit.mjs                   # --base http://nonlife.localhost:8765
```

- Output:
  - `storage/flow-audit/results.json`;
  - `storage/flow-audit/table.md` (the table below);
  - one screenshot per step, `storage/flow-audit/step-NN.png`.
- Rerunning needs a fresh demo, because step 13 locks September.
- Users: `<role>@nonlife.local` with the admin password.

## Counting rules

The script behaves like a user who knows the app.
- **Start:** each step begins on the screen a user would be on, Home or where the previous step ended. It moves only by clicking: sidebar, links, buttons.
- **Clicks:** a pointer press on a control. Choosing from a select counts 2 (open + choose). A lookup pick counts 1 after typing.
- **Keys:** characters typed plus keys pressed. Money is typed as digits without separators.
- **Defaults:** a field that already holds the right value is not touched and costs nothing. A field that is empty or wrong costs the input and is listed under "No default". Sensible defaults: branch = the user's branch, date = today, product = last used, currency = the entity's base.
- **Screens:** distinct pages (Inertia page components) visited during the step, including the starting one. A tab, or a record saved in place, stays the same screen.
- **Drawers/dialogs:** counted each time one opens.

## Baseline — 14 Sep 2026, before the flow fixes

| # | Part A step | Role | Start | Screens | Drawers/dialogs | Clicks | Keys | Leaves the flow | No default | Required but unknowable | Next step not offered |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | New motor policy: product, customer (new), vehicle, sum insured, premium, VAT and stamp duty | branch.officer | `home/Index` | 4 | 4 | 26 | 84 | producer (when new): no inline create — Distribution → Producers and a licence, then back | product; vehicle type | chassis number required to quote (a customer asking for a price rarely has it) | — |
| 2 | Issue: policy number allocated, accounting written behind the scenes | branch.officer | `proposals/Show` | 2 | 2 | 3 | 0 | — | — | — | after issue → "Record receipt?" |
| 3 | Receive the premium by bank transfer, allocate to the installment, receipt for the customer | branch.manager | `policies/Show` | 4 | 1 | 12 | 35 | receipt is started from Receipts → Record a receipt, not from the policy | amount; value date | — | after receipt → "Print receipt" (only on the Documents tab) |
| 4 | Commission accrued on the receipt for an agent on a commission scheme | finance.manager | `policies/Show` | 1 | 1 | 1 | 0 | — | — | — | — |
| 5 | Register the accident: policy, date of loss, description, documents | claims.officer | `home/Index` | 4 | 0 | 14 | 53 | — | date of loss; reported on | — | — |
| 6 | Surveyor estimates 200,000: set the reserve | claims.officer | `claims/Show` | 1 | 2 | 6 | 24 | — | reserve date | — | after reserve → "Approve" (the claims officer cannot approve their own reserve; no hand-off offered) |
| 7 | Settle at 180,000: approve within limit, finance releases, close releases the rest | claims.manager | `home/Index` | 3 | 6 | 19 | 27 | the claim waiting for approval is not on the claims manager's Home; payee other than a party (e.g. a garage): create in Parties first — no inline create; the payment to release is not on the finance manager's Home | approval amount (reserve not proposed); payee (policyholder not preselected); approval date; paid on; close date | — | — |
| 8 | Home queue; allocate money in suspense to a policy | accountant | `home/Index` | 3 | 1 | 5 | 1 | — | — | — | — |
| 9 | Import the bank statement CSV, accept suggested matches, exceptions left | accountant | `receipts/Allocate` | 3 | 0 | 16 | 47 | — | — | — | — |
| 10 | Record office expenses (vendor bills and salaries are not built) | accountant | `bank/Show` | 4 | 0 | 12 | 53 | no rent/office expense account in this chart: create it first (Accounting → Imports), then come back; an account that is not in the chart: no inline create; vendor bill (AP) and salaries: no module, only a manual journal (G6) | journal date | — | — |
| 11 | Close September: run the checklist | finance.manager | `home/Index` | 3 | 0 | 23 | 0 | — | — | — | — |
| 12 | Trial balance, P&L, balance sheet; click a figure down to the policy | finance.manager | `close/Run` | 6 | 0 | 8 | 0 | — | — | — | — |
| 13 | Lock the period | finance.manager | `reports/Show` | 3 | 1 | 5 | 0 | — | — | — | — |
| 14 | Regulatory exports: premium register by class, outstanding claims, UPR, agency register | auditor | `home/Index` | 4 | 0 | 11 | 0 | IDRA return forms: not built (G5) | — | — | — |

**Totals:** 45 screens, 18 drawers/dialogs, 161 clicks, 324 keystrokes.
Findings:
- 6 steps need more than 3 screens: 1, 3, 5, 10, 12, 14.
- 3 dependent objects cannot be created inline: producer, payee, account.
- 13 fields have no default.
- 1 required field cannot be known at quote time: the chassis number.
- 3 next steps are not offered.

All 14 steps complete. The table measures effort, not pass/fail. The Phase 3 pass/partial result (12 pass, 2 partial: AP/payroll G6, IDRA forms G5) is unchanged.

## Fixes, in order of impact

Each fix is its own commit, `fix(flow): Xn – …`.

| Fix | Steps | Change |
|---|---|---|
| X1 | 2, 3 | After issue: "Record receipt?". The receipt is prefilled from the policy: amount outstanding, allocation lines, branch, value date today. |
| X2 | 5, 6, 7, 10 | Today by default on reported on, reserve, approval, paid on, close and journal dates. Date of loss stays empty: only the customer knows it. |
| X3 | 6, 7 | Claims hand-off: after reserve, "Approve" if the user may and it is within their limit, otherwise say who approves. "Claims to settle" on the claims manager's Home. "Payments to release" on the finance manager's Home. Approval proposes the remaining reserve and the policyholder. |
| X4 | 1, 5, 10 | Start actions on Home (New quote, Record a receipt, Register a claim, New manual journal): no list screen on the way. |
| X5 | 3 | Receipt page: "Print receipt" in the header; "Allocate" when money is left unallocated. |
| X6 | 1 | Product = last used. |
| X7 | 1 | Chassis number required at proposal, not at quote; vehicle type defaults to private. |
| X8 | 7 | New payee created inline in the approval drawer. |
| X9 | 1 | New producer created inline from the quote. |
| X10 | 10 | New account created inline in the manual journal, for users who may manage the chart. |
| X11 | 12 | Account activity links the source document; P&L, balance sheet and trial balance link to each other. |
| X12 | 14 | Export straight from the reports index. |

Out of scope (features, not flow fixes): AP and payroll (G6), IDRA forms (G5).

## Observations to confirm (not failures of the audit)
- **Date shown vs Dhaka date:** the trial balance opened "as of 13 Sep 2026" while it was already 14 Sep in Dhaka. The run was around 21:00 UTC, so the default date may follow the server clock (UTC) rather than the tenant's time zone.
- **Lock with a pending journal:** September locked while the office rent manual journal dated 14 Sep was still waiting for approval. Approving it later cannot post into the locked month. Decide whether the close should block or warn about journals pending approval in the period.
- **Locking early:** a month can be locked before it ends. Part A assumes month end.
- **Regulatory permission:** the finance manager does not hold `reports.regulatory`, so step 14 runs as the auditor.
- **Worker needed:** without a queue worker, accounting events wait and the close reports variances. Home's "Failed accounting events" queue does not list queued-but-unposted events.
