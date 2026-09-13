# Design Note — Rating, Quotation → Cover Note → Policy, Documents, Renewals (Phase 3)

Tags: DECISION / INVARIANT / ASSUMPTION / OPEN / MVP / LATER. Depends on: design-package-v1 (Product, Policy, events), distribution-module-design (producer eligibility), ux-design-brief.

## 0. Scope
G1 Rating engine · G2 Quotation → Proposal → Cover note → Policy with document generation · G3 Renewals & notices. Non-life first (motor, fire, marine cargo, misc); the model is class-agnostic so life products (sum assured × age/term tables) fit LATER.

**DECISION** Rating is a pure, deterministic, versioned function: `rate(productVersion, riskInputs, asOfDate) → RatingResult`. No side effects; the same inputs always give the same premium; every result stores the tariff version and the full breakdown so a premium can be re-explained years later (INVARIANT).

## 1. Product model extensions

```
product_classes(code, name)  -- motor, fire, marine_cargo, marine_hull, engineering, misc, health, life
product_versions  += class_code, risk_schema jsonb (fields the officer must capture), rating_plan_id,
                     duty_profile jsonb, document_set_id, allow_short_period bool, min_premium_minor
coverages(id, product_version_id, code, name, mandatory bool, basis: 'sum_insured'|'flat'|'per_unit'|'pct_of_base',
          rating_rule_ref, limit_rule jsonb, deductible_rule jsonb)
rating_plans(id, t, code, name, class_code, version, effective_from, effective_to, status: 'draft'|'active'|'retired',
          source: 'idra_tariff'|'company', approved_by)
rate_tables(id, plan_id, code, name, dimensions jsonb ["vehicle_type","cc_band","age_band"], value_type: 'rate_pm'|'rate_pct'|'flat')
rate_table_rows(id, table_id, keys jsonb {"vehicle_type":"private","cc_band":"1301-1800"}, value_minor|value_bp, effective_from, effective_to)
rating_steps(id, plan_id, order_no, kind: 'base'|'coverage'|'loading'|'discount'|'minimum'|'rounding'|'duty'|'tax',
          expression, condition, applies_to coverage_code|null, label_en, label_bn)
duties(id, t, code 'vat'|'stamp'|'levy', basis 'pct_of_premium'|'flat_per_policy'|'per_sum_insured_band', value, class_codes[], effective_from, effective_to)
```

Rating step expressions use the same safe evaluator as posting rules, with functions `lookup(table_code, keys…)`, `pct(base,bp)`, `min/max`, `band(value, table)`, over `risk.*`, `coverage.*`, `sum_insured`, `running.premium`. Money in minor units. **INVARIANT** no floats.

### Example — Motor Comprehensive (illustrative structure, not real tariff)
1. base = lookup('motor_base', risk.vehicle_type, band(risk.cc, 'cc_bands')) applied to sum_insured (rate per mille)
2. + coverage 'passenger_liability' = flat per seat × risk.seats
3. loading: driver age < 25 → +10%; vehicle age > 10y → +15%
4. discount: no-claim bonus from prior policy history 0/10/20/30%
5. minimum premium per class
6. rounding to nearest 1.00
7. duties: stamp (flat by class), VAT 15% on premium **ASSUMPTION** — values live in `duties`, marked OPEN until confirmed against current NBR/IDRA rules
8. result: net_premium, per-coverage premium, loadings, discounts, duties, gross, plus `explanation[]` lines with labels EN/BN.

## 2. Quotation → Proposal → Cover note → Policy

```
quotations(id, t, e, branch_id, number, product_version_id, customer_party_id|null, producer_id|null,
           risk_inputs jsonb, rating_result jsonb, rating_plan_version, valid_until, status: 'draft'|'issued'|'expired'|'converted'|'declined')
proposals(id, t, quotation_id, number, kyc_status, underwriting_status: 'auto_approved'|'referred'|'approved'|'declined',
           referral_reason, decided_by, documents, status)
cover_notes(id, t, proposal_id, number, valid_from, valid_to (max N days by class, OPEN), issued_by, status: 'active'|'superseded'|'cancelled')
policies  += quotation_id, proposal_id, cover_note_id, risk_inputs jsonb, rating_result jsonb, rating_plan_version, previous_policy_id (renewal chain)
```

Flow (UI: stepper page with summary rail):
1. **Quote**: pick product → capture risk_schema fields → live rating → save/print quotation (valid 15 days, config).
2. **Proposal**: attach KYC/docs → underwriting rules: `auto_approve` unless referral condition (sum insured > limit, risk flags, producer ineligible) → referral queue for underwriter with approve/decline/counter (re-rate with manual loading, reason mandatory, audit).
3. **Cover note** (optional, non-life practice): temporary evidence of cover pending premium/docs; has its own number; issuing it does **not** post accounting (ASSUMPTION; some insurers recognise premium at cover note — make it a product flag `recognise_at: 'cover_note'|'policy'`).
4. **Issue policy**: when premium received or credit approved (flag `allow_credit_issue` by producer/customer type) → `POLICY_ISSUED` with gross/net/duties from rating_result. Cover note superseded. Documents generated.
5. **Endorsement**: change risk inputs → re-rate with the *original plan version* unless flag `endorsement_uses_current_tariff` → delta premium → `POLICY_ENDORSED` (delta) → endorsement document.

**INVARIANT** A policy's `rating_result` is frozen at issue; re-rating creates an endorsement, never mutates the original.

## 3. Documents
```
document_templates(id, t, code 'quotation'|'cover_note'|'policy_schedule'|'endorsement'|'receipt'|'renewal_notice'|'claim_ack'|'discharge_voucher',
                   product_class|null, version, engine 'blade_pdf', body (Blade/HTML), locale, letterhead_id, status)
generated_documents(id, t, template_id, template_version, object_type, object_id, number, pdf_document_id, rendered_at, hash)
```
- Engine: Blade → HTML → PDF via headless Chromium (browsershot) for Bangla font fidelity; **DECISION** not DOMPDF (weak Bangla shaping).
- Every generated PDF is stored immutably with hash; regeneration creates a new version, never overwrites.
- Templates are tenant-editable (customization tier: configuration), with a preview using demo data; variables documented.
- Bulk print/email/SMS from queues.

## 4. Renewals (G3)
- Nightly job builds the **expiry register**: policies expiring in 60/30/15/7 days by branch/producer.
- Renewal quotation auto-created at T-45 (config) by re-rating with the current tariff and prior-claims data (NCB); status `renewal_offered`.
- Notices: templates `renewal_notice` via email/SMS gateway (provider abstraction; SSL Wireless/Twilio adapters LATER; log-only adapter MVP), with reminders at configured offsets.
- Conversion: renewing creates a new policy with `previous_policy_id`; lapsed/non-renewed reasons captured for the retention report.
- Reports: expiry register, renewal conversion by branch/producer, lapse reasons.

## 5. Underwriting controls
- Authority limits: per user/role by class and sum insured (`underwriting_limits`); above limit → referral (same approval engine as claims).
- Sanctions/blacklist check hook (LATER), duplicate risk check (same vehicle reg / property address active elsewhere) MVP.
- Every manual rating override: reason, approver, shown on the policy audit and the schedule (as "special terms").

## 6. Screens (per ux-design-brief)
Quote workbench (risk form left, live premium breakdown right with EN/BN labels) · Referral queue · Cover notes queue (expiring) · Policy page gains Rating tab (breakdown + plan version) and Documents tab · Tariff editor (plan → tables grid with effective dates, draft/approve/activate, diff view) · Expiry register queue · Template editor with preview.

## 7. MVP vs LATER
MVP: classes motor, fire, marine cargo, misc; rating plans with tables/steps/duties; quote→proposal→cover note→policy; endorsement re-rating; documents (quotation, cover note, schedule, endorsement, receipt, renewal notice); expiry register + renewal quotes + log-only notifications; underwriting limits & referral; duplicate risk check.
LATER: life rating (age/term tables), health, fleet/group policies, co-insurance shares on the schedule, SMS/email gateway adapters, sanctions, tariff import from IDRA circulars.

## OPEN (configurable, conservative defaults)
1. Current IDRA/NBR duty values per class (VAT %, stamp duty amounts) → `duties` rows, seeded as placeholders flagged "verify".
2. Cover note maximum validity per class → default 30 days.
3. Whether premium is recognised at cover note → default 'policy'.
4. Credit issuance rules → default not allowed.
5. NCB scale for motor → seeded 0/10/20/30 flagged "verify".
