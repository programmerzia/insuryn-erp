# Flow audit — Part A "a week in a non-life insurer"

Walks market cross-check Part A steps 1–14 (docs/market-crosscheck-report.md) in a real browser, as the role each step belongs to, on the Part A demo company.
It answers one question per step: can a user do this in the UI today, and what is missing?

## How to run

```bash
composer db:fresh && php artisan erp:demo     # fresh story; the audit issues, pays, reserves and locks September for real
npm run build
php artisan serve --port=8765 &
composer worker &                             # posts accounting events; without it receipts stay unposted and the close shows variances
node scripts/flow-audit.mjs                   # --base http://nonlife.localhost:8765
```

- Since Phase 3 R7, steps 1–3 quote in the quote workbench, make and submit the proposal, issue the policy from the proposal page, receive its rated gross premium and print the receipt.
- Restart the worker after deploying new code (`php artisan queue:restart`): a worker still on older code posts with the old rule set.
- Output goes to `storage/flow-audit/results.json` and one screenshot per step, `storage/flow-audit/step-NN.png`.
- Rerunning needs a fresh demo, because step 13 locks September.
- Users: `<role>@nonlife.local` with the admin password.
- Statuses:
  - **pass**: the step can be done as Part A describes.
  - **partial**: done, with a named shortfall.
  - **fail**: the step cannot be done.

## Latest run — 14 Sep 2026, Phase 3 complete (R1–R10, F1–F6)

| # | Part A step | Role | Result | What happened |
|---|---|---|---|---|
| 1 | New motor policy: product, customer, vehicle, sum insured, premium, VAT and stamp duty | Branch officer | pass | Quote workbench: risk form from the product's schema (vehicle type, registration, chassis, cc, seats, year, driver age, sum insured 450,000.00); premium from the tariff as typed — own damage 10,125.00, third party 2,500.00, stamp duty 50.00, VAT 1,893.75, gross 14,568.75. Quotation issued, proposal made, identity verified, submitted and approved automatically. |
| 2 | Issue; number allocated; accounting behind the scenes | Branch officer | pass | Issued from the proposal as `POL-HO-2026-000008`. Journal preview: Premium receivable 14,568.75 / Unearned premium 12,625.00 / VAT payable 1,893.75 / Stamp duty payable 50.00, each with its plain caption. |
| 3 | Receive the premium by bank transfer, allocate, receipt for the customer | Branch manager | pass | `RCT-2026-000007`: Bank 14,568.75 / Premium receivable, plus commission. Receipt PDF generated from the Documents tab (headless Chromium). A branch officer cannot allocate (SoD), so the branch manager did. |
| 4 | Commission accrued for an agent on a commission scheme | Finance manager | pass | Commission expense and payable, 10% of 14,568.75, on the policy's accounting. |
| 5 | Register the accident with documents; nothing financial | Claims officer | pass | `CLM-2026-000003` registered with no journal; survey report attached and listed. |
| 6 | Set the reserve at 200,000 | Claims officer | pass | Claims incurred / Outstanding claims 200,000. |
| 7 | Approve 180,000 within limit, finance releases, close releases the rest | Claims manager, finance manager | pass | Approved within limit; released and paid by the finance manager; closing released 20,000. |
| 8 | Home queue for the accountant; allocate money in suspense | Accountant | pass | Queues shown; suspense allocated in the workbench (what the installment still needed, 5,397.50). |
| 9 | Import the bank statement, accept suggestions, exceptions left | Accountant | pass | Suggestions accepted; exactly two exceptions left and explained. |
| 10 | Office expenses, vendor bills (AP), salaries | Accountant | partial | Office rent as a manual journal; no AP or payroll module (G6). |
| 11 | Close September | Finance manager | pass | Every checklist task done, all subledgers reconcile. |
| 12 | Trial balance, P&L, balance sheet; click down to policies | Finance manager | pass | Trial balance figure → account activity → journal → policy; P&L and balance sheet open. |
| 13 | Lock the period | Finance manager | pass | September locked. |
| 14 | Regulatory exports | Finance manager, auditor | partial | Premium register totals by class; unearned premium report variance 0.00; agency register XLSX from the producers queue. No IDRA forms (G5). |

**Summary:** 12 pass, 2 partial, 0 fail. The partials are AP/payroll (G6, Phase 2) and IDRA forms (G5).

**Bugs the audit found in Phase 3, fixed in 6b0c641:** every confirmation dialog answered "no" (Reka's AlertDialogAction closed before its click handler), and the quote workbench kept its pre-save state so a new quote could not become a proposal.

## Observations to confirm (not failures of the audit)
- **Date shown vs Dhaka date:** the trial balance opened "as of 13 Sep 2026" while it was already 14 Sep in Dhaka. The run was around 21:00 UTC, so the default date may follow the server clock (UTC) rather than the tenant's time zone.
- **Lock with a pending journal:** September locked while the office rent manual journal dated 14 Sep was still waiting for approval. Approving it later cannot post into the locked month. Worth deciding whether the close should block, or warn about, journals pending approval in the period.
- **Locking early:** a month can be locked before it ends (September was locked on the 14th). Part A assumes month end.
- **Regulatory permission:** the finance manager does not hold `reports.regulatory`, so the agency register export is visible to the auditor but not to the person who files the returns in Part A.
- **Worker needed:** without a queue worker, accounting events wait and the close reports variances. That is correct behaviour, but a first-time local user sees "Subledger premium differs from the GL". Home's "Failed accounting events" queue does not list queued-but-unposted events.

## Changes since the previous audit (onboarding end state)
| Gap in docs/PROGRESS.md | Now |
|---|---|
| 2. Policy number without branch code | Fixed by F1: `POL-<BRANCH>-<FY>-<seq>`, format in the numbering settings. |
| 5. Documents cannot be attached | Fixed by F2: attach, list and download on claims, receipts and policies. |
| 7. No screen for approval limits | Fixed by F3: Admin → Approval limits, and the setup wizard step. |
| Role → account mappings only in seeders/imports | Fixed by F4: Accounting → Account roles, unmapped-role banner, wizard validation. |
| 14. No UPR report, register not by class, agency register API-only | Fixed by F5 (UPR report, register totals by class and branch) and F6 (CSV/XLSX export on the producers queue). IDRA forms remain. |
