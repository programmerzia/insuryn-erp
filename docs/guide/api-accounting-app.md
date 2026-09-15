# Insuryn Ledger — accounting-only app driven by API

The customer already runs policy, claims and payment software. They need the accounting behind it: journals, ledger, sub-ledger reconciliation, close, statements, regulatory returns. Their software sends events over HTTP; this app turns them into balanced journals and gives back the books.

Single company, on-premise or their cloud. Not multi-tenant.

## 1. What it is

```
Their system ──POST /events──▶ Insuryn Ledger ──▶ journals → ledger → statements, returns
      ▲                              │
      └────── GET balances, journals, drill-down, webhooks ◀─┘
```

One rule: **their software never writes a journal.** It says what happened (`POLICY_ISSUED`, `CLAIM_PAID`, `SUPPLIER_BILL_POSTED`…) with the amounts and references. Posting rules (data, editable by their accountant) decide the debits and credits. That keeps their system simple and keeps the books consistent.

## 2. Features (reuse from Insuryn ERP marked ✔)

### Kernel — everything reused ✔
- Chart of accounts, account roles (role → their account), effective-dated mappings.
- Accounting events → posting rules (versioned JSON, `for_each` line groups) → balanced journals; idempotency per event; failed/stuck event queue with requeue.
- Fiscal years and periods; soft lock, hard lock, CFO early lock; year-end close to retained earnings.
- Manual journals with maker/checker approval, reversals (mirror), move-to-next-period.
- Dimensions on every line (branch, product, policy, claim, cost centre) so reports slice without extra tables.
- Sub-ledger reconcilers: a pluggable list; each compares "what the source system says is outstanding" with the GL control account.
- Month-end close checklist with tasks, dependencies, reconciliation variances blocking the lock, nightly job log.
- Trial balance, account activity with source links, P&L, balance sheet, registers, exports (CSV/XLSX/PDF).
- Audit trail on every change; nothing deleted; money in minor units.
- Roles, permissions, segregation-of-duties rules, approval limits and routing.
- Screens for finance: journals, chart, roles, events, close, reports, admin.

### Insurance accounting knowledge — reused as **rule packs** ✔
The 57 posting rules and the sub-ledger definitions (premium receivable, unearned premium and daily earning, VAT/stamp duty, commission, claims reserves/payable/recoveries, reinsurance ceded/recoverable, IBNR) ship as a starter pack. The customer's system sends the events; the earning run, UPR and IBNR provisions can run inside the ledger from event data, so their system does not need to compute them.

### New — the API layer
- `POST /api/v1/events` — one event or a batch; idempotency key; returns the journal(s) or a validation error. Synchronous posting option for small volumes; queued for bulk.
- `GET /api/v1/journals/{id}`, `/events/{id}` — status, lines, source references.
- `GET /api/v1/balances?as_of=&account=&dimension=` and `/trial-balance`, `/statements/{pnl|balance-sheet}` — for their dashboards.
- `GET /api/v1/subledgers/{name}/positions` — what the ledger believes is outstanding per policy/claim/supplier, so their system can reconcile.
- `POST /api/v1/subledger-snapshots/{name}` — their system sends "outstanding per policy" at period end; the reconciler compares with the GL and reports variances.
- `POST /api/v1/periods/{id}/close-tasks/{code}/run` and lock endpoints — or leave close to the finance screens.
- Webhooks: `journal.posted`, `event.failed`, `period.locked`, `variance.found` to their URL.
- Reference data: `GET/PUT /accounts`, `/account-roles`, `/posting-rules` (read; edit through the screen with approval).
- Auth: per-integration API keys with scopes, IP allow-list, request signing (HMAC) for webhooks, full request log.
- Sandbox: a second database where their developers can post freely; "replay" endpoint to re-run an event against the current rules without posting (the journal preview).

### Dropped from the ERP
Quotes, proposals, policies, claims screens, collections, reinsurance treaties, HR/payroll, fixed assets, budgets, petty cash, distribution — unless the customer wants any of them later. (Payables and fixed assets are pure accounting and are the likeliest to come back.)

## 3. The event contract (what their developers implement)

```json
POST /api/v1/events
{
  "event_type": "POLICY_ISSUED",
  "idempotency_key": "POLICY_ISSUED:PT-88213",
  "transaction_date": "2026-09-14",
  "source": { "type": "policy_transaction", "id": "PT-88213", "number": "POL-HO-2026-000008" },
  "dimensions": { "branch": "HO", "product": "MOTOR", "policy": "POL-HO-2026-000008", "customer": "C-1021" },
  "payload": { "gross_premium": 1456875, "net_premium": 1262500, "tax": 189375, "stamp_duty": 5000 },
  "currency": "BDT"
}
```
Response: `202 {event_id, status: "queued"}` or `201 {journal: {number: "JV-2026-000041", lines: [...]}}`. Amounts in paisa. Every event type has a documented payload schema (generated from the posting rules), and `GET /api/v1/event-types` lists them with an example.

Event catalogue to start with: POLICY_ISSUED, POLICY_ENDORSED, POLICY_CANCELLED, PREMIUM_RECEIVED, PREMIUM_RECEIPT_REVERSED, RECEIPT_ALLOCATED, COMMISSION_EARNED/PAID/CLAWBACK, CLAIM_RESERVED/ADJUSTED/APPROVED/PAID/RECOVERED/CLOSED, RI_PREMIUM_CEDED, RI_CLAIM_RECOVERABLE, AP_BILL_POSTED, AP_PAYMENT_RELEASED, PAYROLL_POSTED/PAID, BANK_CHARGE, MANUAL (through the screen). Their unknown cases become new rules, not code.

## 4. Development flow

| Phase | Weeks | Work | Done when |
|---|---|---|---|
| 0. Discovery | 1 | Map their documents to event types; get their chart of accounts, tax rates, fiscal year, branches; decide sync vs queued posting; agree the reconciliation snapshots they can send. | Event catalogue signed off; sample payloads for 10 real transactions. |
| 1. Carve-out | 2 | New repo from the ERP: keep Platform + Accounting (+ Reports), remove tenancy (single company: tenant id fixed, RLS off), remove insurance/finance/people modules and their routes, seeders, menus. Keep tests for the kernel. | App boots with chart, roles, rules, close, reports screens; kernel test suite green. |
| 2. API | 3 | `/api/v1` above: events (single/batch, idempotent, sync/queued), reads, sub-ledger snapshots, webhooks, API keys and scopes, request log, OpenAPI spec generated, sandbox mode, replay/preview. | Their 10 sample transactions post correctly via the API; OpenAPI published; Postman collection. |
| 3. Rule packs and reconcilers | 2 | Load their chart and map roles; adapt the insurance rule pack to their events; earning/UPR/IBNR runs from event data; reconcilers fed by snapshots; variance reporting. | A month of their real history replayed: trial balance matches their current books (or differences explained). |
| 4. Integration and parallel run | 3–4 | Their developers wire events (with our support); run one closed month in parallel with their existing books; fix mapping gaps; train finance on close and reports. | Two consecutive months reconcile; finance signs off the close. |
| 5. Go-live | 1 | Opening balances import at cut-over date; lock history; monitoring (failed events, webhook delivery), backups, restore drill. | Live; first month closed in the ledger. |

About 12–13 weeks with one senior backend developer and one integration developer on their side; the reuse is what makes it short.

## 5. Decisions to settle with the customer early
1. **Sync or queued posting?** Sync gives their UI an immediate journal preview; queued handles bulk (batch nights). Support both, default queued.
2. **Who owns the sub-ledgers?** Their system holds policies and claims; the ledger only needs period-end snapshots to reconcile. Or the ledger keeps its own positions from events (more independent, more duplication). Recommend snapshots.
3. **Earning, UPR, IBNR, depreciation** — computed in the ledger from events (recommended), or sent as events by their system.
4. **Corrections**: their system sends a reversal event; nobody edits journals. Confirm they can do this.
5. **Cut-over date and opening balances**: trial balance import at a period end; history stays in the old books.
6. **Regulatory returns** from the ledger (needs the dimensions on every event) or from their system.
7. **Hosting**: their server (Docker: PHP, Postgres, Redis, worker, scheduler) and who runs backups.

## 6. What they get on day one
- A documented, versioned HTTP API and a sandbox to code against.
- Books that always balance, with every number traceable to their document id.
- Month-end close with reconciliation against their own figures.
- Finance screens for the parts an API cannot do: manual journals, approvals, chart and role maintenance, close, statements, returns.
