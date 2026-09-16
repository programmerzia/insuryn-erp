<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Infrastructure\Jobs\OutboxRelayJob;
use App\Modules\Distribution\Application\Advances\AdvanceService;
use App\Modules\Distribution\Application\Compensation\CompensationRuleRequest;
use App\Modules\Distribution\Application\Compensation\CompensationSchemeService;
use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\Hierarchy\HierarchyService;
use App\Modules\Distribution\Application\Incentives\IncentivePlanService;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Distribution\Application\Targets\TargetService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Commission\Application\CommissionPayoutService;
use App\Modules\Insurance\Commission\Application\CommissionStatementRun;
use App\Modules\Insurance\Commission\Application\IncentiveRun;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Distribution design note "End": both compensation modes, demonstrable on the demo tenant (local only; run after DemoBusinessSeeder).
 * - Life: Endowment product under the LIFE-AGENCY commission scheme — levels FA < UM < BM, first-year 25% direct with 5% (UM) and 2% (BM)
 *   overrides, renewal 5% / 1%, caps 35% first year and 10% renewal, 5% withholding. One branch manager, two unit managers, three financial associates.
 * - Non-life: SME fire product under NL-BDO (salary_incentive: commission disabled), three salaried BDOs with a monthly premium incentive plan and targets.
 * August statements are prepared and approved (three paid); September's are drafts. One associate has an advance. A local-only `payer@demo.local` holds
 * commission.pay (no §7.2 template does), so payouts and advances can be shown.
 */
final class DistributionDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DistributionDemoSeeder runs in the local environment only.');
        }
        config(['queue.default' => 'sync']);
        $tenantId = (string) (DB::table('tenants')->where('slug', 'demo')->value('id') ?? throw new RuntimeException('Seed the demo tenant first.'));

        TenantContext::run($tenantId, function () use ($tenantId): void {
            if (DB::table('compensation_schemes')->exists()) {
                throw new RuntimeException('The demo tenant already has compensation schemes; migrate:fresh --seed first.');
            }
            $this->seed($tenantId);
        });
        dispatch_sync(new OutboxRelayJob());
    }

    private function seed(string $tenantId): void
    {
        $user = fn (string $email): string => (string) (DB::table('users')->where('email', $email)->value('id') ?? throw new RuntimeException("Run DemoBusinessSeeder first ({$email})."));
        [$admin, $planner, $manager] = [$user('admin@demo.local'), $user('finance.manager@demo.local'), $user('branch.manager@demo.local')];
        $payer = $this->payer($tenantId);
        $entityId = (string) DB::table('legal_entities')->value('id');
        $branchId = (string) DB::table('branches')->value('id');
        $day = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
        DB::table('tax_rates')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'jurisdiction' => 'BD', 'tax_type' => 'AIT_COMMISSION', 'rate_bp' => 500,
            'inclusive' => false, 'withholding' => true, 'effective_from' => '2026-01-01']);

        $schemes = app(CompensationSchemeService::class);
        $catalogue = app(ProductCatalogue::class);
        $life = $catalogue->createProduct('LIFE-END', 'Endowment life', 'life', $admin);
        $fire = $catalogue->createProduct('FIRE-SME', 'SME fire', 'fire', $admin);

        $lifeScheme = $schemes->createScheme('LIFE-AGENCY', 'Life agency commission', 'commission', $day('2026-01-01'), null, [
            'allowed_producer_types' => ['agent', 'agency_org'],
            'caps' => [['policy_year_from' => 1, 'policy_year_to' => 1, 'max_total_bp' => 3500], ['policy_year_from' => 2, 'policy_year_to' => 99, 'max_total_bp' => 1000]],
        ], $planner, 'BD', 'AIT_COMMISSION');
        app(HierarchyService::class)->defineLevels($lifeScheme, [['code' => 'FA', 'rank' => 1, 'label' => 'Financial associate'], ['code' => 'UM', 'rank' => 2, 'label' => 'Unit manager'],
            ['code' => 'BM', 'rank' => 3, 'label' => 'Branch manager']], $planner);
        foreach ([
            ['producer_type' => 'agent', 'level_code' => 'FA', 'rate_bp' => 2500],
            ['producer_type' => 'agent', 'level_code' => 'FA', 'rate_bp' => 500, 'policy_year_from' => 2, 'policy_year_to' => 99],
            ['level_code' => 'UM', 'override_rate_bp' => 500],
            ['level_code' => 'UM', 'override_rate_bp' => 100, 'policy_year_from' => 2, 'policy_year_to' => 99],
            ['level_code' => 'BM', 'override_rate_bp' => 200],
        ] as $rule) {
            $schemes->addRule($lifeScheme, CompensationRuleRequest::fromArray(['basis' => 'premium_received', 'policy_year_from' => 1, 'policy_year_to' => 1, 'effective_from' => '2026-01-01',
                'product_id' => $life->id, ...$rule]), $planner);
        }
        $nonLifeScheme = $schemes->createScheme('NL-BDO', 'Non-life salaried BDOs', 'salary_incentive', $day('2026-01-01'), null, ['allowed_producer_types' => ['bdo', 'broker']], $planner);
        $versionTerms = ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'daily_365', 'posting_rule_set' => 'default', 'coverages' => [],
            'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true, 'refund_tax_on_cancellation' => true]];
        $catalogue->addVersion($life->id, [...$versionTerms, 'tax_profile' => ['inclusive' => true], 'compensation_scheme_id' => $lifeScheme], $admin);
        // Phase 3 R1 (life is a LATER class); R7: the fire policies are paid on issue day or later, so the demo product issues on credit (A-117).
        $catalogue->addVersion($fire->id, [...$versionTerms, 'compensation_scheme_id' => $nonLifeScheme, ...DemoRatingCatalogue::versionTerms('fire'), 'allow_credit_issue' => true], $admin);

        $parties = app(PartyService::class);
        $producers = app(ProducerService::class);
        $licence = fn (string $id, string $code, string $class) => app(LicenceService::class)->record(new RecordLicence($id, "IDRA-{$code}", $class, $day('2026-01-01'),
            $code === 'FA-02' ? $day('2026-10-20') : $day('2027-12-31')), $manager);
        /** @var array<string, string> $agents producer id by code */
        $agents = [];
        foreach ([['BM-01', 'Anwar Hossain', null, 'BM'], ['UM-01', 'Salma Begum', 'BM-01', 'UM'], ['UM-02', 'Rafiqul Islam', 'BM-01', 'UM'],
            ['FA-01', 'Nasrin Akter', 'UM-01', 'FA'], ['FA-02', 'Habib Rahman', 'UM-01', 'FA'], ['FA-03', 'Shapla Khatun', 'UM-02', 'FA']] as [$code, $name, $parent, $level]) {
            $party = $parties->create(PartyKind::Individual, $name, null, [PartyRoleType::Agent], $manager);
            $id = $producers->create(new CreateProducer($party->id, $code, 'agent', $branchId, joinedOn: $day('2026-01-15')), $manager)->id;
            app(HierarchyService::class)->place($id, $parent === null ? null : $agents[$parent], $level, $day('2026-01-15'), $manager);
            $licence($id, $code, 'life');
            $agents[$code] = $id;
        }
        /** @var array<string, string> $bdos producer id by code */
        $bdos = [];
        foreach ([['BDO-01', 'Tahmina Ferdous'], ['BDO-02', 'Mahbub Alam'], ['BDO-03', 'Rumana Sultana']] as [$code, $name]) {
            $party = $parties->create(PartyKind::Individual, $name, null, [PartyRoleType::Employee], $manager);
            $id = $producers->create(new CreateProducer($party->id, $code, 'bdo', $branchId, employeeId: (string) Str::uuid7(), joinedOn: $day('2026-02-01')), $manager)->id;
            $licence($id, $code, 'non_life');
            $bdos[$code] = $id;
        }

        $holders = DB::table('parties as p')->join('party_roles as r', 'r.party_id', '=', 'p.id')->where('r.role', 'policyholder')->orderBy('p.display_name')->pluck('p.id')->map(fn ($id): string => (string) $id)->all();
        $lifecycle = app(PolicyLifecycle::class);
        $receipts = app(ReceiptService::class);
        /** @param int|array<string, mixed> $premiumOrRisk the typed premium of the (unrated) life product, or the risk of the rated fire product (Phase 3 R7) */
        $sell = function (string $productId, string $producerId, int|array $premiumOrRisk, string $issued, ?string $received, int $holder) use ($lifecycle, $receipts, $entityId, $branchId, $manager, $holders, $day): void {
            if (is_array($premiumOrRisk)) {
                $policyId = DemoNewBusiness::sell($branchId, $productId, $holders[$holder % count($holders)], $producerId, $issued, $premiumOrRisk, $manager);
            } else {
                $policyId = $lifecycle->quote(new QuoteRequest($entityId, $branchId, $productId, $holders[$holder % count($holders)], $producerId, $day($issued), $premiumOrRisk, (string) config('erp.default_currency', 'KES'), 1), $manager)->id;
                $lifecycle->issue($policyId, $day($issued), $manager);
            }
            if ($received !== null) {
                $premium = (int) DB::table('policies')->where('id', $policyId)->value('gross_premium_minor');
                $receipts->record(new RecordReceiptRequest($entityId, $branchId, null, 'bank_transfer', $premium, (string) config('erp.default_currency', 'KES'), $day($received), null, 'DIST-'.substr($policyId, -6),
                    [new AllocationLine((string) DB::table('installments')->where('policy_id', $policyId)->value('id'), $premium)]), $manager);
            }
        };
        $i = 0;
        foreach ([['FA-01', 6_000_000, '2026-08-05', '2026-08-10'], ['FA-02', 4_800_000, '2026-08-12', '2026-08-20'], ['FA-03', 7_500_000, '2026-08-18', '2026-08-25'],
            ['FA-01', 9_000_000, '2026-09-02', '2026-09-05'], ['FA-02', 3_600_000, '2026-09-04', '2026-09-08'], ['FA-03', 5_400_000, '2026-09-06', null], ['UM-01', 12_000_000, '2026-09-07', '2026-09-10']] as [$code, $premium, $issued, $received]) {
            $sell($life->id, $agents[$code], $premium, $issued, $received, $i++);
        }
        // Phase 3 R7: rated on the placeholder fire tariff, within the branch manager's placeholder limit (A-90): factory 25,000,000.00 → gross 72,375.00; factory
        // 24,000,000.00 → 69,500.00; shop 20,000,000.00 → 34,700.00; warehouse 20,000,000.00 → 46,500.00; dwelling 25,000,000.00 → 23,500.00 (verify).
        $fireRisk = fn (string $occupancy, int $sumInsuredMinor, string $address): array => ['occupancy' => $occupancy, 'construction_class' => 'class_1', 'address' => $address, 'sum_insured' => $sumInsuredMinor];
        foreach ([['BDO-01', $fireRisk('factory', 2_500_000_000, 'Plot 7, BSCIC Industrial Estate, Tongi'), '2026-08-08'], ['BDO-02', $fireRisk('shop', 2_000_000_000, 'Shop 41, Bashundhara City, Dhaka'), '2026-08-14'],
            ['BDO-03', $fireRisk('factory', 2_400_000_000, 'Plot 22, Savar EPZ, Dhaka'), '2026-08-21'], ['BDO-01', $fireRisk('warehouse', 2_000_000_000, 'Godown 3, Kanchpur, Narayanganj'), '2026-09-03'],
            ['BDO-03', $fireRisk('dwelling', 2_500_000_000, 'House 9, Road 11, Gulshan 2, Dhaka'), '2026-09-09']] as [$code, $risk, $issued]) {
            $sell($fire->id, $bdos[$code], $risk, $issued, $issued, $i++);
        }

        $targets = app(TargetService::class);
        // Phase 3 R7: monthly targets sized to the rated fire premiums above (60,000.00), so BDO-01 and BDO-03 earn the August bonus and BDO-02 does not.
        foreach (['BDO-01' => 6_000_000, 'BDO-02' => 6_000_000, 'BDO-03' => 6_000_000] as $code => $target) {
            $targets->set('producer', $bdos[$code], 'monthly', $day('2026-08-01'), 'premium', $target, $manager);
            $targets->set('producer', $bdos[$code], 'monthly', $day('2026-09-01'), 'premium', $target, $manager);
        }
        foreach (['FA-01' => 8_000_000, 'FA-02' => 8_000_000, 'FA-03' => 8_000_000] as $code => $target) {
            $targets->set('producer', $agents[$code], 'monthly', $day('2026-09-01'), 'premium', $target, $manager);
        }
        $targets->set('branch', $branchId, 'monthly', $day('2026-09-01'), 'premium', 60_000_000, $manager);
        app(IncentivePlanService::class)->create(['code' => 'BDO-MONTHLY', 'name' => 'BDO monthly premium bonus', 'period_type' => 'monthly', 'metric' => 'premium',
            'applies_to' => ['producer_type' => 'bdo'], 'effective_from' => '2026-01-01', 'tiers' => [
                ['achievement_bp_from' => 10000, 'bonus' => ['type' => 'fixed_minor', 'value' => 150_000]],
                ['achievement_bp_from' => 12500, 'bonus' => ['type' => 'percent_of_metric', 'value' => 100]],
            ]], $planner);

        app(AdvanceService::class)->issue($agents['FA-02'], 2_000_000, ['type' => 'percent_of_net', 'bp' => 5000], $day('2026-08-01'), $payer);
        app(IncentiveRun::class)->run($entityId, $day('2026-08-31'), $planner);
        $run = app(CommissionStatementRun::class);
        foreach ($run->prepare($entityId, $day('2026-08-31'), $planner) as $statementId) {
            $statement = $run->approve($statementId, $planner, $day('2026-09-02'));
            if (in_array($statement->agent_id, [$agents['FA-01'], $agents['UM-01'], $bdos['BDO-03']], true)) {
                app(CommissionPayoutService::class)->pay($statementId, null, $payer, $day('2026-09-05'));
            }
        }
        $run->prepare($entityId, $day('2026-09-30'), $planner);
    }

    /** A local-only user holding commission.pay, which no §7.2 role template grants. */
    private function payer(string $tenantId): string
    {
        $roleId = (string) Str::uuid7();
        DB::table('roles')->insert(['id' => $roleId, 'tenant_id' => $tenantId, 'code' => 'commission_payer', 'name' => 'Commission payer', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_permissions')->insert(['tenant_id' => $tenantId, 'role_id' => $roleId, 'permission_code' => 'commission.pay']);
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $tenantId, 'email' => 'payer@demo.local', 'name' => 'Commission payer',
            'password' => Hash::make((string) config('erp.seed.admin_password')), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $id, 'role_id' => $roleId, 'scope_type' => 'tenant', 'scope_id' => $tenantId]);

        return $id;
    }
}
