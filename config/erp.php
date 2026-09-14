<?php

declare(strict_types=1);

return [
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
        'default_timezone' => env('ERP_BUSINESS_TIMEZONE', 'Asia/Dhaka'),
    ],
    'posting' => [
        'rules_path' => resource_path('posting-rules'),
        'transient_retry_attempts' => 5,
        // Roles an event may point at a specific account through payload.account_overrides (design §4.2: a receipt's bank account).
        'overridable_roles' => ['bank_main'],
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
        ],
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
];
