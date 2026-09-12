<?php

declare(strict_types=1);

return [
    'posting' => [
        'rules_path' => resource_path('posting-rules'),
        'transient_retry_attempts' => 5,
    ],
    'numbering' => ['reservation_ttl_minutes' => 15],
    'close' => ['suspense_max_age_days' => 30],
];
