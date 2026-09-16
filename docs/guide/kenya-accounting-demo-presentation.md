# Kenya accounting demo — presentation script

> **HTML deck (present from browser):** [`kenya-accounting-demo-presentation.html`](./kenya-accounting-demo-presentation.html)  
> Open locally: `file:///…/docs/guide/kenya-accounting-demo-presentation.html` or serve from the repo.  
> Use **← →** arrow keys, **N** for speaker notes, **Next** button on screen.

**Audience:** Md. Meherul Islam (integration / payments customer)  
**Goal:** Show complete, professional accounting in KES — web UI + Ledger API for their payment system  
**Duration:** 35–45 minutes (core 25 min + Q&A)  
**Currency:** KES only (no FX in this phase)

---

## Before you present (15 minutes setup)

### 1. Fresh demo data (required if you have not seeded since KES changes)

```bash
docker compose up -d
composer db:fresh && php artisan erp:demo
```

If the app is already running (`composer dev`), Vite will hot-reload after the Vue fix. No rebuild needed for dev.

### 2. URLs and login

| Item | Value |
|------|--------|
| URL | http://nonlife.localhost:8000 (or your `composer dev` port) |
| **Primary login** | `finance.manager@nonlife.local` / `ChangeMe123!` |
| **Ledger API (demo)** | `integration@nonlife.local` / `ChangeMe123!` (seeded by `erp:demo`) |
| API / integration admin | `admin@nonlife.local` / `ChangeMe123!` (create integration users only) |

**Do not use admin for the accounting walkthrough** — admin lacks some accounting permissions.

### 3. Enable accounting focus mode

**Settings (gear icon) → Accounting focus mode** — hides Sales, Claims, Collections, HR, Reinsurance and Admin from the sidebar. Status bar shows **Accounting focus**. Toggle off to restore full menus. Saved per user.

### 4. Have these open in tabs before the call

1. Home (logged in as finance manager)
2. **Accounting → Ledger API demo** (endpoint table + implementation steps on page)
3. **Accounting → Accounting events** (recent posted — API rows marked)
4. **Accounting → Trial balance**
5. **Accounting → Fixed assets**
6. **Accounting → Budgets**
7. OpenAPI spec: `/docs/api/ledger-v1.openapi.json` · quick ref: `docs/guide/ledger-api-quick-reference.md`

### 5. One-line pitch (memorise)

> “Your payment system posts accounting events to our Ledger API; Insuryn turns them into balanced journals, trial balance, P&L and balance sheet — all in KES, with full audit trail. Staff use the same GL through the web screens.”

---

## Presentation flow (follow in order)

### Part A — Context (3 min)

**Say:**

- This is **Insuryn ERP** with a **Ledger Integration API** (v1).
- Your existing system sends **payment and finance transactions** as JSON events; we post to the **same GL** staff see in the browser.
- **KES only** for this demo; multi-currency / FX is a later phase.
- **Budgets** plan spend; **the GL records what actually happened** via events.

**Do not say:** “standalone accounting product” — say “integrated ledger with API.”

---

### Part B — Ledger API (10 min) ★ Main differentiator

**Navigate:** Accounting → **Ledger API demo**

**Show:**

1. **Event types list** — 49+ types (premium, claims, AP, payroll, bank charges, fixed assets, etc.)
2. **Preview** — paste sample `PREMIUM_RECEIVED` (KES 50,000), show balanced lines **before** posting
3. **Post event** — sync post, show journal id returned
4. **Read backs** — mention (or quick curl):
   - `GET /api/v1/journals/{id}`
   - `GET /api/v1/trial-balance?as_of=`
   - `GET /api/v1/reports/profit-and-loss?from=&to=`
   - `GET /api/v1/reports/balance-sheet?as_of=`
5. **Bank charge** — mention `BANK_CHARGE` for M-Pesa / bank fees (not tied to bounced cheques)

**Say for their payment system:**

| Their transaction | Our event type |
|-------------------|----------------|
| Premium / policy payment in | `PREMIUM_RECEIVED` |
| Allocate to policies | `RECEIPT_ALLOCATED` |
| Claim payout | `CLAIM_PAID` |
| Supplier payment | `AP_PAYMENT_RELEASED` |
| Salary payment | `PAYROLL_PAID` |
| Bank / wallet fee | `BANK_CHARGE` |

**Hand them:**

- `docs/guide/ledger-api-quick-reference.md` — endpoint list + 7-step integration checklist
- `docs/guide/ledger-event-catalogue-kes.md` — sample JSON payloads per event type

**On the Ledger API demo page (in app):**

- Full **endpoint table** (write + read)
- **How to integrate** — 6 numbered steps
- **curl** examples with demo integration user

**Auth (30 sec):** Integration user → `POST /api/v1/tokens` → Bearer token on all calls; header `X-Tenant: nonlife`.

**API summary (all v1 endpoints):**

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/v1/tokens` | Get Bearer token |
| DELETE | `/api/v1/tokens/current` | Revoke token |
| GET | `/api/v1/event-types` | List event types |
| POST | `/api/v1/events/preview` | Dry-run journal lines |
| POST | `/api/v1/events?sync=1` | Submit and post immediately |
| GET | `/api/v1/events/{id}` | Event status + journals |
| POST | `/api/v1/events/{id}/replay` | Compare replay to posted |
| GET | `/api/v1/journals/{id}` | Journal lines |
| GET | `/api/v1/balances?account=&as_of=` | Account balance |
| GET | `/api/v1/trial-balance?as_of=` | Trial balance |
| GET | `/api/v1/reports/profit-and-loss?from=&to=` | P&L |
| GET | `/api/v1/reports/balance-sheet?as_of=` | Balance sheet |

---

### Part B2 — Accounting events (2 min)

**Navigate:** Accounting → **Accounting events**

**Say:**

- **Top section (“Needs attention”)** is an **exception queue only** — failed or stuck events. **Empty = healthy.**
- **Bottom section (“Recently posted”)** shows the latest events that reached the ledger — including API posts marked **Via API**.

**After `erp:demo`:** three seeded API events (2× premium received, 1× bank charge) plus hundreds of journals from the insurance story.

---

### Part C — GL integrity (5 min)

**Navigate:** Accounting → **Journals** → open any posted journal

**Show:**

- Debit = credit (balanced)
- Link to **source event**
- Account codes and roles

**Navigate:** Accounting → **Trial balance**

**Show:**

- Totals balance
- Drill to **account activity** → back to journal

**Say:** “Every API post and every web action lands here — one source of truth.”

---

### Part D — Financial statements (3 min)

**Navigate:** Reports → **Profit and loss** and **Balance sheet** (same period)

**Say:** “Same numbers are available on the API for your data warehouse or reconciliation tools.”

---

### Part E — Fixed assets (4 min)

**Navigate:** Accounting → **Fixed assets**

**Show:**

- Asset register (seeded demo assets)
- One asset detail — cost, depreciation schedule
- **Depreciation** run (monthly batch) — explain it posts to GL

**Say:** “Acquisitions and depreciation can come from your system via `FA_ACQUIRED` and `FA_DEPRECIATION_POSTED`, or staff run the batch here.”

---

### Part F — Budgets (3 min)

**Navigate:** Accounting → **Budgets**

**Show:**

- Approved operating budget (FY)
- **Budget variance** link — actual (from GL) vs budget

**Say:** “Budgets are planning and control; they don’t duplicate the GL. Variance pulls from posted journals.”

---

### Part G — Payables & cash (4 min)

**Navigate:** Payables → **Suppliers** → **Bills** → **Payment runs**

**Show:**

- Bill posted to AP
- Payment run prepared / released (money out of bank)

**Say:** “Your AP payments map to `AP_BILL_POSTED` and `AP_PAYMENT_RELEASED` on the API.”

**Optional:** Petty cash float under Accounting — small cash expenses from float.

---

### Part H — Period close & control (3 min)

**Navigate:** Accounting → **Close**

**Show:**

- Close checklist (reconciliations, pending items)
- Explain **period lock** — nothing posts into a locked period

**Say:** “This is how we enforce month-end discipline — API posts respect open periods too.”

---

### Part I — Wrap & Q&A (5–10 min)

**Recap checklist:**

- [ ] API: events in, journals + reports out  
- [ ] KES throughout  
- [ ] Assets & budgets visible and working  
- [ ] Payables / payroll / bank fees covered  
- [ ] Audit trail: event → journal → trial balance → statements  

**Deferred (if asked):**

- FX / multi-currency  
- Webhooks / sandbox tenants  
- Batch event ingest (one event per transaction today)  
- Customer / agent portals  

**Close:**

> “We can start integration with preview mode and trial balance reconciliation, then go live event-by-event. Opening balances are a one-time import; day-to-day payments use the event API.”

---

## Demo data already seeded (`erp:demo`)

| Area | What you see |
|------|----------------|
| **Ledger API** | Integration user + 3 posted API events (KES) |
| **Accounting events** | Recently posted list (API rows flagged) |
| **Journals / trial balance** | Full insurance week — policies, receipts, claims, commissions |
| **Fixed assets** | Register + depreciation schedules |
| **Budgets** | Approved FY budget + variance vs GL |
| **Payables** | Suppliers, bills, payment runs |
| **People** | Payroll run + payslips |
| **Bank** | Statement lines, matches, exceptions |
| **Close** | August closed/locked; September open |

Re-seed if anything looks empty: `composer db:fresh && php artisan erp:demo`

---

## Demo accounts (reference)

| Email | Role | Use in this demo |
|-------|------|------------------|
| `finance.manager@nonlife.local` | Finance manager | **Primary** — accounting, API demo, assets, budgets, payables |
| `accountant@nonlife.local` | Accountant | Manual journals, close, COA |
| `cfo@nonlife.local` | CFO | Approve payment runs, lock periods |
| `integration@nonlife.local` | Integration (API) | curl / token demo — seeded by `erp:demo` |
| `admin@nonlife.local` | Admin | Create integration users on Ledger API demo page only |

Password for all: `ChangeMe123!`

---

## Troubleshooting during the demo

| Problem | Fix |
|---------|-----|
| Blank/error after login | Run `composer db:fresh && php artisan erp:demo`; refresh browser |
| Vite compile error | Pull latest; restart `composer dev` |
| Event stays “queued” | Queue worker must run (`composer dev` includes worker, or `php artisan queue:work`) |
| “Period closed” on API post | Use transaction date in an **open** period (demo: Sep 2026) |
| Admin can’t see accounting | Switch to `finance.manager@nonlife.local` |

---

## What you must NOT skip

1. **Ledger API demo** — preview + post + trial balance narrative  
2. **Trial balance** — proves books balance  
3. **Fixed assets** — customer asked for assets in accounting  
4. **Budgets + variance** — customer asked for budgets  
5. **KES** — no BDT labels; all amounts in Kenyan shilling context  

---

## Optional backup: 60-second API curl (if screen share fails)

```bash
# Token (replace tenant header if needed)
curl -s -X POST http://nonlife.localhost:8000/api/v1/tokens \
  -H "Content-Type: application/json" -H "X-Tenant: {tenant_uuid}" \
  -d '{"email":"integration@nonlife.local","password":"ChangeMe123!","device_name":"demo"}'

# Trial balance
curl -s "http://nonlife.localhost:8000/api/v1/trial-balance?as_of=2026-09-15" \
  -H "Authorization: Bearer {token}" -H "X-Tenant: {tenant_uuid}"
```

Create integration user from **Ledger API demo** page while logged in as admin if none exists.

---

## Practice run (solo, 20 min)

1. Fresh seed → login finance manager  
2. Ledger API: preview → post → note journal id  
3. Trial balance → one account drill  
4. Fixed assets → one asset  
5. Budgets → variance  
6. Payables → one bill or payment run  
7. Close checklist (don’t lock the period unless you re-seed)  

If all seven steps work, you are ready to present.
