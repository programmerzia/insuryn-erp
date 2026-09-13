# Design Note — Distribution & Agency Management (Phase 2 module)

Tags as in the design package: DECISION / INVARIANT / ASSUMPTION / OPEN / MVP / LATER.

## 0. Positioning
One module, named **Distribution**, covering every way business reaches the insurer: individual agents, agency organisations, salaried business-development officers (BDOs), brokers, bancassurance/partner channels, and direct. It owns *who sold it, under which channel, how they are paid and whether they were allowed to*. Commission accounting stays in the Commission subledger; Distribution feeds it.

**DECISION** Built configuration-first so the same code serves:
- *Life insurer*: multi-level agency hierarchy, first-year/renewal commission, overrides, persistency bonus.
- *Non-life insurer (Bangladesh, 2026)*: zero commission to individual agents; salaried BDOs with targets and incentives; brokers/corporate agents where licensed.
The mode is a per-product **compensation scheme**, not a global switch, because an insurer may run both.

**ASSUMPTION** Regulatory caps and the zero-commission rule are modelled as *compliance rules* on compensation schemes (max % by product/term/year, allowed producer types), enforced at plan creation and at calculation — never hard-coded.

## 1. Domain model

```
channels(id, t, code, name, type: 'agency'|'bdo'|'broker'|'bancassurance'|'partner'|'direct', status)

producers(id, t, party_id, code, type: 'agent'|'agency_org'|'bdo'|'broker'|'partner',
          channel_id, branch_id, status: 'applicant'|'active'|'suspended'|'terminated',
          employee_id null (BDO = employee), joined_on, terminated_on, termination_reason)
producer_licences(id, producer_id, authority 'IDRA', licence_no, class (life|non-life|both),
          issued_on, expires_on, document_id, status)
producer_hierarchy(id, t, producer_id, parent_producer_id, level_code, effective_from, effective_to)
          -- effective-dated: transfers keep history; INVARIANT no cycles, one active parent
hierarchy_levels(t, scheme_id, level_code, rank, label)   -- e.g. FA < UM < BM < AGM < RM (life)
producer_territories(producer_id, branch_id|region, effective_from, effective_to)

compensation_schemes(id, t, code, name, mode: 'commission'|'salary_incentive'|'hybrid'|'none',
          effective_from, effective_to, compliance_profile jsonb)
compensation_rules(id, scheme_id, product_id|null, producer_type, level_code|null,
          basis: 'premium_received'|'premium_written'|'net_premium',
          policy_year_from, policy_year_to,        -- 1..1 = first year, 2..99 = renewal
          rate_bp, override_rate_bp, cap_bp, min_persistency_bp null, effective_from, effective_to)
incentive_plans(id, t, code, period: 'monthly'|'quarterly'|'annual', metric: 'premium'|'policies'|'persistency'|'collections',
          tiers jsonb, applies_to producer_type|channel|level)
targets(id, t, producer_id|branch_id|channel_id, period, metric, target_minor|count, set_by)

producer_ledger (subsidiary view over journal lines dim_agent) -- not a table
producer_advances(id, producer_id, amount_minor, issued_on, recovery_rule, balance_minor, status)
producer_statements(id, producer_id, period, earned_minor, override_minor, bonus_minor,
          clawback_minor, withholding_minor, advances_recovered_minor, net_minor, status, approved_by, paid_via 'payroll'|'ap')
leads / activities (LATER): lead, follow-up, proposal, conversion — the Vymo-style sales layer
```

Existing `agents` table → migrate into `producers` (type=agent); `commission_entries` stays and gains `scheme_id`, `rule_id`, `level_code`, `hierarchy_snapshot jsonb` (who was above at the time — payouts must not change when the tree changes later). **INVARIANT.**

## 2. Compensation calculation (the core)
Trigger: `RECEIPT_ALLOCATED` (basis premium_received) or `POLICY_ISSUED` (premium_written), per rule.
1. Resolve scheme via product → scheme; check producer eligibility (active, licence valid for product class, type allowed by compliance profile). Ineligible → no commission, **compliance exception** logged, policy still issues.
2. Compute direct commission by policy year and rule; apply cap.
3. Walk `producer_hierarchy` snapshot at transaction date; compute override per level rule.
4. Persistency/min-production conditions evaluated at statement time, not at accrual (hold as `conditional`).
5. Emit `COMMISSION_EARNED` per beneficiary (direct + each override) with `hierarchy_snapshot`; clawback on cancellation proportional to unearned, per beneficiary.
6. Statement run (monthly): accruals + bonuses + clawbacks − withholding − advance recovery → `producer_statements`; approval; payout via payroll (BDO/agent on payroll) or AP (agencies, brokers) → `COMMISSION_PAID`.

**INVARIANT** Σ commission emitted for a policy ≤ compliance cap for that product; violation blocks the calculation, not the policy.

## 3. Lifecycle & compliance
Applicant → (KYC, licence, training, agreement signed) → Active → Suspended (licence expired, misconduct, debit balance over limit) → Terminated (final settlement: recover advances, freeze renewals per law, pay balance).
Rules: no new business for a producer without a valid licence of the right class (blocking); renewal commission only while licence valid (Insurance Act §40(1B) pattern) → rule flag `renewal_requires_valid_licence`; licence-expiry alerts at 60/30/7 days; IDRA agent register export.

## 4. Sales performance (salaried-channel needs)
Targets by producer/branch/channel/period; achievement from premium register and collections; incentive tiers computed at period end into `producer_statements.bonus`; leaderboards and persistency (13th/25th-month) reports. For BDOs, bonus posts through payroll as an earning type.

## 5. Producer portal (mobile-first, REST)
My customers & policies, renewals due, collections to deposit, my statement, licence status, targets vs achievement, submit proposal (LATER: full digital proposal). Read-only in MVP except collection recording.

## 6. Screens (follow ux-design-brief)
Producers queue (licence expiring, debit balance, pending statements) · Producer page (Overview · Hierarchy · Compensation · Production · Statements · Documents · Audit) · Hierarchy tree with drag-transfer + effective date · Scheme & rule editor with validation against compliance profile · Statement run workbench (preview → approve → pay) · Targets grid.

## 7. MVP vs LATER
MVP: channels, producers (agent, agency_org, bdo), licences with blocking, effective-dated hierarchy, schemes/rules for commission and salary_incentive modes, compliance caps, calculation with overrides & snapshot, monthly statements → payroll/AP, targets & achievement, producer queue and page.
LATER: leads/activities, contests, bancassurance settlement, full proposal submission in portal, IDRA electronic returns.

## OPEN (do not invent; make configurable with a conservative default)
1. Exact IDRA caps and the current non-life zero-commission circular text → `compliance_profile` values; default = commission mode disabled for non-life products until configured.
2. Life hierarchy level names and override rates → seeded example scheme only.
3. Whether renewal commission continues after termination (jurisdiction) → flag, default false.
