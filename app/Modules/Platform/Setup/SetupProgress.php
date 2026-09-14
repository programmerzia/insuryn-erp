<?php

declare(strict_types=1);

namespace App\Modules\Platform\Setup;

use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Setup wizard progress (session S1): the steps a tenant has saved. A step saved again keeps its row and takes the new time; `done` means
 * someone finished the wizard, which stops the first sign-in redirect even when steps are left for other people.
 */
final class SetupProgress
{
    public const STEPS = ['company', 'fiscal_year', 'chart_of_accounts', 'bank_accounts', 'product', 'underwriting_limits', 'users', 'approvals', 'done']; // GA-18: bank accounts, underwriting limits

    public function complete(string $step, string $actorUserId): void
    {
        if (! in_array($step, self::STEPS, true)) {
            throw new InvalidArgumentException("Unknown setup step {$step}.");
        }
        DB::table('setup_progress')->upsert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'step' => $step, 'completed_by' => $actorUserId,
            'completed_at' => CarbonImmutable::now()], ['tenant_id', 'step'], ['completed_by', 'completed_at']);
    }

    /** @return list<string> */
    public function completed(): array
    {
        return array_values(DB::table('setup_progress')->pluck('step')->map(fn (mixed $step): string => (string) $step)->all());
    }

    public function isFinished(): bool
    {
        return DB::table('setup_progress')->where('step', 'done')->exists();
    }
}
