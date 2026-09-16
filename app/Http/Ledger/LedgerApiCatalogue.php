<?php

declare(strict_types=1);

namespace App\Http\Ledger;

/** Human-readable Ledger API v1 catalogue for the demo page and docs. */
final class LedgerApiCatalogue
{
    /** @return list<array{method: string, path: string, summary: string, auth: string, notes?: string}> */
    public static function endpoints(): array
    {
        return [
            ['method' => 'POST', 'path' => '/api/v1/tokens', 'summary' => 'Obtain a Bearer token', 'auth' => 'Public (email + password)', 'notes' => 'Integration user only (kind=integration). Header X-Tenant: tenant slug or id.'],
            ['method' => 'DELETE', 'path' => '/api/v1/tokens/current', 'summary' => 'Revoke the current token', 'auth' => 'Bearer'],
            ['method' => 'GET', 'path' => '/api/v1/event-types', 'summary' => 'List posting event types', 'auth' => 'Bearer read'],
            ['method' => 'POST', 'path' => '/api/v1/events/preview', 'summary' => 'Preview journal lines without posting', 'auth' => 'Bearer read'],
            ['method' => 'POST', 'path' => '/api/v1/events?sync=1', 'summary' => 'Submit an accounting event (sync post)', 'auth' => 'Bearer write', 'notes' => 'Omit ?sync=1 to queue. Idempotency-Key in body. Amounts in minor units.'],
            ['method' => 'GET', 'path' => '/api/v1/events/{id}', 'summary' => 'Event status and linked journals', 'auth' => 'Bearer read'],
            ['method' => 'POST', 'path' => '/api/v1/events/{id}/replay', 'summary' => 'Replay lines against current rules', 'auth' => 'Bearer read'],
            ['method' => 'GET', 'path' => '/api/v1/journals/{id}', 'summary' => 'Journal detail with lines', 'auth' => 'Bearer read'],
            ['method' => 'GET', 'path' => '/api/v1/balances?account=&as_of=', 'summary' => 'Single account balance', 'auth' => 'Bearer read'],
            ['method' => 'GET', 'path' => '/api/v1/trial-balance?as_of=', 'summary' => 'Trial balance', 'auth' => 'Bearer read'],
            ['method' => 'GET', 'path' => '/api/v1/reports/profit-and-loss?from=&to=', 'summary' => 'Profit and loss', 'auth' => 'Bearer read'],
            ['method' => 'GET', 'path' => '/api/v1/reports/balance-sheet?as_of=', 'summary' => 'Balance sheet', 'auth' => 'Bearer read'],
        ];
    }

    /** @return list<string> */
    public static function implementationSteps(): array
    {
        return [
            'Create an integration user (Admin → Ledger API demo, or your provisioning API).',
            'POST /api/v1/tokens with email, password, device_name; store the Bearer token securely.',
            'Send X-Tenant on every call (demo tenant slug: nonlife).',
            'POST /api/v1/events/preview with your payload; fix any rule or dimension errors.',
            'POST /api/v1/events?sync=1 with the same idempotency_key; store returned journal id.',
            'Reconcile with GET /api/v1/trial-balance and GET /api/v1/reports/*.',
        ];
    }
}
