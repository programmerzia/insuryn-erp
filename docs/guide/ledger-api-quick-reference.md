# Ledger API v1 — quick reference

Base URL: your Insuryn host · Header **`X-Tenant`**: tenant slug (demo: `nonlife`) · Amounts: **minor units** (KES × 100)

## Authentication

```http
POST /api/v1/tokens
Content-Type: application/json
X-Tenant: nonlife

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
7. Handle 422 responses (period closed, unmapped role, validation).

## Event body (required fields)

```json
{
  "event_type": "PREMIUM_RECEIVED",
  "idempotency_key": "PREMIUM_RECEIVED:unique-id",
  "transaction_date": "2026-09-15",
  "currency": "KES",
  "payload": { "amount": 5000000 },
  "dimensions": { "branch": "{uuid}", "product_code": "MOTOR", "lob": "motor", "channel": "agent" },
  "source": { "type": "receipt", "id": "RCT-001", "number": "RCT-HO-001" }
}
```

## In-app demo

- **Accounting → Ledger API demo** — preview/post, endpoint table, curl
- **Accounting → Accounting events** — recently posted (API rows marked)
- **Accounting → Journals** — full audit trail

After `composer db:fresh && php artisan erp:demo`: integration user `integration@nonlife.local` / `ChangeMe123!`
