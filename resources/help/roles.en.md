# What each journal line means

One plain line per account role, shown under each line in *View accounting* and in the confirmation before money moves. The first column is the
account role (do not translate it); the others say what a debit or a credit to that account means.

| Role | Debit | Credit |
|---|---|---|
| bank_main | Money came into the bank | Money went out of the bank |
| bank_clearing | Money is on its way to the bank through a payment gateway | Money left the clearing account for the bank |
| cheques_in_clearing | A cheque was received and the bank has not credited it yet | The cheque cleared into the bank, or bounced |
| bank_charges | The bank deducted a charge | Bank charge reduced |
| suspense_receipts | Money in suspense was matched to a policy | Money arrived that is not yet matched to a policy |
| premium_receivable | Customer owes us the premium | Customer paid, so owes us less |
| unearned_premium | Cover has been provided, so this premium is no longer owed to the customer | Cover not yet provided — a liability until time passes |
| premium_income | Premium income reduced | Premium earned as time passed |
| premium_tax_payable | VAT no longer owed to the government | VAT we collect for the government |
| stamp_duty_payable | Stamp duty no longer owed to the government | Stamp duty on the policy, owed to the government |
| customer_refund_payable | Refund paid to the customer | We owe the customer a refund |
| agent_receivable | An agent holds customer cash not yet deposited | The agent deposited the cash they collected |
| commission_expense | Commission cost of selling the policy | Commission cost reduced |
| commission_payable | Commission paid or recovered | We owe the agent commission |
| commission_withholding_payable | Tax withheld paid to the government | Tax withheld from commission, owed to the government |
| producer_advances | Advance paid to a producer | Advance recovered from the producer's commission |
| accounts_payable | Payable settled | We owe a supplier or producer |
| ap_expense | Cost on a supplier bill | Supplier bill cost reduced |
| input_vat_receivable | VAT on a supplier bill we can reclaim | Reclaimable VAT reversed or used |
| vat_deducted_at_source_payable | VAT deducted at source paid to the government | VAT deducted at source from a supplier, owed to the government |
| supplier_tax_withheld_payable | Supplier tax withheld paid to the government | Income tax deducted at source from a supplier, owed to the government |
| dac_asset | Selling cost kept for later months | Kept selling cost charged to this month |
| claims_outstanding | Reserve released or moved to payable | Money we expect to pay on a reported claim |
| claims_expense | Cost of claims | Claims cost reduced as the reserve is released |
| claims_payable | Claim payment made | Approved claim we must now pay |
| claims_ibnr_expense | Estimated cost of claims that happened but are not reported yet | Last quarter's IBNR estimate released |
| ibnr_provision | Last quarter's IBNR estimate released | Claims we expect for losses that happened but are not reported yet |
| claims_recovery_income | Recovery income reduced | Money recovered from salvage or a third party |
| recovery_receivable | Recovery we expect to receive | Recovery received |
| rounding_difference | Rounding difference | Rounding difference |
| fx_gain_loss | Loss from exchange rates | Gain from exchange rates |
| salary_expense | Salary cost | Salary cost reduced |
| employer_pf_expense | Employer's provident fund cost | Employer's provident fund cost reduced |
| bonus_expense | Festival bonus cost | Festival bonus cost reduced |
| salary_payable | Salary paid | Salary we owe staff |
| employee_tax_payable | Employee tax paid to the government | Tax withheld from salaries, owed to the government |
| pf_payable | Provident fund paid over | Provident fund we owe |
| retained_earnings | Retained earnings reduced | Profit kept in the company, or a balance brought forward |
| premium_written_off | Premium the customer still owed, written off as too small to collect | Written-off premium reduced |
| ri_premium_ceded | Premium passed to a reinsurer for its share of the risk | Ceded premium reduced: a cancellation, or its unearned share |
| ri_payable | Owed to a reinsurer reduced: its commission, or a payment | Premium owed to a reinsurer for the risk it took |
| ri_commission_income | Reinsurance commission given back (a cancellation) | Commission a reinsurer allows us on the premium ceded to it |
| ri_unearned_premium | The reinsurers' share of premium for cover not yet given | That share earned as time on cover passes |
| ri_outstanding_claims | A reinsurer's share of an open claim reserve | That share reduced: the reserve fell, or a payment became recoverable |
| ri_claims_recoverable | A reinsurer owes us its share of a claim we paid | A reinsurer paid its share, or the share was reduced |
| fixed_asset_cost | An asset the company bought, at its cost | The asset left the books: sold, scrapped or moved |
| accumulated_depreciation | Depreciation taken off an asset that left the books | Part of the asset's cost used up so far |
| depreciation_expense | This month's cost of using the company's assets | Depreciation cost reduced |
| asset_disposal_gain_loss | Loss on selling or scrapping an asset | Gain on selling an asset for more than its book value |
| petty_cash | Cash put into a branch petty cash float | Cash paid out of the petty cash float |
| petty_cash_expense | A small expense paid from petty cash | Petty cash expense reduced |
| petty_cash_over_short | Cash found short on counting the float | Cash found over on counting the float |

## When one event changes what a line means

The event type and account role come first (do not translate them); the caption replaces the one above for that event only.

| Event | Role | Debit | Credit |
|---|---|---|---|
| POLICY_CANCELLED | unearned_premium | Premium for cover not given, taken off the bill or returned to the customer | Cover not yet provided — a liability until time passes |
