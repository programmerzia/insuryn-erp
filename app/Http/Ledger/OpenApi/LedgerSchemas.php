<?php

declare(strict_types=1);

namespace App\Http\Ledger\OpenApi;

/** JSON schemas referenced by ledger OpenAPI operations. */
final class LedgerSchemas
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'Error' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string'], 'reason' => ['type' => 'string']]],
            'TokenRequest' => ['type' => 'object', 'required' => ['email', 'password', 'device_name'], 'properties' => [
                'email' => ['type' => 'string'], 'password' => ['type' => 'string'], 'device_name' => ['type' => 'string'],
                'abilities' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
            'TokenResponse' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => [
                'token' => ['type' => 'string'], 'abilities' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]]]],
            'EventBody' => ['type' => 'object', 'required' => ['event_type', 'idempotency_key', 'transaction_date', 'currency', 'payload', 'dimensions', 'source'],
                'properties' => [
                    'event_type' => ['type' => 'string'], 'idempotency_key' => ['type' => 'string'], 'transaction_date' => ['type' => 'string', 'format' => 'date'],
                    'effective_date' => ['type' => 'string', 'format' => 'date'], 'currency' => ['type' => 'string'], 'payload' => ['type' => 'object'],
                    'dimensions' => ['type' => 'object'], 'source' => ['type' => 'object'],
                ]],
            'EventTypes' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object']]]],
            'EventDetail' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]],
            'EventWrite' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]],
            'JournalDetail' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]],
            'AccountBalance' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]],
            'TrialBalance' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]],
            'EventPreview' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]],
            'EventReplay' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]],
            'ProfitAndLoss' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]],
            'BalanceSheet' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]],
        ];
    }
}
