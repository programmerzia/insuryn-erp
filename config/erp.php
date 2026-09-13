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
    'posting' => [
        'rules_path' => resource_path('posting-rules'),
        'transient_retry_attempts' => 5,
        // Roles an event may point at a specific account through payload.account_overrides (design §4.2: a receipt's bank account).
        'overridable_roles' => ['bank_main'],
    ],
    'commission' => [
        // ASSUMPTION: A-7 — whose commission plan applies when both the product version and the agent name one is not specified.
        'plan_precedence' => ['product_version', 'agent'],
    ],
    'products' => [
        // ASSUMPTION: A-17 — products carry an insurance class (life | non_life) that producer licences must cover; a product whose line of
        // business is listed here is life unless its class is given explicitly, every other product is non-life.
        'life_lobs' => ['life'],
    ],
    'distribution' => [
        // ASSUMPTION: A-14 — which producer types need a licence to write new business is not specified: all of them.
        'licence_required_types' => ['agent', 'agency_org', 'bdo', 'broker', 'partner'],
        // Distribution design note §3: licence-expiry alerts this many days before expiry.
        'licence_alert_days' => [60, 30, 7],
        // ASSUMPTION: A-22 — payout route: producers with an employee record are paid through payroll; others by type (default accounts payable).
        'payout_route_by_type' => ['agent' => 'ap', 'agency_org' => 'ap', 'broker' => 'ap', 'partner' => 'ap', 'bdo' => 'payroll'],
        // ASSUMPTION: A-16 — IDRA's register file format is not specified: CSV with these columns, in this order.
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
    'numbering' => ['reservation_ttl_minutes' => 15],
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
];
