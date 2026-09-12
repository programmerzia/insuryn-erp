<?php

declare(strict_types=1);

return [
    'posting' => [
        'rules_path' => resource_path('posting-rules'),
        'transient_retry_attempts' => 5,
    ],
    'numbering' => ['reservation_ttl_minutes' => 15],
    'close' => ['suspense_max_age_days' => 30],

    /*
     * ASSUMPTION: design OPEN #6 — whether opening balances come from an existing system, and in which
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
        'opening_balances' => [
            'account_code' => 'account_code', 'debit' => 'debit', 'credit' => 'credit', 'branch_code' => 'branch_code', 'memo' => 'memo',
        ],
    ],
];
