# Demo guide — Padma General Insurance (tenant `nonlife`)

## Before the demo
```bash
docker compose up -d                      # Postgres 5441, Redis 6383
composer db:fresh && php artisan erp:demo # fresh story (dated 14 Sep 2026)
npm run build
php artisan serve --port=8765 &
php artisan queue:work --queue=posting,batch,recon,default &   # posts accounting events
php artisan schedule:work &                                    # nightly jobs
```
- Open http://nonlife.localhost:8765.
- Every user signs in as `<role>@nonlife.local` with password `ChangeMe123!`. The login page also has a "Demo accounts" button.
- All rates, tariffs and limits are placeholders flagged "verify" (docs/PROGRESS.md ASSUMPTION register, docs/phase-2/customer-questions.md).

## Story in 25 minutes

| # | Sign in as | Show | Where |
|---|---|---|---|
| 1 | branch.officer | Home queues → **New quote**. Rated motor tariff as you type (premium, VAT, stamp duty), new customer inline, make proposal, verify KYC, submit (auto-approved). | Home → New quote |
| 2 | branch.officer | **Issue policy** from the proposal. The journal preview shows the accounting before posting, then "Record receipt?" is offered. | Proposal page |
| 3 | branch.manager | **Record receipt** prefilled from the policy, allocate, print the receipt PDF. | Policy → Record receipt |
| 4 | claims.officer | **Register a claim**, then set the reserve at 200,000. The hand-off message says the claims manager approves. | Home → Register a claim |
| 5 | claims.manager | Claims to settle on Home. **Approve payment**: proposes the remaining reserve and the policyholder, shows the claim's policy panel, reinsurers' share. | Home → claim |
| 6 | finance.manager | Payments to release → pay. Approvals inbox with journal lines. | Home |
| 7 | accountant | **Bank**: import statement, accept matches, record receipt from an unknown line. **Payables**: suppliers (landlord, DESCO, garage), bill with VAT/VDS/TDS, payment run. | Bank, Payables |
| 8 | cfo | Release the payment run; download the BEFTN file. Expense vs budget on Home. | Home → Payment runs |
| 9 | hr.manager (or admin, who also holds HR Manager in the demo) | **HR & Payroll**: 30 employees, August payroll paid, September preview, payslip PDF. | HR & Payroll |
| 10 | finance.manager | **Reinsurance**: treaties, the SBC share, the 150 crore garment factory (POL-HO-2026-000008) split by SBC, the surplus treaty and facultative cover; reinsurer statements and bordereaux. | Reinsurance |
| 11 | finance.manager | **Fixed assets, budgets, petty cash**: asset register and depreciation, budget variance (HO marketing 36% over), petty cash floats. | Fixed assets, Budgets, Petty cash |
| 12 | finance.manager | **Month-end close** checklist: reconciliations (premium, claims, commission, UPR, VAT, stamp duty, AP, reinsurance, fixed assets, payroll), nightly jobs, pending documents. **Trial balance** → account activity → source policy. P&L ↔ balance sheet. | Close, Trial balance |
| 13 | cfo | **Regulatory**: IDRA returns (XLSX/PDF, one marked filed), technical provisions (IBNR chain ladder), solvency snapshot. | Regulatory |
| 14 | admin | Users and roles with SoD warnings, approval and underwriting limits, setup wizard. | Admin |

## Things to know while presenting
- **The lock rule:** September cannot be locked on 14 Sep. The soft lock opens on the month's last day and the hard lock after month end (a CFO can lock earlier with a written reason). Say it is a deliberate control.
- **Phone:** Home, policy, receipt and claim pages work at phone width (sidebar behind ☰).
- **Bangla:** the user menu switches the language (help, tour, captions, risk labels, payslips).
- **Search:** Ctrl+K finds policies, claims, receipts, quotes, cover notes, producers and vehicle registrations.
- **Not built yet** (say "next phase"):
  - customer, agent and surveyor portals
  - real SMS/email (log-only today)
  - excess-of-loss treaties
  - co-insurance
  - bulk policy import
  - loans and final settlement in payroll
- **Known demo quirk:** the petty cash and fixed asset demo entries add bank ledger lines that are not on the demo bank statement, so they show as unmatched on the bank screen.

## Verified before the demo
- `scripts/e2e.sh`: the core insurance flow (quote → issue → receipt → claim → pay → close) and the phone layout, in a real browser.
- `node scripts/demo-flows.mjs http://nonlife.localhost:8765`: every action in the new modules (bill approve/post, payment run release and bank file, capitalise/dispose/transfer/depreciate, budget approve, petty cash voucher/replenish/count, hire/payroll post/pay/payslip PDF, treaty/facultative/reinsurer statement, Q4 returns and provisions, the September close) — all pass on a fresh demo.
- Rules to know: Q4 2026 appears only via `?period=2026-Q4`; a Q4 return cannot be marked filed before 1 Oct; dispose assets before posting the month's depreciation.
