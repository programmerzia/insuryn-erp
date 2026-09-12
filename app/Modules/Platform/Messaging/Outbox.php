<?php

declare(strict_types=1);

namespace App\Modules\Platform\Messaging;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §8.2 transactional outbox: a message row committed in the same transaction as the change it
 * announces; relays deliver it after commit.
 */
final class Outbox
{
    /** @param array<string, mixed> $payload */
    public function add(string $messageType, array $payload): string
    {
        $id = (string) Str::uuid7();
        DB::table('outbox')->insert([
            'id' => $id, 'tenant_id' => TenantContext::id(), 'message_type' => $messageType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);

        return $id;
    }
}
