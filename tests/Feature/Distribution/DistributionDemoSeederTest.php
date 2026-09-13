<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoBusinessSeeder;
use Database\Seeders\DistributionDemoSeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\seed;
use function Pest\Laravel\travelTo;

/**
 * Distribution "End": the local demo shows both compensation modes — a life agency paid by commission with overrides, and salaried non-life BDOs
 * with commission disabled who are paid incentives — with statements prepared, approved and paid (`composer db:fresh`).
 */
it('seeds a life commission scheme with overrides and a non-life salaried scheme with incentives', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-13 10:00'));
    app()->detectEnvironment(fn (): string => 'local');
    seed(DatabaseSeeder::class);
    seed(DemoBusinessSeeder::class);
    seed(DistributionDemoSeeder::class);
    $tenantId = (string) DB::table('tenants')->where('slug', 'demo')->value('id');

    asTenant($tenantId, function (): void {
        $modes = DB::table('compensation_schemes')->orderBy('code')->pluck('mode', 'code')->all();
        $nonLifeProfile = (array) json_decode((string) DB::table('compensation_schemes')->where('code', 'NL-BDO')->value('compliance_profile'), true);
        expect($modes)->toBe(['LIFE-AGENCY' => 'commission', 'NL-BDO' => 'salary_incentive'])
            ->and($nonLifeProfile['non_life_commission_allowed'] ?? null)->toBeFalse();

        $byLevel = DB::table('commission_entries as e')->join('producers as p', 'p.id', '=', 'e.agent_id')->where('e.kind', 'earned')->whereNotNull('e.scheme_id')
            ->groupBy('e.beneficiary_role', 'e.level_code')->selectRaw("e.beneficiary_role || ':' || e.level_code as k, count(*) as n")->pluck('n', 'k')->map(fn ($n): int => (int) $n)->all();
        expect($byLevel)->toHaveKeys(['direct:FA', 'override:UM', 'override:BM'])
            ->and(DB::table('commission_entries as e')->join('producers as p', 'p.id', '=', 'e.agent_id')->where('p.type', 'bdo')->where('e.kind', '<>', 'bonus')->count())->toBe(0)
            ->and(DB::table('incentive_awards')->count())->toBeGreaterThan(0)
            ->and(DB::table('commission_statements')->where('period_end', '2026-08-31')->where('status', 'paid')->count())->toBe(3)
            ->and(DB::table('commission_statements')->where('period_end', '2026-09-30')->where('status', 'draft')->count())->toBeGreaterThan(0)
            ->and(DB::table('commission_statements as s')->join('producers as p', 'p.id', '=', 's.agent_id')->where('p.type', 'bdo')->pluck('s.paid_via')->unique()->values()->all())->toBe(['payroll'])
            ->and(DB::table('producer_advance_recoveries')->count())->toBeGreaterThan(0)
            ->and(DB::table('compliance_exceptions')->count())->toBe(0)
            ->and(DB::table('accounting_events')->where('status', 'failed')->whereIn('event_type', ['COMMISSION_EARNED', 'INCENTIVE_BONUS_EARNED', 'PRODUCER_ADVANCE_ISSUED', 'PRODUCER_ADVANCE_RECOVERED', 'COMMISSION_PAYOUT_TO_AP', 'COMMISSION_PAYOUT_TO_PAYROLL'])->count())->toBe(0);
    });
});
