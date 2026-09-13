# Questions for the customer — before Phase 1 go-live

These are decisions only you can make. Until you answer, the system uses the most cautious choice listed
under "What the system does today". Every such choice is a setting, so an answer changes configuration,
not code (unless the question says otherwise).

Please answer in the "Your answer" line, or tell us which option you pick. Items are grouped by who at your
side is most likely to know the answer.

---

## A. Business model and regulation (management, compliance)

### Q1. Are you the insurer (carrier), a broker, or an MGA?
- **Why we ask:** it decides whose money premium is, which accounts premiums and claims post to, and whether
  claims are paid from your bank or passed to an insurer. (Design OPEN #1.)
- **What the system does today:** books as a carrier: premium is your revenue, claims are your expense.
- **Your answer:**

### Q2. Which regulator reports do you file, and in what format?
- **Why we ask:** needed to plan the regulatory reporting pack (IDRA returns or others). Not needed for
  go-live; it is Phase 3 input. (Design OPEN #5.)
- **What the system does today:** produces trial balance, P&L, balance sheet and the operating reports; no
  regulator-specific formats.
- **What we need:** a copy of each return template you file, with how often you file it.
- **Your answer:**

---

## B. Tax (finance, tax adviser)

### Q3. Which taxes apply to premium, and are they refunded when a policy is cancelled?
- **Why we ask:** VAT, stamp duty and levies differ by product and jurisdiction. (Design OPEN #2.)
- **What the system does today:** each product version has a tax rate and says whether the premium includes it.
  When a policy is cancelled, the VAT on the returned premium **is refunded** to the customer. (Assumption A-1.)
- **What we need:** for each product, the taxes on premium, their rates, whether premium is quoted with tax
  included, and whether tax is refunded on cancellation.
- **Your answer:**

### Q4. Is withholding tax deducted from agent commission, and at what rate?
- **Why we ask:** the system withholds tax from commission only when a rate is set; it never assumes zero.
- **What the system does today:** a commission plan can name a withholding tax; if the rate is missing the
  commission is refused rather than paid without tax.
- **Your answer:**

---

## C. Approvals and roles (finance manager, CFO, claims manager)

### Q5. What are your approval limits, and who approves above them?
- **Why we ask:** the system routes items above a limit to more senior approvers. (Design OPEN #3.)
- **What the system does today:** no limits are set. Every manual journal, reversal, refund, claim payment and
  commission payout still needs a second person (the person who prepares it can never approve it). Nothing
  goes to a senior approver automatically. (Assumption A-2.)
- **What we need:** a table like the one below, for each item.

  | Item | Up to (BDT) | Approved by | Above that | Approved by (in order) |
  |---|---|---|---|---|
  | Claim payment | e.g. 500,000 | Claims Manager | > 500,000 | Finance Manager, then CFO |
  | Claim payment release | | | | |
  | Claim reopen | | | | |
  | Manual journal | | | | |
  | Journal reversal | | | | |
  | Reopening a closed month | | | | |
  | Premium refund | | | | |
  | Commission payout | | | | |

  Limits on claim payments, claim payment release, claim reopen, manual journals, reversals and reopening a month
  are settings. Limits on premium refunds and commission payouts need a small addition, which we would plan once we
  have your answer.
- **Your answer:**

### Q6. Who locks the month, and must the CFO approve the lock?
- **Why we ask:** the design names the Finance Manager as owner with CFO approval.
- **What the system does today:** anyone holding the "lock period" permission can lock once every close task is
  done and every reconciliation balances. There is no separate CFO approval step.
- **Your answer:**

### Q7. How do our role templates map to your staff?
- **Why we ask:** the system ships these roles: Branch Officer, Branch Manager, Claims Officer, Claims Manager,
  Accountant, Finance Manager, CFO, Auditor, Tenant Admin. There is no separate Cashier role; tell us if you need one. Each carries a set of permissions.
- **What we need:** for each of your job titles, the role(s) they should hold, and any person who must hold two
  roles (the system blocks one person preparing and approving the same item even then).
- **Your answer:**

---

## D. Products and premium (underwriting, actuarial)

### Q8. How is premium earned for each product, and do you use a short-rate table on cancellation?
- **Why we ask:** earning method changes monthly revenue; short-rate changes refunds. (Design OPEN #4.)
- **What the system does today:** each product earns by day (365ths) or by month. 24ths and short-rate tables are
  refused, so every cancellation is refunded **pro-rata**. (Assumption A-4.)
- **What we need:** earning method per product; your short-rate table if you use one.
- **Your answer:**

### Q9. When several people pay one policy, who gets the refund on cancellation?
- **Why we ask:** a policy can be split between payers (for example an employer 70%, employee 30%).
- **What the system does today:** installments and cancellation credits are split by share, but a **refund goes
  to the policyholder**.
- **Options:** (a) policyholder; (b) each payer by share; (c) each payer by what they actually paid.
- **Your answer:**

---

## E. Collections (collections manager, treasury)

### Q10. What is your reminder and lapse schedule for unpaid installments?
- **What the system does today:** reminders at **7 and 21 days** overdue; an active policy lapses automatically
  after **30 days** unpaid (counted again from reinstatement). Reminders are recorded but not yet sent by SMS or
  email. (Assumption A-10.)
- **What we need:** reminder days, grace period, whether lapse is automatic, and which channel (SMS, email, letter).
- **Your answer:**

### Q11. What format do your banks' statement files use?
- **What the system does today:** CSV with a header row, dates as `2026-09-30`, one signed amount column in taka
  (money in positive). Column names are configurable. (Assumption A-5.)
- **What we need:** one sample statement file per bank (numbers may be blanked out).
- **Your answer:**

### Q12. What happens when a cheque bounces after the policy was cancelled?
- **What the system does today:** the bounce is refused and must be handled by hand, because the cancellation
  refund or credit already assumed the money arrived.
- **Your answer:**

---

## F. Commission (sales operations, finance)

### Q13. How are commission rates set?
- **Why we ask:** the product spec mentions tiers, first-year versus renewal rates, and overrides up an agent
  hierarchy.
- **What the system does today:** one flat rate per plan, earned when premium is received, clawed back on
  cancellation or bounced cheque. No tiers, no year rules, no overrides. When both the product and the agent name
  a plan, the **product's plan wins**. (Assumptions A-6, A-7.)
- **What we need:** your commission schedule, and whether managers earn an override on their agents' business.
  Tiers, year rules and overrides are new work, not a setting.
- **Your answer:**

### Q14. How do you pay commission?
- **What the system does today:** a statement per agent is approved by one person and paid by another, straight
  from a bank account; tax withheld stays payable to the tax authority. There is no limit-based approval.
- **Options:** (a) as today; (b) through payroll (Phase 2); (c) through accounts payable (Phase 2).
- **Your answer:**

---

## G. Claims (claims manager)

### Q15. When may a closed claim be reopened, and who decides?
- **What the system does today:** anyone with claim approval rights may reopen; a limit can route it to a more
  senior approver once you answer Q5.
- **Your answer:**

---

## H. Go-live data (finance, IT)

### Q16. Where do opening balances and the chart of accounts come from?
- **Why we ask:** decides the import format and the cut-over date. (Design OPEN #6.)
- **What the system does today:** imports a CSV with a header row, amounts in taka with a dot decimal. Column
  names are configurable. The import never creates an unbalanced ledger. (Assumption A-3.)
- **What we need:** the current system's name, an export of the chart of accounts and a trial balance, open
  policies, unpaid installments, open claims with reserves, and the planned cut-over month.
- **Your answer:**

### Q17. Do you need facultative reinsurance at go-live?
- **Why we ask:** the spec says Phase 1 has no reinsurance "or facultative only if the customer needs it".
- **What the system does today:** no reinsurance.
- **Your answer:**
