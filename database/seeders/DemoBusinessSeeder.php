<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Infrastructure\Jobs\OutboxRelayJob;
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Finance\Bank\Application\BankMatcher;
use App\Modules\Finance\Bank\Application\StatementImport;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ChequeDetails;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Party\Application\AgentService;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningRun;
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
 * Local-only demo business for the demo tenant, so screens, screenshots and the browser happy path have something to show. Everything goes
 * through the application services (posting engine, SoD, numbering), acted by one user per §7.2 role: `<role>@demo.local` with the admin
 * password. Not called by DatabaseSeeder; run `php artisan db:seed --class=DemoBusinessSeeder` on a freshly seeded local database.
 */
final class DemoBusinessSeeder extends Seeder
{
    private const ROLES = ['branch_officer', 'branch_manager', 'claims_officer', 'claims_manager', 'accountant', 'finance_manager', 'cfo', 'auditor'];

    private const NAMES = ['Rahima Akter', 'Karim Hossain', 'Nusrat Jahan', 'Tanvir Ahmed', 'Farzana Islam', 'Sabbir Rahman', 'Mitu Chowdhury', 'Arif Hasan',
        'Shirin Sultana', 'Imran Kabir', 'Dhaka Garments Ltd', 'Meghna Traders', 'Padma Logistics', 'Jamuna Textiles', 'Sylhet Tea Estates', 'Chittagong Shipping Co'];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoBusinessSeeder runs in the local environment only.');
        }
        config(['queue.default' => 'sync']);
        $tenantId = (string) (DB::table('tenants')->where('slug', 'demo')->value('id') ?? throw new RuntimeException('Seed the demo tenant first.'));

        TenantContext::run($tenantId, function () use ($tenantId): void {
            if (DB::table('policies')->exists()) {
                throw new RuntimeException('The demo tenant already has business; migrate:fresh --seed first.');
            }
            $this->seedBusiness($tenantId);
        });
        dispatch_sync(new OutboxRelayJob());
    }

    private function seedBusiness(string $tenantId): void
    {
        $users = $this->roleUsers($tenantId);
        $admin = (string) DB::table('users')->where('email', 'admin@demo.local')->value('id');
        $entityId = (string) DB::table('legal_entities')->value('id');
        $branchId = (string) DB::table('branches')->value('id');
        $accounts = DB::table('accounts')->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
        $day = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);

        DB::table('tax_rates')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'jurisdiction' => 'BD', 'tax_type' => 'VAT', 'rate_bp' => 1500,
            'inclusive' => true, 'withholding' => false, 'effective_from' => '2026-01-01']);
        $plan = app(CommissionPlanService::class)->create('STD10', 'Standard 10%', 1000, null, null, $users['finance_manager']);

        $catalogue = app(ProductCatalogue::class);
        $products = [];
        foreach ([['MOTOR', 'Motor comprehensive', 'motor'], ['FIRE', 'Fire and allied perils', 'fire'], ['MARINE', 'Marine cargo', 'marine']] as [$code, $name, $lob]) {
            $product = $catalogue->createProduct($code, $name, $lob, $admin);
            $catalogue->addVersion($product->id, ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'daily_365',
                'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true, 'refund_tax_on_cancellation' => true],
                'commission_plan_id' => $plan->id, 'posting_rule_set' => 'default', 'coverages' => []], $admin);
            $products[] = $product->id;
        }

        $parties = app(PartyService::class);
        $holders = [];
        foreach (self::NAMES as $i => $name) {
            $kind = $i < 10 ? PartyKind::Individual : PartyKind::Organization;
            $holders[] = $parties->create($kind, $name, null, [PartyRoleType::Customer, PartyRoleType::Policyholder], $users['branch_officer'])->id;
        }
        $agents = [];
        foreach ([['Jamal Uddin', 'AG-001'], ['Rokeya Begum', 'AG-002'], ['Selim Reza', 'AG-003']] as [$name, $code]) {
            $party = $parties->create(PartyKind::Individual, $name, null, [PartyRoleType::Agent], $users['branch_manager']);
            $agents[] = app(AgentService::class)->create($party->id, $code, $branchId, null, $plan->id, $users['branch_manager'])->id;
        }

        $lifecycle = app(PolicyLifecycle::class);
        $premiums = [4_500_000, 12_000_000, 8_750_000, 23_000_000, 6_200_000, 15_500_000, 3_900_000, 9_800_000, 31_000_000, 5_400_000, 18_250_000, 7_300_000];
        $issued = [];
        for ($i = 0; $i < 36; $i++) {
            $inception = $day('2026-07-01')->addDays($i * 2);
            $request = new QuoteRequest($entityId, $branchId, $products[$i % 3], $holders[$i % count($holders)], $i % 4 === 3 ? null : $agents[$i % 3],
                $inception, $premiums[$i % count($premiums)], 'BDT', [1, 2, 4][$i % 3]);
            $policy = $lifecycle->quote($request, $users['branch_officer']);
            if ($i >= 32) {
                continue; // quotes still to follow up
            }
            $lifecycle->issue($policy->id, $inception, $users['branch_manager']);
            $issued[] = $policy->id;
        }

        $receipts = app(ReceiptService::class);
        foreach ($issued as $i => $policyId) {
            if ($i % 3 === 2) {
                continue; // installments left overdue for dunning and ageing
            }
            $installment = DB::table('installments')->where('policy_id', $policyId)->orderBy('no')->first(['id', 'amount_minor', 'due_date']);
            if ($installment === null) {
                continue;
            }
            $valueDate = $day((string) $installment->due_date)->addDays(3);
            $channel = ['bank_transfer', 'cheque', 'cash', 'mobile_money'][$i % 4];
            $cheque = $channel === 'cheque' ? new ChequeDetails((string) (88200 + $i), 'Sonali Bank', $valueDate->subDay()) : null;
            $receipts->record(new RecordReceiptRequest($entityId, $branchId, null, $channel, (int) $installment->amount_minor, 'BDT', $valueDate, null,
                "REF-{$i}", [new AllocationLine((string) $installment->id, (int) $installment->amount_minor)], $cheque), $users['branch_manager']);
        }
        foreach ([['2026-09-02', 2_500_000, 'unreadable ref'], ['2026-08-21', 1_150_000, 'TT 4471'], ['2026-07-28', 640_000, 'cash deposit']] as [$date, $amount, $ref]) {
            $receipts->record(new RecordReceiptRequest($entityId, $branchId, null, 'bank_transfer', $amount, 'BDT', $day($date), null, $ref, []), $users['branch_officer']);
        }

        $bank = app(BankAccountService::class)->create($entityId, $accounts['1010'], 'City Bank', '****4471', 'BDT', $users['finance_manager']);
        $csv = "date,description,reference,amount\n2026-09-03,Transfer,REF-0,".$this->major(4_500_000)."\n2026-09-05,Deposit,unknown,18000.00\n"
            ."2026-09-08,Bank charges,,-350.00\n2026-09-09,Transfer,TT 9921,52000.00\n2026-09-10,Card settlement,CS-771,7300.00\n";
        app(StatementImport::class)->import($bank->id, $csv, 'city-sep.csv', $users['accountant']);
        app(BankMatcher::class)->autoMatch($bank->id);

        $claims = app(ClaimService::class);
        $payments = app(ClaimPaymentService::class);
        // [description, reserve, payment approved, release requested]; no reserve = awaiting reserve
        $claimSpecs = [['Rear collision', 300_000_00, 120_000_00, true], ['Kitchen fire', 150_000_00, 90_000_00, true], ['Water damage in transit', 80_000_00, 40_000_00, false],
            ['Windscreen', 25_000_00, null, false], ['Theft of goods', 60_000_00, null, false], ['Flood', null, null, false]];
        foreach ($claimSpecs as $i => [$description, $reserve, $payment, $release]) {
            $policyId = $issued[$i];
            $loss = $day('2026-08-10')->addDays($i * 4);
            $claim = $claims->register($policyId, $loss, $description, $users['claims_officer'], $loss->addDay());
            if ($reserve === null) {
                continue;
            }
            $claims->reserve($claim->id, $reserve, 'Initial estimate', $users['claims_officer'], $loss->addDays(2));
            if ($payment !== null) {
                $holder = (string) DB::table('policies')->where('id', $policyId)->value('policyholder_party_id');
                $approved = $payments->approve($claim->id, $payment, $holder, $users['claims_manager'], $loss->addDays(5));
                if ($release) {
                    $payments->requestRelease($approved->id, $users['claims_manager'], null);
                }
            }
        }

        $earning = app(PremiumEarningRun::class);
        foreach (['2026-07-01', '2026-08-01'] as $starts) {
            $earning->run((string) DB::table('fiscal_periods')->where('starts', $starts)->value('id'));
        }

        $journals = app(ManualJournalService::class);
        $journal = $journals->create(new ManualJournalRequest($entityId, $day('2026-09-10'), 'Office rent accrual for September', JournalKind::Manual, 'Rent invoice 9/26', 'BDT', [
            new ManualJournalLine($accounts['5300'], Side::Debit, 85_000_00, ['branch' => $branchId], 'Rent'),
            new ManualJournalLine($accounts['1010'], Side::Credit, 85_000_00, ['branch' => $branchId], null),
        ]), $users['accountant']);
        $journals->submit($journal->id, $users['accountant']);
    }

    /** @return array<string, string> role code → user id */
    private function roleUsers(string $tenantId): array
    {
        $users = [];
        foreach (self::ROLES as $code) {
            $email = str_replace('_', '.', $code).'@demo.local';
            $id = (string) Str::uuid7();
            DB::table('users')->insert(['id' => $id, 'tenant_id' => $tenantId, 'email' => $email, 'name' => ucfirst(str_replace('_', ' ', $code)),
                'password' => Hash::make((string) config('erp.seed.admin_password')), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $roleId = DB::table('roles')->where('code', $code)->value('id') ?? throw new RuntimeException("Role {$code} is missing; run RolesSeeder first.");
            DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $id, 'role_id' => $roleId, 'scope_type' => 'tenant', 'scope_id' => $tenantId]);
            $users[$code] = $id;
        }

        return $users;
    }

    private function major(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
