# Project context — Insurance ERP

Read this file first in every working session (human or coding agent).

Read `docs/design-package-v1.md` before writing code. `docs/spec-v2.md` is product scope. Follow DECISION and INVARIANT tags; never resolve an OPEN item by guessing — ask.

## Non-negotiables (tests enforce these; do not weaken tests to pass)
1. Every journal balances per currency. Unbalanced → exception, never persisted as posted.
2. Posted journals and their lines are never updated or deleted. Corrections = reversal / adjustment journals with `reverses_journal_id` / `corrects_journal_id` + reason.
3. Postings into a `locked` period are rejected; `soft_locked` needs `accounting.post_in_soft_locked`.
4. Business modules never write to `journals`/`journal_lines`. They submit an `AccountingEvent`; only `PostingEngine` writes journals.
5. Money = `bigint` minor units + currency. Use `Brick\Money\Money`. Never float, never Eloquent `decimal` casts for arithmetic.
6. Every tenant table has `tenant_id`, RLS enabled+forced, and models use `BelongsToTenant`. Raw queries run inside `TenantContext::run()`.
7. Posting is idempotent: `accounting_events.idempotency_key` unique per tenant; job handlers CAS the status before working.
8. Source row + accounting_event + outbox row commit in ONE transaction.
9. Maker ≠ checker (SoD) on refunds, claim payments, manual journals, commission payouts.
10. Dependency direction: Platform ← Accounting ← (Insurance | Finance | People | Compliance). `App\Modules\Accounting` must not reference Policy/Claim/Commission/Employee.

## Conventions
- PHP 8.4, `declare(strict_types=1)`, PHPStan level 8, readonly value objects, enums for statuses.
- Module layout: `app/Modules/<Context>/{Domain,Application,Infrastructure,Http}`.
- Business dates `date`; timestamps `timestamptz` UTC. IDs: UUIDv7 (`Str::uuid7()`).
- Tests: Pest against real Postgres (docker compose). Add a golden fixture in `tests/Fixtures/golden` for every posting-rule change.
- Commit small vertical slices. Run `./vendor/bin/pest` and `phpstan` before every commit.

## Customization policy
- One product, one `main` branch, tagged releases. No per-customer branches.
- Customer-specific code lives ONLY in that customer's extension package (`erp-ext-<customer>`), or in `extensions/<customer>/` for the first customer until the package is split out.
- Extensions may: add posting-rule JSON (higher `version` wins), listen to domain/accounting events, add reports, set config/flags. They never edit core files or add core migrations.
- A need the hooks can't meet becomes a core feature behind a feature flag on `main`.
- A customer install = core tag + extension version. Upgrading = bump the tag, migrate, run tests. Never merge.
