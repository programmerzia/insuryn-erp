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
            'Error' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string'], 'reason' => ['type' => 'string'],
                'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']], 'description' => 'field => messages on a 422'],
                'event_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'on a 500 UNEXPECTED from a synchronous post'],
            ]],
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
            'EventWrite' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => [
                'intake_id' => ['type' => 'string', 'format' => 'uuid'], 'event_id' => ['type' => 'string', 'format' => 'uuid'], 'event_type' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['queued', 'posting', 'posted', 'failed']], 'idempotency_key' => ['type' => 'string'],
                'created' => ['type' => 'boolean', 'description' => 'true when this call created the event; false when the idempotency_key was already known'],
                'resubmitted' => ['type' => 'boolean', 'description' => 'true when the key belonged to a failed event and this body replaced it and queued it again'],
                'failure_reason' => ['type' => 'string', 'nullable' => true],
                'journals' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'], 'number' => ['type' => 'string'], 'status' => ['type' => 'string'], 'posting_date' => ['type' => 'string', 'format' => 'date'],
                ]], 'description' => 'present after a synchronous post (?sync=1)'],
            ]]]],
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
