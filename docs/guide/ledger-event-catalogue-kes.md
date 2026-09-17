# Ledger API — event catalogue (KES demo)

External systems post accounting through `POST /api/v1/events`. Amounts are **minor units** (KES cents: 1 KES = 100). Send the branch **code** (`HO`, `CTG`) and the product **code** (`MOTOR`, `FIRE`, …); the API resolves them to ids. Your own policy, customer, claim and agent references are accepted as they are (kept as `policy_ref` etc. on every journal line). Currency must match the entity base currency (`KES` for the demo tenant).

## Authentication

```http
POST /api/v1/tokens
Host: nonlife.localhost:8765
Content-Type: application/json

{"email":"integration@nonlife.local","password":"…","device_name":"payments"}
```

The tenant is the host name (`{slug}.your-domain`); there is no tenant header.

Use `Authorization: Bearer {token}` on all other calls.

## Core payment flows (customer system → GL)

| Customer action | Event type | When to send |
|-----------------|------------|--------------|
| Premium / policy payment received | `PREMIUM_RECEIVED` | Money hits bank or agent cash |
| Reverse a receipt | `PREMIUM_RECEIPT_REVERSED` | Receipt cancelled before allocation |
| Allocate receipt to policies | `RECEIPT_ALLOCATED` | After matching payment to instalments |
| Pay a claim | `CLAIM_PAID` | Claim settlement leaves the bank |
| Pay commission | `COMMISSION_PAID` | Agent/producer payout |
| Supplier bill approved | `AP_BILL_POSTED` | AP liability recognised |
| Pay supplier | `AP_PAYMENT_RELEASED` | Bank payment to vendor |
| Payroll posted | `PAYROLL_POSTED` | Salaries accrued |
| Salaries paid | `PAYROLL_PAID` | Net pay leaves bank |
| Bank / M-Pesa fee | `BANK_CHARGE` | Statement fee, not tied to a bounced cheque |
| Acquire fixed asset | `FA_ACQUIRED` | Capitalise equipment over threshold |
| Monthly depreciation | `DEPRECIATION_POSTED` | Period-end (or use web batch) |
| Petty cash voucher | `PETTY_CASH_SPENT` | Small cash expense from float |

## Sample payloads (KES)

### Premium received (50,000 KES)

```json
{
  "event_type": "PREMIUM_RECEIVED",
  "idempotency_key": "PREMIUM_RECEIVED:RCT-2026-001",
  "transaction_date": "2026-09-15",
  "currency": "KES",
  "payload": { "amount": 5000000 },
  "dimensions": {
    "branch": "HO",
    "product": "MOTOR",
    "product_code": "MOTOR",
    "lob": "motor",
    "channel": "agent",
    "policy": "POL-2026-000123",
    "customer": "CUST-000045",
    "agent": "AGT-0007"
  },
  "source": { "type": "receipt", "id": "RCT-2026-001", "number": "RCT-HO-2026-000001" }
}
```

### Bank charge (250 KES M-Pesa fee)

```json
{
  "event_type": "BANK_CHARGE",
  "idempotency_key": "BANK_CHARGE:MPESA-2026-09-001",
  "transaction_date": "2026-09-15",
  "currency": "KES",
  "payload": { "amount": 25000 },
  "dimensions": { "branch": "HO" },
  "source": { "type": "bank_fee", "id": "MPESA-2026-09-001", "number": "FEE-001" }
}
```

### AP payment (120,000 KES)

```json
{
  "event_type": "AP_PAYMENT_RELEASED",
  "idempotency_key": "AP_PAYMENT_RELEASED:PAY-2026-001",
  "transaction_date": "2026-09-15",
  "currency": "KES",
  "payload": { "amount": 12000000 },
  "dimensions": { "branch": "CTG" },
  "source": { "type": "payment_run", "id": "PAY-2026-001", "number": "PAY-2026-001" }
}
```

## Read APIs after posting

| Endpoint | Purpose |
|----------|---------|
| `GET /api/v1/trial-balance?as_of=` | Verify books balance |
| `GET /api/v1/balances?account=1010&as_of=` | Single account |
| `GET /api/v1/reports/profit-and-loss?from=&to=` | P&L |
| `GET /api/v1/reports/balance-sheet?as_of=` | Balance sheet |
| `GET /api/v1/journals/{id}` | Audit a posting |
| `POST /api/v1/events/preview` | Dry-run before live post |

A 422 names the field and a `reason` (see the [quick reference](./ledger-api-quick-reference.md#refusals-422)); nothing is written on a 422.

## Web-only (demo via UI, not API)

- **Budgets** — prepare / approve / variance (`/budgets`); budgets are planning, not GL postings.
- **Period close** — lock periods (`/accounting/close`).
- **Manual journals** — ad-hoc entries (`/accounting/journals/new`).
- **Opening balances** — import (`/accounting/imports`).

## Explicitly deferred

- Multi-currency / FX
- Webhooks and sandbox tenants
- Batch event ingest (send one event per business transaction)
