# Ledger API v1 — quick reference

Base URL: the tenant's host (demo: `http://nonlife.localhost:8765`; the tenant is the host name, there is no tenant header) · Amounts: **minor units** (KES × 100)

## Authentication

```http
POST /api/v1/tokens
Content-Type: application/json

{
  "email": "integration@nonlife.local",
  "password": "ChangeMe123!",
  "device_name": "payments-prod"
}
```

Response: `{ "data": { "token": "…", "abilities": ["integration:events:write", "integration:events:read"] } }`

Use on all other calls: `Authorization: Bearer {token}`

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/v1/tokens` | Get Bearer token |
| DELETE | `/api/v1/tokens/current` | Revoke token |
| GET | `/api/v1/event-types` | List event types |
| POST | `/api/v1/events/preview` | Dry-run journal lines |
| POST | `/api/v1/events` | Submit event (queued) |
| POST | `/api/v1/events?sync=1` | Submit and post immediately |
| GET | `/api/v1/events/{id}` | Event + journals |
| POST | `/api/v1/events/{id}/replay` | Compare replay to posted |
| GET | `/api/v1/journals/{id}` | Journal lines |
| GET | `/api/v1/balances?account=&as_of=` | Account balance |
| GET | `/api/v1/trial-balance?as_of=` | Trial balance |
| GET | `/api/v1/reports/profit-and-loss?from=&to=` | P&L |
| GET | `/api/v1/reports/balance-sheet?as_of=` | Balance sheet |

OpenAPI machine-readable spec: `/docs/api/ledger-v1.openapi.json`

## Implementation checklist

1. Provision integration user (`kind=integration`) with ledger role.
2. Obtain token; store securely; refresh on expiry.
3. Map each payment flow to an **event_type** (see [ledger-event-catalogue-kes.md](./ledger-event-catalogue-kes.md)).
4. **Preview** every new payload shape before production.
5. **Post** with unique `idempotency_key` per business transaction.
6. **Reconcile** daily: trial balance + account balances vs your system.
7. Handle 422 responses by `reason` (below); resend a corrected body under the same `idempotency_key` when an event failed.

## Event body (required fields)

```json
{
  "event_type": "PREMIUM_RECEIVED",
  "idempotency_key": "PREMIUM_RECEIVED:unique-id",
  "transaction_date": "2026-09-15",
  "currency": "KES",
  "payload": { "amount": 5000000 },
  "dimensions": { "branch": "HO", "product": "MOTOR", "product_code": "MOTOR", "lob": "motor", "channel": "agent", "policy": "POL-2026-000123", "customer": "CUST-000045" },
  "source": { "type": "receipt", "id": "RCT-001", "number": "RCT-HO-001" }
}
```

## Dimensions: codes and your own references

Each event type requires the dimensions its posting rule names (`GET /api/v1/event-types`); a missing one is a 422 `DIMENSION_MISSING` on `dimensions.<name>`.

| Dimension | Send | Stored as |
|-----------|------|-----------|
| `branch` | the branch code (`HO`, `CTG`, case-insensitive) or its uuid | branch uuid; unknown code → 422 `UNKNOWN_BRANCH` listing the codes |
| `product` | the product code (`MOTOR`, `FIRE`, …) or its uuid | product uuid; unknown → 422 `UNKNOWN_PRODUCT` |
| `policy`, `customer`, `claim`, `agent`, `cost_centre`, `employee`, `reinsurer` | any non-empty string: a uuid or your own reference | a uuid as given; a reference becomes a stable uuid5 of it and the reference itself is kept as `<name>_ref` on the event and on every journal line (`dims_ext`), so `GET /api/v1/journals/{id}` shows your id |
| `product_code`, `lob`, `channel` | strings | as given (they select the posting rule) |

The same reference always maps to the same uuid, so balances by policy or customer stay consistent across events.

## Payload

The rule's amount fields (`payload.amount`, `payload.gross_premium`, …) must be present as **integers in minor units**, 0 or more (signed `*_delta` fields may be negative). `GET /api/v1/event-types` lists the fields per type. Missing or non-integer → 422 `PAYLOAD_INVALID` on `payload.<field>`.

## Refusals (422)

Nothing is written on a 422. Body: `{ "message", "reason", "errors": { "<field>": ["…"] } }`.

| `reason` | Field | Meaning |
|----------|-------|---------|
| `VALIDATION_FAILED` | any | a required top-level field is missing or malformed (`errors` per field, no `reason` key in this case) |
| `UNKNOWN_EVENT_TYPE` | `event_type` | not in `GET /api/v1/event-types` |
| `NO_RULE` / `AMBIGUOUS_RULE` | `event_type` | no rule effective on the date for the product/lob/channel, or two conflicting ones |
| `DIMENSION_MISSING` | `dimensions.<name>` | the rule (or the tenant) requires the dimension |
| `UNKNOWN_BRANCH` / `UNKNOWN_PRODUCT` | `dimensions.branch` / `dimensions.product` | code or uuid not found; the message lists the valid codes |
| `PAYLOAD_INVALID` | `payload.<field>` | an amount the rule reads is missing, not an integer or negative |
| `PERIOD_CLOSED` / `PERIOD_SOFT_LOCKED` / `PERIOD_MISSING` | `transaction_date` | the fiscal period for the date does not accept postings |
| `CURRENCY_MISMATCH` | `currency` | the ledger keeps the entity base currency only (no FX) |
| `POSTING_FAILED` | – | `?sync=1` only: the rules refused at posting time (e.g. an unmapped account role); `data.failure_reason` says why and the event is on record as `failed` |

Other statuses: 401 `UNAUTHENTICATED`, 403 `ABILITY_MISSING` (token lacks `integration:events:write`), 404 `NOT_FOUND`, 500 `UNEXPECTED` (with `event_id` when a sync post hit a bug; the detail is in the server log, never in the response).

## Idempotency and resends

- A new `idempotency_key` → 202 (queued) or 201 (`?sync=1`, journals in the body), `created: true`.
- The same key while the event is queued, posting or posted → 200, `created: false`, the existing event; the body is **not** compared.
- The same key after the event **failed** → the new body replaces payload, dimensions, dates, currency and source, the event is queued again (posted at once with `?sync=1`) and the reply is 200 `created: false, resubmitted: true`. The previous body and failure reason stay in the audit trail (`ledger.event_resubmitted`).

## In-app demo

- **Accounting → Ledger API demo** — preview/post, endpoint table, curl
- **Accounting → Accounting events** — recently posted (API rows marked)
- **Accounting → Journals** — full audit trail

After `composer db:fresh && php artisan erp:demo`: integration user `integration@nonlife.local` / `ChangeMe123!`
