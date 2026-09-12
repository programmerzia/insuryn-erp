# Insurance ERP — Design Package v1 (Phase 0 + Phase 1A/1B)

Tags: **DECISION** (settled), **INVARIANT** (enforced in code + tests), **ASSUMPTION** (proceeding on this; revisit), **OPEN** (unknown — do not invent), **MVP** (Phase 0/1A), **LATER**.

---

## 0. Stack & conventions

| Item | DECISION |
|---|---|
| Backend | Laravel 13, PHP 8.4, `declare(strict_types=1)` everywhere, PHPStan level 8 + Larastan |
| DB | PostgreSQL 17, single database, one schema per module (`platform`, `accounting`, `insurance`, `finance`, `people`, `compliance`), RLS on every tenant table |
| Web | Vue 3 + Inertia 2 + TypeScript, Tailwind + shadcn-vue; REST/OpenAPI for external clients |
| Jobs | Redis + Horizon; queues: `posting`, `batch`, `recon`, `reports`, `default` |
| Auth | Zitadel OIDC via Socialite; Sanctum for API tokens; roles/permissions local |
| Money | `bigint` minor units + `char(3)` currency; PHP: `Brick\Money`. Never float, never Eloquent decimal casts for arithmetic |
| IDs | UUIDv7 (`uuid` column, time-ordered) for all PKs; human document numbers separate |
| Time | `timestamptz` UTC in DB; business dates as `date`; tenant has `timezone` and `fiscal_year_start_month` |
| Module layout | `app/Modules/<Context>/{Domain,Application,Infrastructure,Http}`; cross-module calls only via `Application` contracts or events |
| Tenancy | Tenant → Legal Entity → Branch. `tenant_id` on every business row; RLS policy `tenant_id = current_setting('app.tenant_id')::uuid` |

**ASSUMPTION** Customer is a non-life carrier writing short-term packages. If broker/MGA, Claims (1B) and reserves are replaced by carrier-receivable handling; the kernel is unaffected.

---

## 1. Bounded Context Map

```
                ┌──────────────────────────────────────────────┐
                │ PLATFORM  Tenancy · Identity · RBAC/SoD       │
                │           Workflow · Audit · Documents        │
                │           Numbering · Config · Tax(rates)     │
                └───────────────▲──────────────────────────────┘
                                │ depends on
                ┌───────────────┴──────────────────────────────┐
                │ ACCOUNTING KERNEL                            │
                │  COA · Periods · Books · Currency · Dimensions│
                │  AccountingEvent → PostingEngine → Journal    │
                │  Reversal · SubledgerControl · Reconciliation │
                └───────────────▲──────────────────────────────┘
                                │ publishes AccountingEvents (never the reverse)
   ┌──────────────┬─────────────┴──────────────┬───────────────┐
   │ INSURANCE    │ FINANCE                     │ PEOPLE        │
   │ Party        │ Bank (cash mgmt + recon)    │ Employee      │
   │ Product      │ AP · AR · Expenses          │ Payroll       │
   │ Policy       │ FixedAssets · Budget        │ Attendance    │
   │ Premium/Bill │                             │               │
   │ Collections  │                             │               │
   │ Commission   │             COMPLIANCE                      │
   │ Claims       │             IFRS17 · Regulatory · Tax rules │
   │ Reinsurance  │                                             │
   └──────────────┴─────────────────────────────────────────────┘
```

**Dependency rules (INVARIANT, enforced by `deptrac`-style test on namespaces)**
- `Platform` depends on nothing.
- `Accounting` depends on `Platform` only. It has **no** reference to Policy, Claim, Commission, Employee.
- Business contexts depend on `Platform` + `Accounting\Application` contracts only.
- Business contexts do not depend on each other's `Domain`; they integrate via events (`PolicyIssued`) and read-only query contracts.

**Context responsibilities & ownership**

| Context | Owns (source of truth) | Emits | Consumes |
|---|---|---|---|
| Platform | tenants, entities, branches, users, roles, permissions, approvals, audit log, documents, number sequences, tax rates | `ApprovalDecided` | — |
| Accounting | accounts, books, periods, dimensions, accounting_events, journals, journal_lines, subledger_controls, reconciliations | `JournalPosted`, `JournalReversed`, `PeriodLocked` | AccountingEvent submissions |
| Insurance/Party | parties, roles, KYC docs, bank accounts | — | — |
| Insurance/Product | products, versions, coverages, earning method, commission schedule, posting-rule set ref | — | — |
| Insurance/Policy+Premium | policies, endorsements, premium schedules, installments, earning ledger | `PolicyIssued`, `PolicyEndorsed`, `PolicyCancelled`, `InstallmentDue`, `PremiumEarned` | Collections |
| Insurance/Collections | receipts, allocations, suspense items | `ReceiptRecorded`, `ReceiptAllocated`, `RefundIssued` | Bank statement lines |
| Insurance/Commission | commission entries, statements, clawbacks | `CommissionEarned`, `CommissionClawback`, `CommissionPaid` | `ReceiptAllocated`, `PolicyCancelled` |
| Insurance/Claims | claims, reserves, payments, recoveries | `ClaimReserved`, `ClaimReserveAdjusted`, `ClaimPaid`, `ClaimRecovered` | Policy queries |
| Finance/Bank | bank accounts, statement lines, matches | `BankLineMatched` | `JournalPosted` (for matching) |
| People/Payroll | payroll runs, payslips | `PayrollPosted` | Employee |

---

## 2. Core ERD / domain model

Only tables the kernel and Phase 1A need. `t` = `tenant_id uuid not null` + RLS; `e` = `entity_id uuid not null`.

### 2.1 Platform

```sql
tenants(id pk, name, slug unique, timezone, fiscal_year_start_month smallint, base_currency char(3), status)
legal_entities(id pk, t, code, name, base_currency, status)               unique(t,code)
branches(id pk, t, e, code, name, status)                                  unique(e,code)
users(id pk, t, oidc_subject unique, email, name, status)
roles(id pk, t, code, name)                                                 unique(t,code)
permissions(code pk)                                                        -- seeded, global
role_permissions(role_id, permission_code) pk
user_roles(user_id, role_id, scope_type, scope_id) pk                      -- scope: tenant|entity|branch
sod_rules(id pk, t, code, conflicting_permission_a, conflicting_permission_b, mode: 'block'|'warn')

approval_policies(id pk, t, object_type, condition jsonb, steps jsonb, effective_from, effective_to)
approvals(id pk, t, object_type, object_id, policy_id, status, current_step, requested_by, requested_at)
approval_decisions(id pk, approval_id, step_no, decided_by, decision, reason, decided_at)

audit_events(id pk, t, occurred_at, actor_user_id, actor_type, action, object_type, object_id,
             before jsonb, after jsonb, reason, request_id, ip, user_agent)   -- append-only

number_sequences(id pk, t, e, branch_id null, doc_type, fiscal_year, prefix, next_no, updated_at)
             unique(t,e,coalesce(branch_id,'0'),doc_type,fiscal_year)
document_numbers(id pk, t, sequence_id, number text, status 'reserved'|'used'|'voided',
             object_type null, object_id null, reserved_by, reserved_at, used_at, void_reason)
             unique(sequence_id, number)

tax_rates(id pk, t, jurisdiction, tax_type, rate_bp int, inclusive bool, withholding bool,
          effective_from, effective_to)
```

**Document numbering (DECISION):** `reserve()` = `SELECT … FOR UPDATE` on the sequence row, insert `reserved`; `commit()` flips to `used` in the same transaction as the business object; if the business transaction rolls back, a sweeper voids stale reservations (>15 min) with `void_reason='reservation_expired'`. Manual void requires permission `numbering.void` + reason. Report: "voided numbers" per sequence. **INVARIANT:** every number is reserved/used/voided — no unexplained gap.

### 2.2 Accounting kernel

```sql
books(id pk, t, code 'LOCAL'|'IFRS'|'MGMT', name, is_primary)                         unique(t,code)
fiscal_periods(id pk, t, e, book_id, year smallint, period smallint, starts date, ends date,
               status 'open'|'soft_locked'|'locked', locked_by, locked_at)            unique(e,book_id,year,period)

accounts(id pk, t, e, code, name, type 'asset'|'liability'|'equity'|'income'|'expense',
         normal_side 'debit'|'credit', parent_id, is_postable bool, is_control bool,
         control_subledger null, currency char(3) null, status)                       unique(e,code)
account_roles(code pk)          -- seeded semantic roles, e.g. 'premium_receivable'
account_role_mappings(id pk, t, e, book_id, role_code, account_id, effective_from, effective_to)
         unique(e,book_id,role_code,effective_from)

dimension_types(code pk, name, required_default bool)   -- entity, branch, product, lob, channel, agent, policy, claim, cost_centre, employee, customer, reinsurer
dimension_requirements(t, event_type, dimension_code, required bool)  pk(t,event_type,dimension_code)

accounting_events(
  id pk, t, e, event_type, source_type, source_id, source_version int,
  idempotency_key text not null, occurred_at timestamptz, transaction_date date, effective_date date,
  currency char(3), payload jsonb, dimensions jsonb,
  status 'received'|'queued'|'posting'|'posted'|'failed'|'rejected'|'superseded',
  failure_reason, journal_batch_id null, created_at)
  unique(t, idempotency_key)

journal_batches(id pk, t, e, accounting_event_id, created_at)
journals(
  id pk, t, e, book_id, batch_id, number text, period_id, transaction_date, posting_date, effective_date,
  status 'draft'|'pending_approval'|'approved'|'queued'|'posting'|'posted'|'failed'|'cancelled'|'reversed',
  kind 'system'|'manual'|'reversal'|'adjustment'|'opening',
  reverses_journal_id null, corrects_journal_id null, original_transaction_id null, reason,
  source_type, source_id, posting_rule_id, posting_rule_version int,
  currency, fx_rate numeric(18,8) null, created_by, approved_by null, posted_at null,
  description)
  unique(e, book_id, number)
journal_lines(
  id pk, t, journal_id, line_no smallint, account_id, side 'debit'|'credit',
  amount_minor bigint check(amount_minor > 0), currency char(3),
  base_amount_minor bigint, role_code, memo,
  dim_branch uuid, dim_product uuid, dim_lob text, dim_channel text, dim_agent uuid,
  dim_policy uuid, dim_claim uuid, dim_cost_centre uuid, dim_employee uuid, dim_customer uuid,
  dim_reinsurer uuid, dims_ext jsonb)
  -- indexes on (t, account_id, journal_id), (dim_policy), (dim_claim), (dim_agent)

-- Balance enforcement at DB level (belt and braces, INVARIANT)
CREATE CONSTRAINT TRIGGER journal_must_balance AFTER INSERT OR UPDATE ON journal_lines
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION assert_journal_balanced();
-- assert: for the journal, per currency, sum(debit)=sum(credit) and status transition to posted only if balanced

subledger_controls(id pk, t, e, subledger 'premium'|'claims'|'commission'|'customer'|'agent'|'bank'|'ap'|'ar',
                   control_account_role, book_id)
reconciliation_runs(id pk, t, e, subledger, period_id, run_at, subledger_balance_minor, gl_balance_minor,
                    variance_minor, status 'clean'|'variance'|'resolved', resolved_by, resolution_note)
reconciliation_exceptions(id pk, run_id, object_type, object_id, expected_minor, actual_minor, note, status)

period_close_runs(id pk, t, e, period_id, status, started_by, started_at, completed_at)
period_close_tasks(id pk, close_run_id, code, order_no, depends_on text[], owner_role, status
                   'pending'|'running'|'done'|'blocked'|'skipped', result jsonb, done_by, done_at)
```

**Journal immutability (INVARIANT):** DB trigger rejects `UPDATE` on `journals` where `status='posted'` except columns `status` (→`reversed`) and `reversed_by_journal_id`; rejects any `UPDATE/DELETE` on `journal_lines` whose journal is posted.

### 2.3 Correction semantics (DECISION)

| Term | Meaning | Mechanism | Links |
|---|---|---|---|
| Reversal | Undo a journal entirely | New journal, mirrored lines, `kind='reversal'` | `reverses_journal_id`; original → `status='reversed'` |
| Adjustment | Change an amount going forward, original stays valid | New journal for the delta only, `kind='adjustment'` | `corrects_journal_id` |
| Correction | Wrong account/dimension | Reversal + new correct journal, same batch | both links + `original_transaction_id` |
| Refund | Business event returning money | Normal event `REFUND_ISSUED`, not a correction | `source_id` = refund |
| Cancellation | Business event ending a policy | `POLICY_CANCELLED` event with its own rule | `source_id` = policy |

Every reversal/adjustment requires `reason`, `created_by`, and approval per policy. Chain reconstructable by walking `reverses_journal_id`/`corrects_journal_id`.

### 2.4 Insurance & finance (Phase 1A subset)

```sql
parties(id pk, t, kind 'individual'|'organization', display_name, tax_id null, status)
party_roles(id pk, t, party_id, role 'customer'|'policyholder'|'insured'|'beneficiary'|'agent'|'vendor'|'reinsurer'|'employee', role_data jsonb, status)
party_bank_accounts(id pk, t, party_id, bank_name, account_no_masked, account_no_enc, is_default)
agents(id pk, t, party_id, code, parent_agent_id, branch_id, commission_plan_id, status)

products(id pk, t, code, name, lob, status)
product_versions(id pk, product_id, version int, effective_from, effective_to, term_months, earning_method
                 'daily_365'|'monthly'|'24ths', short_rate_table jsonb, tax_profile jsonb,
                 commission_plan_id, posting_rule_set_id, coverages jsonb)   unique(product_id,version)

policies(id pk, t, e, branch_id, number, product_version_id, policyholder_party_id, agent_id,
         status 'quote'|'issued'|'active'|'cancelled'|'lapsed'|'expired'|'renewed',
         inception date, expiry date, currency, gross_premium_minor, tax_minor, net_premium_minor,
         issued_at, cancelled_at, cancel_reason, version int)
policy_transactions(id pk, policy_id, type 'new'|'endorsement'|'cancellation'|'renewal', effective_date,
         premium_delta_minor, tax_delta_minor, created_at)          -- source for accounting events
installments(id pk, policy_id, no, due_date, amount_minor, paid_minor, status)
premium_earning_ledger(id pk, policy_id, period_id, earned_minor, run_id)  unique(policy_id, period_id)

receipts(id pk, t, e, branch_id, number, party_id null, channel, amount_minor, currency, received_at,
         bank_account_id, reference, status 'unallocated'|'partially_allocated'|'allocated'|'bounced'|'refunded')
receipt_allocations(id pk, receipt_id, target_type 'installment'|'policy'|'claim_recovery'|'other', target_id, amount_minor, allocated_at, allocated_by)
suspense_items(id pk, t, e, receipt_id, amount_minor, aged_since, status 'open'|'allocated'|'refunded'|'written_off')

commission_entries(id pk, t, agent_id, policy_id, receipt_allocation_id null, kind 'earned'|'clawback'|'bonus',
         base_minor, rate_bp, amount_minor, withholding_minor, status 'accrued'|'approved'|'paid'|'reversed', statement_id null)

bank_accounts(id pk, t, e, gl_account_id, bank_name, account_no_masked, currency)
bank_statement_lines(id pk, bank_account_id, posted_on, amount_minor, reference, raw jsonb, match_status)
bank_matches(id pk, statement_line_id, journal_line_id, matched_by, method 'auto'|'manual', confidence)
```

---

## 3. Posting Rule Specification

### 3.1 Concepts

| Term | Definition |
|---|---|
| Domain Event | Something that happened in a business module (`PolicyIssued`). Not necessarily financial. |
| Accounting Event | A financial fact submitted to the kernel, with amounts, dates, dimensions, idempotency key. Produced by an **Accounting Event Mapper** in the business module. |
| Posting Rule | Versioned, effective-dated template: event type + conditions → lines of (account role, side, amount expression). |
| Posting Context | Resolved inputs at posting time: entity, book(s), period, role→account map, FX rate, tax rates, dimension requirements. |
| Journal Batch | All journals generated from one accounting event (one per book). |
| Journal / Lines | The immutable result. |

**DECISION:** not every domain event has a rule. `QuoteCreated`, `ClaimRegistered`, `PolicyRenewed` (renewal creates a new `PolicyIssued`) produce no accounting event.

### 3.2 Rule schema (stored as JSONB, versioned)

```jsonc
{
  "id": "uuid", "code": "PREMIUM_RECEIVED.default", "version": 3,
  "event_type": "PREMIUM_RECEIVED",
  "applies_to": { "product_codes": ["*"], "lob": ["*"], "channels": ["*"] },
  "condition": "payload.channel != 'agent_cash'",           // expression, optional
  "effective_from": "2026-01-01", "effective_to": null,
  "books": ["LOCAL", "IFRS"],                               // or per-book overrides
  "currency": { "mode": "transaction", "base_conversion": "spot_at_transaction_date" },
  "tax": { "mode": "none" | "split_from_gross" | "add_to_net", "tax_type": "VAT" },
  "lines": [
    { "role": "bank_clearing",        "side": "debit",  "amount": "payload.amount" },
    { "role": "premium_receivable",   "side": "credit", "amount": "payload.amount", "dims": { "customer": "payload.customer_id" } }
  ],
  "dimensions": { "required": ["branch","product","policy","customer"], "inherit_from_event": true },
  "validation": ["amount > 0", "period.open", "policy.status in ['issued','active']"],
  "reversal": { "strategy": "mirror", "allowed_until": "period_locked" },
  "idempotency": { "key": "PREMIUM_RECEIVED:{payload.receipt_allocation_id}" },
  "rounding": { "mode": "half_even", "residual_role": "rounding_difference" }
}
```

**Amount expression language (MVP, DECISION):** a tiny safe expression evaluator (Symfony ExpressionLanguage) over `payload.*`, `event.*`, with functions `pct(base, bp)`, `min`, `max`, `sub`, `add`, `tax(base, tax_type)`. All arithmetic in minor units (int). No arbitrary PHP.

### 3.3 Resolution algorithm (PostingEngine::handle)

```
1. Load event (status=queued). SET LOCAL app.tenant_id.
2. rule = select rule where event_type match, applies_to match, condition true,
          effective_from <= effective_date < effective_to, highest specificity, latest version.
   none → status=rejected (reason NO_RULE). >1 same specificity → rejected (AMBIGUOUS_RULE).
3. For each book in rule.books:
   a. period = find period(entity, book, posting_date). status!='open' → failed (PERIOD_CLOSED); if soft_locked and actor lacks 'accounting.post_in_soft_locked' → failed.
   b. roles → accounts via account_role_mappings(effective at posting_date). missing → failed (UNMAPPED_ROLE).
   c. evaluate line amounts; drop zero lines; apply tax splitting; apply rounding residual line.
   d. dims: merge event.dimensions + line.dims; assert required present (DIMENSION_MISSING).
   e. FX: base_amount = amount × rate(currency→entity.base, rule.currency.base_conversion).
   f. build journal(status=draft) + lines; assert Σdebit==Σcredit per currency (UNBALANCED → failed, never posted).
   g. if approval_policy matches (manual/adjustment/large amount): status=pending_approval, stop; else approved.
4. Post: single DB transaction: insert journals/lines, status=posted, posted_at, event.status=posted,
   subledger_control deltas, audit_event, outbox row 'JournalPosted'.
5. Any exception → event.status=failed with reason; job retries only for transient DB/lock errors.
```

**INVARIANT:** the engine never emits a journal with `posted` status unless step 3f passed and the DB constraint trigger passed.

### 3.4 Seeded account roles (MVP)

`bank_clearing`, `bank_main`, `suspense_receipts`, `premium_receivable`, `unearned_premium`, `premium_income`, `premium_tax_payable`, `agent_receivable`, `commission_expense`, `commission_payable`, `commission_withholding_payable`, `dac_asset` (LATER), `claims_outstanding` (reserve liability), `claims_expense`, `claims_payable`, `claims_recovery_income`, `recovery_receivable`, `rounding_difference`, `fx_gain_loss`, `salary_expense`, `salary_payable`, `employee_tax_payable`, `pf_payable`, `employer_pf_expense`, `retained_earnings`.

---

## 4. Ten worked examples

Conventions: entity base currency BDT; amounts in BDT minor units shown as BDT for readability; dims abbreviated `B`=branch, `P`=product, `POL`=policy, `AG`=agent, `CU`=customer, `CL`=claim, `EMP`=employee. Book LOCAL unless noted.

### 4.1 Policy issued — POL-1001, Motor Comprehensive, gross 120,000 incl. 15% VAT
Event `POLICY_ISSUED` {gross 120,000; net 104,348; vat 15,652; term 12m; inception 2026-09-01}
Rule `POLICY_ISSUED.default`, tax mode `split_from_gross`.

| Line | Account role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | premium_receivable | 120,000 | | B,P,POL,CU,AG |
| 2 | unearned_premium | | 104,348 | B,P,POL |
| 3 | premium_tax_payable | | 15,652 | B,P,POL |

Idempotency key `POLICY_ISSUED:{policy_transaction_id}`. Effective date = inception.
**DECISION:** written premium recognized to Unearned on issue (accrual). Broker-model variant would post to `carrier_payable` instead — rule set swap, no code change.

### 4.2 Premium received — customer pays 50,000 by bank transfer, allocated to installment 1
Event `PREMIUM_RECEIVED` {amount 50,000; receipt_allocation_id; bank_account_id}

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | bank_main (bank_accounts.gl_account_id overrides role) | 50,000 | | B |
| 2 | premium_receivable | | 50,000 | B,P,POL,CU,AG |

Triggers `COMMISSION_EARNED` (4.5) because commission is on receipt.

### 4.3 Premium earned — month-end run for Sept 2026, daily_365 method
30 days / 365 × 104,348 = 8,576 (half-even). Event `PREMIUM_EARNED` {policy, period 2026-09, earned 8,576}, key `PREMIUM_EARNED:{policy_id}:{period_id}`.

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | unearned_premium | 8,576 | | B,P,POL |
| 2 | premium_income | | 8,576 | B,P,POL,AG,CU |

`premium_earning_ledger` unique(policy, period) makes a rerun a no-op. Final period absorbs rounding residual so Σearned = net premium exactly.

### 4.4 Cancellation & refund — cancelled 2026-12-01 after 3 months, pro-rata, 70,000 already received
Unearned remaining = 104,348 − 3×8,696 (illustrative) = 78,260. Receivable outstanding = 120,000 − 70,000 = 50,000.
Event A `POLICY_CANCELLED` {unearned_remaining 78,260; vat_reversal 11,739; receivable_outstanding 50,000; refund_due 39,999}

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | unearned_premium | 78,260 | | B,P,POL |
| 2 | premium_tax_payable | 11,739 | | B,P,POL |
| 3 | premium_receivable | | 50,000 | B,P,POL,CU |
| 4 | customer_refund_payable | | 39,999 | B,POL,CU |

Event B `REFUND_ISSUED` when paid: DR customer_refund_payable 39,999 / CR bank_main 39,999.
Event C `COMMISSION_CLAWBACK` (4.5 mirror) on the unearned share.
**OPEN:** whether VAT on cancelled premium is refundable in the customer's jurisdiction — rule line 2 is configurable off.

### 4.5 Commission earned — 10% on 50,000 received, 5% withholding tax
Event `COMMISSION_EARNED` {base 50,000; rate 1000bp; amount 5,000; withholding 250}

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | commission_expense | 5,000 | | B,P,POL,AG |
| 2 | commission_payable | | 4,750 | AG |
| 3 | commission_withholding_payable | | 250 | AG |

Key `COMMISSION_EARNED:{commission_entry_id}`. LATER (Phase 3): DR `dac_asset` instead of expense, amortized with earning.

### 4.6 Claim reserve — CL-77 registered, initial case reserve 200,000; later adjusted to 250,000
Event `CLAIM_RESERVED` {amount 200,000}

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | claims_expense | 200,000 | | B,P,POL,CL |
| 2 | claims_outstanding | | 200,000 | B,P,POL,CL |

Event `CLAIM_RESERVE_ADJUSTED` {delta +50,000} → same lines for 50,000 (`kind='adjustment'`, `corrects_journal_id`=first). A decrease posts the mirror. Reserve history = ordered adjustments.

### 4.7 Claim payment — approved 180,000, paid by bank
Event `CLAIM_APPROVED` {amount 180,000}: DR claims_outstanding 180,000 / CR claims_payable 180,000 (dims B,P,POL,CL).
Event `CLAIM_PAID`: DR claims_payable 180,000 / CR bank_main 180,000.
Event `CLAIM_CLOSED` {release 70,000}: DR claims_outstanding 70,000 / CR claims_expense 70,000 (reserve release). **INVARIANT:** Σ(claims_outstanding by CL) = 0 after close.

### 4.8 Claim recovery — salvage sold for 30,000, cash received
Event `CLAIM_RECOVERED` {amount 30,000; type salvage}

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | bank_main | 30,000 | | B |
| 2 | claims_recovery_income | | 30,000 | B,P,POL,CL |

If accrued first (invoice raised): DR recovery_receivable / CR claims_recovery_income, then DR bank / CR recovery_receivable on cash.

### 4.9 Suspense receipt then allocation — 25,000 arrives with unreadable reference
Event `RECEIPT_RECORDED` {amount 25,000; unallocated}

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | bank_main | 25,000 | | B |
| 2 | suspense_receipts | | 25,000 | B, receipt |

Three days later allocated to POL-2002 installment. Event `RECEIPT_ALLOCATED` {amount 25,000; from suspense}

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1 | suspense_receipts | 25,000 | | B, receipt |
| 2 | premium_receivable | | 25,000 | B,P,POL,CU,AG |

Key `RECEIPT_ALLOCATED:{receipt_allocation_id}`. Suspense ageing report from `suspense_items.aged_since`.

### 4.10 Payroll posting — Sept run, 40 employees, totals: gross 4,000,000; employee tax 300,000; employee PF 400,000; employer PF 400,000; net 3,300,000
Event `PAYROLL_POSTED` {run_id; lines per employee} — engine aggregates one journal with per-employee dims (or summary lines + payroll subledger; DECISION: per-employee lines, `dim_employee`, `dim_cost_centre`).

| Line | Role | DR | CR | Dims |
|---|---|---|---|---|
| 1..n | salary_expense | 4,000,000 | | B,EMP,cost_centre |
| n+1..2n | employer_pf_expense | 400,000 | | B,EMP,cost_centre |
| | salary_payable | | 3,300,000 | EMP |
| | employee_tax_payable | | 300,000 | |
| | pf_payable | | 800,000 | |

`PAYROLL_PAID` per bank file: DR salary_payable / CR bank_main. Key `PAYROLL_POSTED:{payroll_run_id}`.

---

## 5. State machines

### 5.1 Accounting event
```
received ─validate─▶ queued ─worker─▶ posting ─▶ posted
   │                    │                │
   └─▶ rejected         └─▶ failed(reason) ──retry(transient only)──▶ queued
                                          └─▶ superseded (newer source_version arrived)
```
Terminal: posted, rejected, superseded. `failed` visible in exception queue; manual "requeue" needs `accounting.requeue_event`.

### 5.2 Journal
```
draft ─▶ pending_approval ─approve─▶ approved ─▶ queued ─▶ posting ─▶ posted ─reverse─▶ reversed
  │            └─reject─▶ cancelled                                     │
  └─▶ cancelled                                                          └─(never edited)
```
System journals skip approval unless an approval policy matches (amount threshold, kind=adjustment/manual, back-dated).

### 5.3 Fiscal period
```
open ─soft_lock─▶ soft_locked ─lock─▶ locked
  ▲                   │                 │
  └────reopen(approval + reason)────────┘
```
Reopen emits audit + `PeriodReopened`; posting into a reopened period requires the close run to be re-executed.

### 5.4 Policy
```
quote ─issue─▶ issued ─inception reached─▶ active ─▶ expired
                 │                           ├─cancel─▶ cancelled
                 │                           ├─lapse──▶ lapsed ─reinstate─▶ active
                 └─cancel─▶ cancelled        └─renew──▶ renewed (+ new policy issued)
endorse: allowed in issued|active, bumps policy.version, creates policy_transaction
```
Emits accounting events on: issue, endorse (delta), cancel, refund, earning (batch).

### 5.5 Claim
```
registered ─reserve─▶ reserved ─adjust*─▶ reserved ─approve─▶ approved ─pay(partial*)─▶ paid ─close─▶ closed
     │                                        │                                              ▲
     └─reject─▶ rejected                      └─reject─▶ rejected                            └─ recovery* (any time after paid)
closed ─reopen(approval)─▶ reserved
```
Approval limits by amount/role at `approve` and each `pay`.

### 5.6 Commission entry
`accrued ─▶ approved ─▶ paid`; `accrued|approved ─clawback─▶ reversed` (creates negative entry netted in next statement).

### 5.7 Month-end close (first-class workflow)

Tasks, order, dependencies, owner, blocking condition:

| # | Task | Depends | Owner | Blocks close if |
|---|---|---|---|---|
| 1 | Premium earning batch run | — | System | any policy missing earning row for period |
| 2 | Suspense review | — | Branch accountant | open suspense > threshold days (configurable) unless waived with reason |
| 3 | Bank reconciliation | — | Treasury | unmatched lines > 0 without explanation |
| 4 | Premium subledger recon | 1 | Accounting | variance ≠ 0 |
| 5 | Claims subledger recon | — | Claims accounting | variance ≠ 0 |
| 6 | Commission subledger recon | 1 | Accounting | variance ≠ 0 |
| 7 | AP/AR recon (LATER 1B+) | — | Accounting | variance ≠ 0 |
| 8 | Accruals & prepayments (manual journals) | — | Accounting | — |
| 9 | Depreciation (LATER) | — | System | — |
| 10 | FX revaluation | 3 | System | — |
| 11 | DAC amortization (LATER) | 1 | System | — |
| 12 | Tax computation | 1,8 | Accounting | — |
| 13 | Trial balance generated & reviewed | 1–12 | Finance manager | TB unbalanced (should be impossible) |
| 14 | Financial statements generated | 13 | System | — |
| 15 | Close checklist sign-off | 13,14 | Finance manager | any task not done/skipped-with-reason |
| 16 | Period lock | 15 | Finance manager (approval by CFO role) | — |

**INVARIANT:** `period.lock()` refuses if any `period_close_tasks.status ∉ {done, skipped}` or any `reconciliation_runs.status='variance'` for that period. Soft-lock happens at task 13; only users with `accounting.post_in_soft_locked` can post between 13 and 16 (re-runs 13/14 automatically).

---

## 6. Subledger-to-GL reconciliation design

### 6.1 Classification (DECISION — one source of truth)

| Module | Type | Authoritative balance | Reconciles to |
|---|---|---|---|
| General Ledger | **The** financial source of truth | account balances | — |
| Premium (installments/receivable) | Accounting subledger | Σ(installment.amount − paid) per policy | `premium_receivable` control |
| Claims (reserves, payables) | Accounting subledger | Σ open reserves; Σ approved-unpaid | `claims_outstanding`, `claims_payable` |
| Commission | Accounting subledger | Σ entries not paid | `commission_payable` |
| Bank | **Cash-management/recon domain**, not a subledger | bank statement (external) | `bank_main` per account via statement matching |
| Customer / Agent | **Subsidiary views**, not ledgers | derived: journal lines filtered by `dim_customer`/`dim_agent` | trivially equal to GL by construction |
| Suspense | subledger (small) | Σ open suspense_items | `suspense_receipts` |
| AP / AR / Payroll / Fixed assets | subledgers (Phase 2) | module balances | respective control accounts |

Customer and agent statements are **queries over journal lines**, never separately maintained balances. This eliminates two competing sources of truth.

### 6.2 Reconciliation contract (every subledger implements)

```php
interface SubledgerReconciler {
    public function subledger(): Subledger;                          // enum
    public function balanceAt(EntityId $e, CarbonImmutable $asOf): Money;   // authoritative subledger balance
    public function itemsAt(EntityId $e, CarbonImmutable $asOf): iterable;  // for exception drill-down
}
```
Kernel side: `ReconciliationService::run(subledger, period)`:
1. `sub = reconciler->balanceAt(periodEnd)`; `gl = LedgerQuery::balance(controlAccount, book, periodEnd)`.
2. `variance = sub − gl`. Persist `reconciliation_runs`.
3. If variance ≠ 0: diff items vs journal lines tagged with the subledger's dimension → `reconciliation_exceptions`; status `variance`; notify owner role; close task blocked.
4. Resolution: post correcting journal (approved) or fix subledger item; rerun; `resolved` with note.

**INVARIANT:** control accounts (`accounts.is_control=true`) reject manual journals unless actor has `accounting.post_to_control` and the journal is `kind='adjustment'` with reason — keeps subledger and GL from drifting silently.

Runs: nightly (`recon` queue) for all subledgers + on-demand + mandatory in close.

---

## 7. Permissions, RBAC, SoD

### 7.1 Permission catalogue (MVP subset; code = `context.action`)

`accounting.view_journals`, `accounting.create_manual_journal`, `accounting.approve_journal`, `accounting.reverse_journal`, `accounting.post_to_control`, `accounting.post_in_soft_locked`, `accounting.requeue_event`, `accounting.manage_coa`, `accounting.manage_posting_rules`, `periods.soft_lock`, `periods.lock`, `periods.reopen`,
`policy.create`, `policy.issue`, `policy.endorse`, `policy.cancel`, `receipt.create`, `receipt.allocate`, `receipt.refund_request`, `receipt.refund_release`,
`claim.register`, `claim.reserve`, `claim.approve`, `claim.pay_request`, `claim.pay_release`,
`commission.approve`, `commission.pay`, `bank.match`, `bank.import`, `numbering.void`, `platform.manage_users`, `platform.manage_roles`, `audit.view`, `reports.financial`, `reports.regulatory`.

### 7.2 Roles (seeded templates per tenant, editable)

| Role | Key permissions |
|---|---|
| Branch Officer | policy.create/issue, receipt.create |
| Branch Manager | + policy.cancel, receipt.allocate, claim.register |
| Claims Officer | claim.register, claim.reserve |
| Claims Manager | + claim.approve (≤ limit), claim.pay_request |
| Accountant | accounting.view/create_manual_journal, bank.match/import, receipt.allocate |
| Finance Manager | + accounting.approve_journal, reverse_journal, periods.soft_lock/lock, commission.approve, claim.pay_release, receipt.refund_release |
| CFO | + periods.reopen, accounting.post_to_control, approve above thresholds |
| Auditor (read-only) | audit.view, accounting.view_journals, reports.* |
| Tenant Admin | platform.* (no financial permissions by default) |

Scope: `user_roles.scope` restricts to entity/branch; queries apply scope filters via a `ScopedQuery` trait (branch users never see other branches).

### 7.3 Segregation of duties

`sod_rules` seeded (mode `block` by default):
- `receipt.refund_request` ✕ `receipt.refund_release`
- `claim.pay_request` ✕ `claim.pay_release`
- `claim.reserve` ✕ `claim.approve` (same claim)
- `accounting.create_manual_journal` ✕ `accounting.approve_journal` (same journal)
- `commission.approve` ✕ `commission.pay`
- `platform.manage_roles` ✕ any `accounting.*` (admins don't post)

Enforcement points: (1) role assignment warns/blocks when a user would hold both; (2) action time — `SodGuard::assert(actor, permission, object)` checks the object's history (`audit_events`) so the *same person* cannot be maker and checker even with both permissions via two roles. Auditor role can never be combined with write permissions.

Approval policies (amount thresholds) are data: e.g. claim approve ≤ 500,000 → Claims Manager; > 500,000 → Finance Manager + CFO sequential. **OPEN:** actual thresholds — from customer.

---

## 8. Idempotency, outbox, jobs, tenant context

### 8.1 Idempotency
- `accounting_events.idempotency_key` unique per tenant; key template defined by the rule (`§3.2`) and computed by the mapper from the **source row id** (+ `source_version` where re-emission is legitimate, e.g. endorsement v2).
- Submit = `INSERT … ON CONFLICT (tenant_id, idempotency_key) DO NOTHING RETURNING id`; conflict → return existing event (no error).
- Journal number reserved only at post time; `journals` unique(entity, book, number).
- Batch jobs (earning) use natural uniqueness (`premium_earning_ledger` unique) so reruns are no-ops.

### 8.2 Transaction boundary (INVARIANT)
```
DB::transaction(function () {
    $tx = $policy->issue(...);                 // source business row
    $event = AccountingEventMapper::from($tx); // accounting_events row (status=received)
    Outbox::add(new PostAccountingEvent($event->id));  // outbox row
});                                            // COMMIT — all three or none
```
Outbox relay (every 1s, `posting` queue): `SELECT … FOR UPDATE SKIP LOCKED LIMIT 100` → dispatch job → mark relayed. Laravel's `dispatch()->afterCommit()` is used as a fast path; the outbox table is the guarantee if the process dies between commit and dispatch.

### 8.3 Exactly-once business effect
Delivery is at-least-once (Horizon retries). Effect is once because: (a) job handler starts with `UPDATE accounting_events SET status='posting' WHERE id=? AND status='queued'` — 0 rows → exit; (b) posting happens inside one transaction; (c) journal number/idempotency uniques as final guard; (d) `ShouldBeUnique` on the job keyed by event id prevents concurrent workers.

### 8.4 Retry & dead-letter
Transient (deadlock, connection, lock timeout): retry 5× with backoff 5s→5m. Business failures (PERIOD_CLOSED, UNMAPPED_ROLE, DIMENSION_MISSING, UNBALANCED): **no retry**, status `failed`, land in the Accounting Exceptions screen with reason; fixed by config change + manual requeue. Horizon failed-jobs table is the infrastructure DLQ; a daily job alerts if it is non-empty.

### 8.5 Background jobs
| Job | Queue | Schedule | Idempotent by |
|---|---|---|---|
| OutboxRelay | posting | every second | relayed flag |
| PostAccountingEvent | posting | on demand | status CAS + unique key |
| PremiumEarningRun | batch | nightly + close | earning ledger unique |
| SubledgerReconcile | recon | nightly + close | run per (subledger, period, date) |
| BankMatchSuggest | recon | on import | match table |
| ReservationSweeper | default | every 15 min | status |
| ReportSnapshot | reports | on demand | — |

### 8.6 Tenant context (INVARIANT — a developer cannot bypass)
1. **HTTP:** `ResolveTenant` middleware from OIDC claims/host → `TenantContext::set()` → `DB::statement("SET LOCAL app.tenant_id = ?")` inside a per-request transaction wrapper *and* Eloquent global scope `TenantScope` on every model using `BelongsToTenant`.
2. **Jobs:** every job carries `tenant_id`; `TenantAwareJob` middleware re-establishes context; a job without tenant fails fast.
3. **Imports/exports/reports:** run as jobs → same path.
4. **Integrations (API tokens):** Sanctum token bound to tenant; `ResolveTenant` reads token.
5. **Admin ops:** `SuperAdmin` context sets `app.tenant_id` explicitly per operation and is logged; no "all tenants" queries on business tables.
6. **DB-level:** RLS `ENABLE + FORCE` on every tenant table; app connects as a non-owner role so `FORCE ROW LEVEL SECURITY` applies. Raw `DB::table()` without `SET LOCAL` returns **zero rows**, not another tenant's.
7. **Tests:** see §9 — a test that creates two tenants and asserts cross-visibility is zero for every model and every raw table.

---

## 9. Testing strategy + MVP boundary

### 9.1 Test layers
| Layer | Tool | What |
|---|---|---|
| Invariant tests (kernel) | Pest + real Postgres (Docker) | unbalanced journal rejected at service and DB trigger; posted journal update rejected; locked period rejects; missing dimension rejects; control account manual post rejected |
| Posting-rule golden tests | Pest, data-driven | the 10 examples in §4 as fixtures: event JSON → expected lines JSON; any rule change must update fixtures |
| Idempotency tests | Pest | same event submitted twice → one journal; job executed twice concurrently → one journal; crash between commit and dispatch → outbox relays |
| Tenancy tests | Pest, two tenants | for every table with `tenant_id`: rows invisible cross-tenant via Eloquent and via raw SQL; job without tenant fails |
| SoD/permission tests | Pest | maker≠checker on refund, claim pay, manual journal; auditor cannot write |
| Close tests | Pest | lock refused with open variance; reopen requires approval; earning rerun no-op |
| Property tests | Pest + generators | random policy terms/amounts: Σearned == net premium; reserve lifecycle Σ=0 at close |
| Static | PHPStan L8, Larastan, architecture test (namespace dependency rules §1) | |
| E2E | Playwright | issue policy → receive → earn → close month, one happy path |
| Performance smoke | k6 | 10k events posted < 60s on a 2-vCPU box (target, not promise) |

**DECISION:** CI blocks merge on all but performance smoke.

### 9.2 MVP vs LATER

| Capability | MVP (Phase 0 + 1A) | LATER |
|---|---|---|
| Books | LOCAL only (schema supports multi) | IFRS book, MGMT book |
| Currencies | single base + capture FX fields | multi-currency posting, revaluation |
| Approval | maker-checker + amount threshold, sequential | parallel, delegation, escalation, SLA |
| Rules | JSON rules + expression evaluator, admin UI read-only | rule editor UI, simulation/dry-run |
| Subledgers | premium, suspense, commission, bank recon | claims (1B), AP/AR/payroll/FA (2), reinsurance (3) |
| Close | tasks 1–6, 8, 13–16 | 7, 9–12 |
| Tax | rate table + split/withhold | exemptions, returns, reporting |
| Reports | TB, P&L, BS, premium register, receivable ageing, suspense ageing, commission statement | regulatory packs, custom builder |
| Multi-entity | data model + single-entity UI | consolidation, intercompany |
| DAC | expense on receipt | capitalize + amortize |
| Portals | none (Inertia admin only) | agent portal via REST |
| AI | none | bank-match suggestions first |

### 9.3 Definition of done for Phase 0
A vertical slice: tenant + entity + branch + users/roles; COA import; period setup; posting rules for `POLICY_ISSUED`, `PREMIUM_RECEIVED`, `PREMIUM_EARNED`, `RECEIPT_RECORDED/ALLOCATED`; manual journal with approval; reversal; TB report; all §9.1 invariant, idempotency, tenancy tests green. Timebox 6–8 weeks; correctness wins over the date.

---

## OPEN questions (do not invent)
1. Carrier vs broker/MGA — decides Phase 1B and rule set.
2. Jurisdiction tax rules on premium (VAT/stamp/levy) and on cancellation refunds.
3. Approval thresholds and who the roles map to at the customer.
4. Earning method per product (365ths vs monthly) and short-rate table.
5. Regulator report formats (IDRA or other) — Phase 3 input.
6. Whether opening balances come from an existing system (import format).
