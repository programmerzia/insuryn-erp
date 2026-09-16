# Insuryn Ledger API v1 — Implementation Plan

**Branch:** `feat/api-accounting-v1` · **Guide:** [docs/guide/api-accounting-app.md](../guide/api-accounting-app.md) · **ADR:** D-118 in [DECISIONS.md](../DECISIONS.md)

**Status (2026-09-16):** Slices 1–3 done (auth, events, journals/balances, preview/replay). Slice 4 (webhooks, sandbox) deferred. Demo page: `/accounting/ledger-api`. OpenAPI: `php artisan ledger:openapi` → `docs/api/ledger-v1.openapi.json`.

## 1. Goal

Expose `/api/v1` so external systems submit accounting events and read journals, balances, and status — never writing journals directly. The API is a thin adapter: validate auth/payload, map JSON to `SubmitAccountingEvent` + optional sync `PostingEngine::post`, return DTOs. **Kernel unchanged** (`SubmitAccountingEvent`, `PostingEngine`, rules, outbox, idempotency). Insurance web flows, producer portal (`kind=portal`), and staff UI keep current routes/guards.

## 2. Architecture boundary

```
 External system                New API layer (this work)              Kernel (unchanged)
+------------------+           +---------------------------+          +----------------------+
| Policy / Claims  |  Bearer   | IntegrationController     |  txn    | SubmitAccountingEvent|
| ERP / Payroll    +---------->| EnsureIntegrationUser     +-------->| PostingEngine        |
| Bank feed        |  token    | Request validation        |         | Posting rules        |
+------------------+           | EventLinesPreview (read)  |         | Outbox / idempotency |
       ^                       +-------------+-------------+         +----------+-----------+
       |                                     |                                    |
       +----------- GET events/journals/balances / webhooks -----------------------+
```

**Auth split:** `users.kind = integration` + Sanctum abilities (`integration:events:write`, `integration:read`). Portal (`kind=portal`, `/api/portal/*`) and staff session stay isolated via middleware.

## 3. Vertical slices

### Slice 1 — Auth, events, event-types
Endpoints: `POST /api/v1/tokens`; `POST /api/v1/events` (single/batch, idempotent, queued default, `?sync=1`); `GET /api/v1/events/{id}`; `GET /api/v1/event-types`.

**Acceptance:** integration user posts `POLICY_ISSUED`, duplicate key returns same event; portal/staff blocked on `/api/v1/*`; invalid/unknown type → 422; OpenAPI at `docs/api/ledger-v1.openapi.json`.

### Slice 2 — Journals and balances
Endpoints: `GET /api/v1/journals/{id}`; `GET /api/v1/balances?as_of=&account=&dimension=`; `GET /api/v1/trial-balance` (reuse report query layer).

**Acceptance:** Slice 1 event readable via event + journal endpoints; balances match `LedgerBalancesTest` fixtures; read scope enforced on GET routes.

### Slice 3 — Preview and replay
Endpoints: `POST /api/v1/events/preview` (`EventLinesPreview`, no writes); `POST /api/v1/events/{id}/replay` (re-run rules vs posted journal).

**Acceptance:** preview returns balanced draft lines; unmapped role returns empty + reason; replay matches posted journal (or diffs when rules changed).

### Slice 4 — Webhooks and sandbox
Components: webhook subscriptions (`journal.posted`, `event.failed`, `period.locked`, HMAC-signed); API request log (secrets redacted); sandbox DB (`DB_DATABASE_SANDBOX`, token `sandbox=true`).

**Acceptance:** `JournalPosted` outbox triggers signed webhook; failed event emits `event.failed`; sandbox token writes only sandbox DB.

## 4. Non-regression strategy

Run **`composer test`** + **`vendor/bin/phpstan`** before each slice merge.

| Slice | Must stay green |
|---|---|
| **1** | Accounting: SubmitAccountingEvent, PostingFailure, GoldenRules, OutboxRelay · ProducerPortalTest · DependencyTest |
| **2** | Slice 1 set + LedgerBalancesTest, ReportDrillThroughTest, PolicyIssueFromProposalTest |
| **3** | Slice 2 set + JournalDraftBuilderTest, JournalPreviewTest |
| **4** | Full suite + smoke: portal token, staff login, policy issue → receipt → journal |

New tests: `tests/Feature/Integration/LedgerApi*.php` (one per slice). Kernel tests unchanged except additive fixtures.

## 5. Task list

```
T1  Migration: users.kind adds 'integration'; integration_users (name, status, ip_allowlist)
    └─ T2  IntegrationAccounts service (PortalAccounts pattern)
T3  EnsureIntegrationUser middleware + Sanctum abilities
    └─ T4  IntegrationTokenController
        └─ T5  /api/v1 routes + IntegrationEventController
T6  EventRequest DTO/validator (minor units, dimensions)
    └─ T7  SubmitEventViaApi (DB::transaction → SubmitAccountingEvent; optional sync post)
        └─ T8  EventTypesQuery
T9  Slice 1 tests + artisan ledger:openapi  ──► SLICE 1
T10 JournalQuery + BalanceQuery adapters
    └─ T11 Slice 2 controllers + tests      ──► SLICE 2
T12 Preview adapter → EventLinesPreview
    └─ T13 ReplayEventQuery
        └─ T14 Slice 3 routes + tests       ──► SLICE 3
T15 webhook_subscriptions + WebhookDispatcher (outbox JournalPosted / event.failed)
    └─ T16 ApiRequestLog middleware
        └─ T17 Sandbox TenantResolver
            └─ T18 Slice 4 tests              ──► SLICE 4
T19 OpenAPI + Postman export · T20 cross-link guide
```

**Dependencies:** T1→T3→T5→T7→T9 (Slice 1 path). Slices 2–4 sequential. T15 uses existing outbox types — no kernel edits.

**Out of scope v1:** sub-ledger snapshots, period close via API, account/rule mutation (finance screens only).
