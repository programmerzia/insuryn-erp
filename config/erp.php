<?php

declare(strict_types=1);

return [
    // Demo and new tenants default to Kenyan shilling (customer demo); override with ERP_DEFAULT_CURRENCY.
    'default_currency' => env('ERP_DEFAULT_CURRENCY', 'KES'),
    'seed' => [
        // Password of the per-tenant admin created by AdminUserSeeder (local and single-install use). Change it after first sign-in.
        'admin_password' => env('ERP_ADMIN_PASSWORD', 'ChangeMe123!'),
    ],
    'tenancy' => [
        // Tenant used when no header, session or subdomain names one (local browsing, single-install deployments). Empty = none.
        'default_slug' => env('ERP_DEFAULT_TENANT', 'demo'),
    ],
    'business_clock' => [
        // Slice 2.1b (D-54, CQ-H2): business dates follow the legal entity's time zone (legal_entities.timezone). This zone is the default for a new
        // entity and the zone used outside a tenant; the nightly jobs are scheduled on it. ASSUMPTION A-151, A-152.
        'default_timezone' => env('ERP_BUSINESS_TIMEZONE', 'Africa/Nairobi'),
    ],
    'posting' => [
        'rules_path' => resource_path('posting-rules'),
        'transient_retry_attempts' => 5,
        // Roles an event may point at a specific account through payload.account_overrides (design §4.2: a receipt's bank account).
        // D-100 (addendum v2 §B.2.1 PD-4): ap_expense lines take the account of each supplier bill line.
        'overridable_roles' => ['bank_main', 'ap_expense', 'fixed_asset_cost', 'accumulated_depreciation', 'depreciation_expense', 'asset_disposal_gain_loss', 'petty_cash', 'petty_cash_expense'], // + design addendum v2 PD-4: fixed assets post to their class's accounts
        // ASSUMPTION A-173 (gap fix GA-08): a queued event not posted after this many minutes is listed as stuck on Accounting events (the worker was down).
        'stale_after_minutes' => (int) env('ERP_POSTING_STALE_AFTER_MINUTES', 15),
    ],
    'outbox' => [
        // ASSUMPTION: A-186 (gap audit GA-46) — outbox messages with no subscriber in this build; the relay marks them relayed. Messages waiting for a
        // Phase 2 consumer (CommissionPayableToAp, CommissionPayrollEarning, DunningNoticeDue, ProducerLicenceExpiring) are not listed and stay unrelayed.
        'no_subscriber_types' => ['JournalPosted', 'PeriodLocked', 'PeriodReopened', 'PeriodSoftLocked'],
    ],
    'commission' => [
        // ASSUMPTION: A-7 — whose commission plan applies when both the product version and the agent name one is not specified.
        'plan_precedence' => ['product_version', 'agent'],
        // ASSUMPTION: A-181 (gap audit GA-42, D-70) — commission on premium received is a percentage of the NET premium in each allocation, without
        // VAT and stamp duty (Bangladesh practice; IDRA commission caps are on net premium): `net_premium`. `gross` pays on the cash allocated.
        'premium_received_base' => env('ERP_COMMISSION_PREMIUM_RECEIVED_BASE', 'net_premium'),
    ],
    'products' => [
        // ASSUMPTION: A-17 — products carry an insurance class (life | non_life) that producer licences must cover; a product whose line of
        // business is listed here is life unless its class is given explicitly, every other product is non-life.
        'life_lobs' => ['life'],
    ],
    'approvals' => [
        // Fix F3: object types the approval engine requests (ApprovalService::request), with the name on the Approval limits screen and the
        // approval duty a step records for segregation of duties. Refunds and commission payouts do not go through the engine (maker-checker only).
        'object_types' => [
            'claim_payment' => ['label' => 'Claim payment approval', 'permission' => 'claim.approve'],
            'claim_payment_release' => ['label' => 'Claim payment release', 'permission' => 'claim.pay_release'],
            'journal' => ['label' => 'Manual journal', 'permission' => 'accounting.approve_journal'],
            'journal_reversal' => ['label' => 'Journal reversal', 'permission' => 'accounting.approve_journal'],
            'claim_reopen' => ['label' => 'Claim reopening', 'permission' => 'claim.approve'],
            'fiscal_period_reopen' => ['label' => 'Period reopening', 'permission' => 'periods.reopen'],
            // Slice R5: underwriting referrals by sum insured. Without a policy, the referral goes to the role whose underwriting limit covers it (D-31).
            'proposal_referral' => ['label' => 'Underwriting referral', 'permission' => 'underwriting.decide'],
            // Gap fixes W7 (GA-24): writing off a cancelled policy's small unpaid premium. Without a policy, any other holder of receipt.write_off_approve (A-233).
            'premium_write_off' => ['label' => 'Premium write-off', 'permission' => 'receipt.write_off_approve'],
            // Slices 2.3/2.4 accounts payable: an approval limit adds approvers above an amount; without one, one checker other than the maker decides.
            'ap_bill' => ['label' => 'Supplier bill', 'permission' => 'ap.approve_bills'],
            'payment_run' => ['label' => 'Supplier payment run', 'permission' => 'ap.approve_payments'],
        ],
    ],
    // Gap fixes W7 (GA-24): "Write off small balance" on a cancelled policy.
    'premium_write_off' => [
        // ASSUMPTION: A-231 — the largest unpaid premium (minor units, the policy's currency) a cancelled policy may have written off instead of collected: 1,000.00.
        'max_minor' => (int) env('ERP_PREMIUM_WRITE_OFF_MAX_MINOR', 100_000),
    ],
    // Phase 3 slice R4 quotations. ASSUMPTION: A-80 — "valid 15 days": the issue day and the 14 days after it; the quotation expires the day after.
    'quotations' => [
        'valid_days' => (int) env('ERP_QUOTATION_VALID_DAYS', 15),
    ],
    // Phase 3 slice R7: policies issued from approved proposals, endorsement re-rating.
    'policies' => [
        // ASSUMPTION: A-116 — a policy is issued only while its quotation's premium is still guaranteed (the issue date is on or before the quotation's
        // valid_until); after that the customer is quoted again. false lets an approved proposal issue at any time on its frozen premium.
        'issue_within_quotation_validity' => (bool) env('ERP_POLICY_ISSUE_WITHIN_QUOTATION_VALIDITY', true),
        // ASSUMPTION: A-119 — how an endorsement's re-rated premium change is charged is not specified and no endorsement pro-rata convention exists:
        // 'full' charges the whole annual difference (new rating − rating in force); 'pro_rata' charges net premium and VAT/levies for the days left
        // (effective date to expiry, both included, over the policy's days), stamp duty in full.
        'endorsement_premium' => env('ERP_ENDORSEMENT_PREMIUM', 'full'),
    ],
    // Phase 3 slice R9 renewals (design §4).
    'renewals' => [
        // ASSUMPTION: A-125 — the expiry register lists issued and active policies expiring within the largest bucket; a policy is in the smallest bucket
        // (days) not below its days left. Verify with the insurer.
        'buckets' => [60, 30, 15, 7],
        // ASSUMPTION: A-127 — the renewal quotation is offered this many days before expiry, valid until the expiry date (renew by).
        'quote_days_before' => (int) env('ERP_RENEWAL_QUOTE_DAYS_BEFORE', 45),
        // ASSUMPTION: A-131 — the renewal notice goes out with the renewal quotation; reminders at these days before expiry.
        'reminders' => [30, 15, 7],
        // ASSUMPTION: A-128 — the risk field holding claim-free years (no-claim bonus): one more after a period without claims, 0 after a claim.
        'ncb_field' => 'ncb_years',
        // ASSUMPTION: A-132 — why a policy was not renewed (people choose one; `other` needs a note). Labels in English and Bangla.
        'lapse_reasons' => [
            'price' => ['en' => 'Price', 'bn' => 'মূল্য'],
            'service' => ['en' => 'Service', 'bn' => 'সেবা'],
            'sold_asset' => ['en' => 'Sold the insured asset', 'bn' => 'বিমাকৃত সম্পদ বিক্রি'],
            'moved_to_competitor' => ['en' => 'Moved to a competitor', 'bn' => 'অন্য বিমাকারীর কাছে গেছেন'],
            'no_response' => ['en' => 'No response', 'bn' => 'সাড়া নেই'],
            'other' => ['en' => 'Other', 'bn' => 'অন্যান্য'],
        ],
    ],
    // Slice R9 (DECISION D-41): notification channels. Every channel uses the log-only adapter until a gateway adapter exists (SSL Wireless, Twilio: LATER).
    'notifications' => [
        'channels' => [
            'email' => ['enabled' => (bool) env('ERP_NOTIFY_EMAIL', true), 'adapter' => env('ERP_NOTIFY_EMAIL_ADAPTER', 'log')],
            'sms' => ['enabled' => (bool) env('ERP_NOTIFY_SMS', true), 'adapter' => env('ERP_NOTIFY_SMS_ADAPTER', 'log')],
        ],
    ],
    // Phase 3 slice R6 cover notes. ASSUMPTION: A-93 — design OPEN 2: the longest cover note per product class in days, both ends included (default 30, verify).
    'cover_notes' => [
        'max_days' => ['default' => 30, 'motor' => 30, 'fire' => 30, 'marine_cargo' => 30, 'misc' => 30],
        // The cover notes queue's "ending soon" filter.
        'expiring_within_days' => 7,
    ],
    'underwriting' => [
        // ASSUMPTION: A-88 — the risk fields that identify a risk for the duplicate check (design §5), per product class. Verify with underwriting.
        'duplicate_keys' => [
            'motor' => ['registration_no', 'chassis_no'],
            'fire' => ['address'],
        ],
        // ASSUMPTION: A-89 — risk flags that refer a proposal to an underwriter (design §2 "risk flags"), per class. Rules: above / below a number,
        // age_above (years from a year field to the day of submission), in (a list of option values). Conservative placeholders: verify with underwriting.
        'risk_flags' => [
            'motor' => [['field' => 'year_of_manufacture', 'rule' => 'age_above', 'value' => 15, 'label' => 'Vehicle older than 15 years']],
            'fire' => [['field' => 'construction_class', 'rule' => 'in', 'value' => ['class_3'], 'label' => 'Construction class 3 (tin or wood)']],
        ],
        // ASSUMPTION: A-92 — identity documents accepted for KYC on a proposal.
        'kyc_id_types' => ['nid', 'passport', 'birth_certificate', 'trade_licence', 'tin'],
    ],
    'setup' => [
        // Session S1 setup wizard: the jurisdiction a first product's premium tax (VAT) is recorded under, and the template offered first.
        'tax_jurisdiction' => env('ERP_TAX_JURISDICTION', 'BD'),
        'chart_of_accounts_template' => 'non-life-insurance',
        // ASSUMPTION: A-182 (gap audit GA-44, D-71) — the first product's earning method: 1/365 per day on cover (`daily_365`), the common method in
        // Bangladesh non-life; `monthly` (each calendar month's share) stays available here and per product version.
        'earning_method' => env('ERP_SETUP_EARNING_METHOD', 'daily_365'),
        // Gap fix GA-18. ASSUMPTION: A-211 — the month a new tenant's fiscal year starts in by default is not specified: January (1). Bangladesh non-life
        // insurers keep calendar-year accounts (IDRA returns and the Insurance Act's annual accounts run January–December); July (7, the government's
        // fiscal year) is offered in the hint. Verify with the customer.
        'fiscal_year_first_month' => (int) env('ERP_FISCAL_YEAR_FIRST_MONTH', 1),
    ],
    'ui' => [
        // UX U1 date picker. ASSUMPTION: A-165 — the calendar week starts on Sunday: Bangladesh's working week runs Sunday to Thursday and CLDR
        // gives Sunday for bn-BD. Not confirmed with the customer (some offices print Saturday-first calendars); one of sunday … saturday.
        'week_starts_on' => env('ERP_WEEK_STARTS_ON', 'sunday'),
    ],
    'distribution' => [
        // ASSUMPTION: A-14 — which producer types need a licence to write new business is not specified: all of them.
        'licence_required_types' => ['agent', 'agency_org', 'bdo', 'broker', 'partner'],
        // Distribution design note §3: licence-expiry alerts this many days before expiry.
        'licence_alert_days' => [60, 30, 7],
        // ASSUMPTION: A-22 — payout route: producers with an employee record are paid through payroll; others by type (default accounts payable).
        'payout_route_by_type' => ['agent' => 'ap', 'agency_org' => 'ap', 'broker' => 'ap', 'partner' => 'ap', 'bdo' => 'payroll'],
        // ASSUMPTION: A-191 (GA-10) — commission earned only under Phase 1 commission plans (no compensation scheme) is paid from the bank, as the Phase 1 payout
        // did, now that the monthly statement run is the only payout path (bank | ap | payroll; a producer on payroll is still paid through payroll).
        'plan_payout_route' => env('ERP_PLAN_PAYOUT_ROUTE', 'bank'),
        // ASSUMPTION: A-16 — IDRA's register file format is not specified: CSV with these columns, in this order.
        // Flow fix X9: the prefix of the code suggested for a producer created inline from a quote (PREFIX-001, the next free number); the code can be changed.
        'producer_code_prefixes' => ['agent' => 'AG', 'agency_org' => 'AGY', 'bdo' => 'BDO', 'broker' => 'BRK', 'partner' => 'PTR'],
        'idra_register_columns' => ['licence_no', 'authority', 'producer_code', 'producer_name', 'producer_type', 'class', 'issued_on', 'expires_on', 'status', 'branch_code'],
    ],
    'parties' => [
        // ASSUMPTION: A-193 (GA-17) — where an individual's mobile number is required: the Parties form (new and edit) yes; the quote's inline
        // "New customer" drawer no, so a walk-in can be quoted before giving it (the drawer asks for it and the customer page flags it as missing).
        'mobile_required' => ['party_form' => (bool) env('ERP_PARTY_MOBILE_REQUIRED', true), 'quote_drawer' => (bool) env('ERP_QUOTE_CUSTOMER_MOBILE_REQUIRED', false)],
    ],
    'collections' => [
        // ASSUMPTION: A-10 — dunning schedule and grace period are not specified (spec §4 names the feature only).
        'dunning_notice_days' => [7, 21],
        'grace_days' => 30,
        'auto_lapse' => true,
    ],
    'bank' => [
        // Auto-match (slice 1A.6): same amount, the journal's reference or receipt number in the statement text, and the
        // statement date within this many days of the posting date. Anything ambiguous is left for manual matching.
        'auto_match_date_window_days' => 3,
    ],
    'numbering' => [
        'reservation_ttl_minutes' => 15,
        // Fix F1: number format per document type ({prefix}, {branch} code, {fy}, {seq}). New numbers only: issued numbers are never rewritten.
        // Fix G5 (D-53): a type not listed uses {prefix}-{branch}-{fy}-{seq}; {branch} drops out for entity-level sequences (commission statements).
        'formats' => ['policy' => env('ERP_POLICY_NUMBER_FORMAT', '{prefix}-{branch}-{fy}-{seq}'),
            // Phase 3 R4–R6: quotations, proposals and cover notes are branch-coded like policies (QUO-HO-2026-000001).
            'quotation' => env('ERP_QUOTATION_NUMBER_FORMAT', '{prefix}-{branch}-{fy}-{seq}'),
            'proposal' => env('ERP_PROPOSAL_NUMBER_FORMAT', '{prefix}-{branch}-{fy}-{seq}'),
            'cover_note' => env('ERP_COVER_NOTE_NUMBER_FORMAT', '{prefix}-{branch}-{fy}-{seq}'),
            /*
             * Fix G5: receipts, claims and agent deposits are numbered per branch and unique in the tenant, so they carry the branch code too
             * (RCT-HO-2026-000001); without it a second branch repeated the first branch's numbers and was refused.
             * ASSUMPTION: A-150 — the customer has not chosen the receipt, claim and deposit formats (CQ-E5); branch-coded until they do.
             */
            'receipt' => env('ERP_RECEIPT_NUMBER_FORMAT', '{prefix}-{branch}-{fy}-{seq}'),
            'claim' => env('ERP_CLAIM_NUMBER_FORMAT', '{prefix}-{branch}-{fy}-{seq}'),
            'agent_deposit' => env('ERP_AGENT_DEPOSIT_NUMBER_FORMAT', '{prefix}-{branch}-{fy}-{seq}'),
            // Entity-level sequence (no branch): the {branch} part is left out, so this reads CST-2026-000001.
            'commission_statement' => env('ERP_COMMISSION_STATEMENT_NUMBER_FORMAT', '{prefix}-{branch}-{fy}-{seq}')],
    ],
    'close' => ['suspense_max_age_days' => 30],
    // Reinsurance MVP (market gap G4).
    'reinsurance' => [
        // ASSUMPTION: A-251 — the statutory share of non-life business ceded to Sadharan Bima Corporation (SBC) is a placeholder: 50% (VERIFY with the insurer and IDRA/SBC
        // circulars). It is the default for a new treaty's SBC share and can be changed per treaty.
        'sbc_share_bp' => (int) env('ERP_RI_SBC_SHARE_BP', 5000),
        // ASSUMPTION: A-252 — the SBC share is applied first, to the gross sum insured and net premium; treaties apply to what is left.
        'sbc_basis' => 'gross_first',
        // ASSUMPTION: A-253 — a product version without a class maps to a treaty class by its line of business.
        'lob_classes' => ['motor' => 'motor', 'fire' => 'fire', 'marine' => 'marine_cargo', 'engineering' => 'engineering', 'misc' => 'misc', 'health' => 'health'],
        // ASSUMPTION: A-254 — the risk field that holds a policy's sum insured (the proposal's sum insured is used first).
        'sum_insured_field' => 'sum_insured',
    ],

    /*
     * ASSUMPTION: A-3 — design OPEN #6 — whether opening balances come from an existing system, and in which
     * format, is unknown. Imports accept CSV with a header row; these are the header names expected for
     * each field (rename them here to match a source system's export). Amounts are major units with a
     * dot decimal separator ("1234.56").
     */
    'imports' => [
        'max_rows' => 10_000,
        'chart_of_accounts' => [
            'code' => 'code', 'name' => 'name', 'type' => 'type', 'normal_side' => 'normal_side', 'parent_code' => 'parent_code',
            'is_postable' => 'is_postable', 'is_control' => 'is_control', 'control_subledger' => 'control_subledger',
            'currency' => 'currency', 'role' => 'role',
        ],
        // ASSUMPTION: A-5 (like A-3) — bank statement CSV: one signed amount column (positive = money in), dates as Y-m-d.
        'bank_statement' => [
            'posted_on' => 'date', 'description' => 'description', 'reference' => 'reference', 'amount' => 'amount',
        ],
        'bank_statement_date_format' => 'Y-m-d',
        'opening_balances' => [
            'account_code' => 'account_code', 'debit' => 'debit', 'credit' => 'credit', 'branch_code' => 'branch_code', 'memo' => 'memo',
        ],
    ],

    /*
     * Fix F2: documents attached to claims, receipts and policies (Platform\Documents\DocumentStore), kept on a private disk.
     * ASSUMPTION: A-52 — the upload limit and the accepted file types are not specified: 10 MB, PDF, JPEG/PNG images, Word and Excel.
     * PHP's upload_max_filesize and post_max_size must be at least as large.
     */
    'documents' => [
        'disk' => env('ERP_DOCUMENTS_DISK', 'documents'),
        'max_upload_kb' => (int) env('ERP_DOCUMENT_MAX_UPLOAD_KB', 10240),
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'],
        /*
         * Slice R8: generated documents. DECISION D-34 — PDFs are printed by the Chrome binary in headless mode (Platform\Documents\Rendering\ChromePdfRenderer).
         * ASSUMPTION: A-102 — a document is generated in English unless the user chooses Bangla.
         */
        'chrome_binary' => env('ERP_CHROME_BINARY', '/usr/bin/google-chrome'),
        'render_timeout_seconds' => (int) env('ERP_DOCUMENT_RENDER_TIMEOUT', 60),
        'default_locale' => env('ERP_DOCUMENT_LOCALE', 'en'),
    ],

    /*
     * Market gap G5: regulatory returns (IDRA) and technical provisions (IBNR). Every rate here is a PLACEHOLDER to verify with the customer's actuary and IDRA circulars.
     * ASSUMPTION: A-261 — IDRA's form numbers and wording are not known: forms are titled descriptively and their sections and columns are this configuration,
     * so a layout is changed here, not in code. A column key names a figure the form's builder computes; a key it does not know stays blank.
     */
    'regulatory' => [
        'regulator' => 'Insurance Development and Regulatory Authority (IDRA)',
        'forms' => [
            'premium_income' => ['title' => 'Premium income by class of business', 'sheet' => 'Premium income', 'sections' => [
                ['key' => 'by_class', 'title' => 'By class of business', 'columns' => ['group' => 'Class of business', 'policies' => 'Policies', 'gross_premium' => 'Gross premium (excluding VAT)',
                    'vat' => 'VAT collected (excluded)', 'stamp_duty' => 'Stamp duty', 'cancellations' => 'Cancellations (return premium)', 'net_premium' => 'Net premium income']],
                ['key' => 'by_branch', 'title' => 'By branch', 'columns' => ['group' => 'Branch', 'policies' => 'Policies', 'gross_premium' => 'Gross premium (excluding VAT)',
                    'vat' => 'VAT collected (excluded)', 'stamp_duty' => 'Stamp duty', 'cancellations' => 'Cancellations (return premium)', 'net_premium' => 'Net premium income']],
            ]],
            'claims' => ['title' => 'Claims intimated, paid and outstanding by class of business', 'sheet' => 'Claims', 'sections' => [
                ['key' => 'by_class', 'title' => 'By class of business', 'columns' => ['group' => 'Class of business', 'intimated_count' => 'Claims intimated (number)',
                    'intimated_amount' => 'Claims intimated (estimate)', 'paid_count' => 'Claims paid (number)', 'paid_amount' => 'Claims paid (amount)',
                    'outstanding_count' => 'Claims outstanding at period end (number)', 'outstanding_amount' => 'Claims outstanding at period end (amount)']],
            ]],
            'expenses' => ['title' => 'Commission and management expenses by class of business against the expense limit', 'sheet' => 'Expenses', 'sections' => [
                ['key' => 'by_class', 'title' => 'By class of business', 'columns' => ['group' => 'Class of business', 'gross_premium' => 'Gross premium (excluding VAT)',
                    'commission' => 'Commission', 'management' => 'Management expenses (allocated)', 'total' => 'Total expenses', 'ratio' => 'Expenses to gross premium',
                    'limit_rate' => 'Expense limit rate', 'limit' => 'Expense limit', 'excess' => 'Excess over limit']],
            ]],
            'agent_register' => ['title' => 'Agent and producer register summary', 'sheet' => 'Agent register', 'sections' => [
                ['key' => 'summary', 'title' => 'Licences by producer type at period end', 'columns' => ['group' => 'Producer type', 'valid' => 'Valid', 'expired' => 'Expired',
                    'suspended' => 'Suspended', 'revoked' => 'Revoked', 'not_yet_valid' => 'Not yet valid', 'total' => 'Total', 'issued_in_period' => 'Issued in the period']],
                ['key' => 'licences', 'title' => 'Licence register', 'columns' => ['licence_no' => 'Licence number', 'producer_code' => 'Producer code', 'producer_name' => 'Name',
                    'producer_type' => 'Type', 'class' => 'Class', 'branch_code' => 'Branch', 'issued_on' => 'Issued on', 'expires_on' => 'Expires on', 'status' => 'Status']],
            ]],
            'reinsurance_ceded' => ['title' => 'Reinsurance ceded summary', 'sheet' => 'Reinsurance ceded', 'sections' => [
                ['key' => 'by_class', 'title' => 'By class of business', 'columns' => ['group' => 'Class of business', 'cessions' => 'Cessions', 'ceded_premium' => 'Premium ceded',
                    'commission' => 'Reinsurance commission', 'recoveries' => 'Claims recovered']],
            ]],
        ],
        /*
         * ASSUMPTION: A-263 — the IDRA management expense limit as a percentage of gross premium per class (placeholders, verify against the current IDRA expense
         * rules). Management expenses are the expense accounts not mapped to the roles below, spread over classes by gross premium.
         */
        'expense_limit_bp' => ['fire' => 3500, 'marine' => 3500, 'motor' => 3500, 'engineering' => 3500, 'default' => 3500],
        'non_management_expense_roles' => ['claims_expense', 'claims_ibnr_expense', 'commission_expense', 'rounding_difference', 'fx_gain_loss'],
        'provisions' => [
            // ASSUMPTION: A-264 — IBNR as a percentage of net written premium (excluding VAT, after cancellations) over the four quarters to the quarter end (placeholders, verify).
            'ibnr_percentage_bp' => ['fire' => 300, 'marine' => 400, 'motor' => 500, 'default' => 500],
            'premium_base_quarters' => 4,
            // ASSUMPTION: A-265 — a paid chain ladder needs paid claims in at least this many accident quarters; otherwise the class falls back to the percentage.
            'chain_ladder_min_accident_quarters' => 4,
            'triangle_quarters' => 8,
            // ASSUMPTION: A-266 — premium deficiency check: unearned premium against its expected claims and maintenance expenses (placeholders, verify).
            'expected_loss_ratio_bp' => ['fire' => 4000, 'marine' => 5000, 'motor' => 6500, 'default' => 6000],
            'maintenance_expense_ratio_bp' => 1000,
            // ASSUMPTION: A-267 — the branch the provision is booked to (a head-office provision); the first branch by code when this code does not exist.
            'branch_code' => env('ERP_PROVISIONS_BRANCH_CODE', 'HO'),
        ],
        /*
         * ASSUMPTION: A-269 — solvency snapshot, a placeholder formula to verify: available capital = assets − liabilities; required = the greater of the minimum
         * paid-up capital for a non-life insurer, a share of net premium over the last four quarters and a share of net claims incurred over them.
         */
        'solvency' => ['minimum_capital_minor' => 40_00_00_000_00, 'premium_factor_bp' => 2000, 'claims_factor_bp' => 3000],
    ],

    /*
     * Slices 2.3/2.4 accounts payable (addendum v2 B.4).
     * ASSUMPTION A-243 (CQ-B3/CQ-B4 unanswered): VAT on supplier bills, VAT deducted at source (VDS) and income tax deducted at source (TDS/AIT) per
     * supplier category, in basis points of the bill line's net amount. PLACEHOLDER rates from the Bangladesh VAT and Supplementary Duty Act 2012 SRO
     * schedules and the Income Tax Act 2023 withholding sections as commonly applied; VERIFY every rate with the insurer's tax adviser before use.
     * VDS is never more than the line's VAT.
     * ASSUMPTION A-244: VAT on supplier bills is not recoverable (it is part of the expense) unless input_vat_recoverable is true.
     */
    'payables' => [
        'input_vat_recoverable' => (bool) env('ERP_AP_INPUT_VAT_RECOVERABLE', false),
        'default_payment_terms_days' => 30,
        'categories' => [
            'rent' => ['label' => 'Office rent (landlord)', 'vat_bp' => 1500, 'vds_bp' => 1500, 'tds_bp' => 500],
            'utility' => ['label' => 'Utilities (electricity, water, gas)', 'vat_bp' => 500, 'vds_bp' => 0, 'tds_bp' => 0],
            'telecom' => ['label' => 'Telephone and internet', 'vat_bp' => 1500, 'vds_bp' => 0, 'tds_bp' => 0],
            'repairs' => ['label' => 'Garage and repair services', 'vat_bp' => 1000, 'vds_bp' => 1000, 'tds_bp' => 500],
            'professional' => ['label' => 'Surveyors and professional services', 'vat_bp' => 1500, 'vds_bp' => 1500, 'tds_bp' => 1000],
            'supplies' => ['label' => 'Stationery and supplies', 'vat_bp' => 500, 'vds_bp' => 500, 'tds_bp' => 500],
            'other' => ['label' => 'Other suppliers', 'vat_bp' => 1500, 'vds_bp' => 1500, 'tds_bp' => 500],
        ],
        // ASSUMPTION A-245 (CQ-I1 unanswered): a generic BEFTN-style CSV until the bank names its upload format.
        'bank_file_format' => 'beftn_csv',
    ],

    // Design addendum v2 §B.6–B.8 finance modules: fixed assets, budgets, petty cash.
    'reports' => [
        // Registers served on their own screens, listed on the reports index with their exports.
        'screens' => [
            ['title' => 'Fixed asset register', 'description' => 'Cost, accumulated depreciation and net book value per asset at a date, by class and branch, reconciled to the ledger.',
                'href' => '/fixed-assets/register', 'export' => '/fixed-assets/register/export'],
            ['title' => 'Budget variance', 'description' => 'Actual against the approved budget for a month and the year to date, by account group and branch.',
                'href' => '/budgets/variance', 'export' => '/budgets/variance/export'],
            ['title' => 'Petty cash book', 'description' => 'Cash received and paid per petty cash float, with the running balance.',
                'href' => '/petty-cash/book', 'export' => '/petty-cash/book/export'],
        ],
    ],
    'fixed_assets' => [
        /*
         * ASSUMPTION: A-271 — default asset classes offered to a new register (Bangladesh practice, PLACEHOLDER rates: verify with the insurer's finance team and
         * auditors; the Third Schedule of the Income Tax Act gives tax rates, which differ from book depreciation). code => [name, method, life months | yearly rate %, residual %, threshold BDT].
         */
        'default_classes' => [
            'FURN' => ['Furniture and fixtures', 'straight_line', 120, 0, 10000],
            'IT' => ['Computers and IT equipment', 'straight_line', 36, 0, 10000],
            'VEH' => ['Motor vehicles', 'reducing_balance', 20, 0, 10000],
            'OFFEQ' => ['Office equipment', 'straight_line', 60, 0, 10000],
            'LHI' => ['Leasehold improvements', 'straight_line', 60, 0, 10000],
        ],
    ],
];
