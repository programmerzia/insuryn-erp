<?php

declare(strict_types=1);

return [
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
