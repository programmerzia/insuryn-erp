<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application;

use App\Modules\Distribution\Domain\Enums\ChannelType;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The tenant's channels. Each channel type has a standard channel (code = type in capitals), created the first time it is needed. */
final class ChannelDirectory
{
    public function standard(ChannelType $type): string
    {
        DB::table('channels')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'code' => $type->standardCode(),
            'name' => $type->standardName(), 'type' => $type->value, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return (string) DB::table('channels')->where('code', $type->standardCode())->value('id');
    }
}
