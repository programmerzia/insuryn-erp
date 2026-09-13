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

- Output goes to `storage/flow-audit/results.json` and one screenshot per step, `storage/flow-audit/step-NN.png`.
- Rerunning needs a fresh demo, because step 13 locks September.
- Users: `<role>@nonlife.local` with the admin password.
- Statuses:
  - **pass**: the step can be done as Part A describes.
  - **partial**: done, with a named shortfall.
  - **fail**: the step cannot be done.

## Latest run — 14 Sep 2026, after fixes F1–F6

| # | Part A step | Role | Result | What happened |
|---|---|---|---|---|
| 1 | New motor policy: product, customer, vehicle, sum insured, premium | Branch officer | partial | Quote created. No vehicle details or sum insured; the premium is typed in, not calculated (G1 — Phase 3 R1–R4). |
| 2 | Issue; number allocated; accounting behind the scenes | Branch officer | partial | Issued as `POL-HO-2026-000008` (F1). The preview shows Premium receivable 12,000.00, Unearned premium 10,434.78, VAT 1,565.22, each with its plain caption. No stamp duty: only VAT is split (Phase 3 R2 duties). |
| 3 | Receive 12,000 by bank transfer, allocate, receipt for the customer | Branch manager | partial | Receipt `RCT-2026-000007` posted: Bank 12,000 / Premium receivable 12,000, plus commission. A branch officer cannot allocate (segregation of duties), so the branch manager did. No printable receipt (G2 — Phase 3 R8). |
| 4 | Commission accrued for an agent on a commission scheme | Finance manager | pass | Commission expense and payable, 10% of 12,000, on the policy's accounting. |
| 5 | Register the accident with documents; nothing financial | Claims officer | pass | `CLM-2026-000003` registered with no journal. Survey report attached and listed on the Documents tab (F2). |
| 6 | Set the reserve at 200,000 | Claims officer | pass | Claims incurred / Outstanding claims 200,000. |
| 7 | Approve 180,000 within limit, finance releases, close releases the rest | Claims manager, finance manager | pass | Approved within the claims manager's limit; the default limit sends 500,000 and above to Finance Manager then CFO (F3). Released and paid by the finance manager (a different person). Closing released 20,000. |
| 8 | Home queue for the accountant; allocate money in suspense | Accountant | pass | Home shows unallocated receipts, unmatched bank lines, journals awaiting approval and failed events. The 8,500 in suspense was allocated in the workbench: Suspense / Premium receivable plus commission. |
| 9 | Import the bank statement, accept suggestions, exceptions left | Accountant | pass | The demo imports the September CSV; the import button is on the bank account page. Suggested matches accepted with Enter. Exactly two exceptions left (bank charges 350.00, unknown transfer 52,000.00), explained with reasons. |
| 10 | Office expenses, vendor bills (AP), salaries | Accountant | partial | Office rent recorded as a manual journal and submitted for approval. No accounts payable or payroll module (G6, Phase 2). |
| 11 | Close September: earn, reconcile premium, claims, commission, bank, suspense | Finance manager | pass | Every checklist task done: earning, suspense review, bank, premium, claims, commission, accruals, trial balance, statements, sign-off. |
| 12 | Trial balance, P&L, balance sheet; click down to policies | Finance manager | pass | Trial balance figure → account activity → journal → the policy. P&L and balance sheet open. |
| 13 | Lock the period | Finance manager | pass | September locked. |
| 14 | Regulatory exports: premium register by class, outstanding claims, UPR, agency register | Finance manager, auditor | partial | Premium register has totals by class and branch (F5). The unearned premium report reconciles to the control with variance 0.00 (F5). Agency register downloads as XLSX from the producers queue (F6). No IDRA return forms (G5). |

**Summary:** 9 pass, 5 partial, 0 fail. Every partial is a known product gap:

| Gap | Steps | Planned |
|---|---|---|
| Rating | 1 | Phase 3 R1–R4 |
| Stamp duty | 2 | Phase 3 R2 duties |
| Printed documents | 3 | Phase 3 R8 |
| AP and payroll | 10 | Phase 2 |
| IDRA forms | 14 | G5 |

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
