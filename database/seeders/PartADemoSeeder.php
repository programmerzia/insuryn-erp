<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Infrastructure\Jobs\OutboxRelayJob;
use App\Modules\Distribution\Application\Compensation\CompensationRuleRequest;
use App\Modules\Distribution\Application\Compensation\CompensationSchemeService;
use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Finance\Bank\Application\BankMatcher;
use App\Modules\Finance\Bank\Application\StatementImport;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Collections\Application\SuspenseService;
use App\Modules\Insurance\CoverNote\Application\CoverNoteService;
use App\Modules\Insurance\Party\Application\AgentService;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Renewal\Application\ExpiryRegister;
use App\Modules\Insurance\Renewal\Application\RenewalQuotations;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Domain\Enums\ProposalStatus;
use App\Modules\Platform\Approvals\ApprovalPolicyService;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\RoleTemplates;
use App\Modules\Platform\Setup\SetupProgress;
use App\Modules\Platform\Tax\TaxRateSetup;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The market cross-check Part A story, "a week in a non-life insurer" (session S2), in its own tenant so it stays exactly that story:
 *
 * - Padma General Insurance, Head Office and a Chittagong branch (CTG) with its own branch-scoped officer; 4 products (motor, fire, marine and a one-month fire
 *   short-period cover; rated by placeholder tariffs, earned 1/365 per day, issued on credit); 5 customers; 2 producers — Jamal Uddin, an agent on 10% commission
 *   through the compensation scheme AGENCY-NL, and Nasima Akter, a salaried BDO with none (the zero-commission case). The bank balance brought forward is
 *   the paid-up share capital.
 * - August: three policies issued and paid by bank transfer, one issued and later cancelled; a motor claim registered, reserved at
 *   200,000, approved at 180,000, paid by finance and closed (the 20,000 left released); the bank statement fully matched; the month closed and locked.
 * - September (open): a policy paid, a payment without a reference allocated from suspense, one still in suspense, a policy unpaid, an issued quotation
 *   to follow up, the cancellation, a marine claim reserved at 150,000; the September statement imported with three lines to match and two exceptions
 *   (bank charges and an unknown transfer), also written to storage/app/demo/city-bank-2026-09.csv.
 * - GA-35 (ASSUMPTION: A-199, demo placeholders): a proposal covered by a cover note while the customer arranges payment; in Chittagong, a short-period fire policy expiring on 2 October with its
 *   renewal quotation offered. Eight policies in all.
 *
 * Phase 3 R7: every policy is sold as a branch sells a rated product — quotation on the tariff, proposal with KYC, automatic underwriting approval, policy issued
 * from the proposal (DemoNewBusiness, with the clock on the story's day) — so premiums come from the placeholder tariffs and bank lines follow the receipts.
 *
 * Everything goes through the application services, acted by one user per §7.2 role (`<role>@<slug>.local`, admin password). A tenant that
 * already has policies is left as it is; the whole story is one transaction, so a failure leaves nothing behind.
 */
final class PartADemoSeeder extends Seeder
{
    public const STATEMENT_FILE = 'app/demo/city-bank-2026-09.csv';

    private const ROLES = ['branch_officer', 'branch_manager', 'claims_officer', 'claims_manager', 'accountant', 'finance_manager', 'cfo', 'auditor'];

    /** GA-35: the second branch and the email of its branch-scoped officer. */
    public const SECOND_BRANCH = ['code' => 'CTG', 'name' => 'Chittagong Branch'];

    public const SECOND_BRANCH_OFFICER = 'branch.officer.ctg';

    /** @var array<string, string> role code → user id */
    private array $users = [];

    private string $entityId = '';

    private string $branchId = '';

    private string $bankAccountId = '';

    private string $secondBranchId = '';

    /** @return bool whether the story was seeded (false: the tenant already had it) */
    public function run(string $slug = 'nonlife'): bool
    {
        if (DB::table('tenants')->where('slug', $slug)->exists()) {
            $tenantId = (string) DB::table('tenants')->where('slug', $slug)->value('id');
            if (TenantContext::run($tenantId, fn (): bool => DB::table('policies')->exists())) {
                return false;
            }
            throw new RuntimeException("Tenant {$slug} exists without the demo story; choose another slug.");
        }
        config(['queue.default' => 'sync']);

        DB::transaction(function () use ($slug): void {
            (new AccountRolesSeeder())->run();
            (new PermissionsSeeder())->run();
            (new ProductClassesSeeder())->run();
            $context = (new DemoTenantSeeder())->run($slug);
            DB::table('tenants')->where('id', $context['tenant_id'])->update(['name' => 'Padma General Insurance']);
            TenantContext::run($context['tenant_id'], function () use ($context, $slug): void {
                DB::table('legal_entities')->where('id', $context['entity_id'])->update(['code' => 'PADMA', 'name' => 'Padma General Insurance PLC']);
                RoleTemplates::seedCurrentTenant();
                app(\App\Modules\Platform\Documents\Templates\DocumentTemplates::class)->seedCurrentTenant(); // slice R8: default document templates
                \App\Modules\Accounting\Application\Posting\TenantDimensionRequirements::seedCurrentTenant($context['tenant_id']); // gap audit GA-47 (A-187)
                $this->entityId = $context['entity_id'];
                $this->branchId = $context['branch_id'];
                $this->users = $this->roleUsers($context['tenant_id'], $slug);
                // Phase 3 R7: the tenant admin (created again, unchanged, after the story) sets the placeholder underwriting limits (A-90, verify) from the first
                // day of the story, so the story's proposals are approved automatically within the branch officer's limits.
                (new AdminUserSeeder())->run();
                $admin = (string) DB::table('users')->where('email', "admin@{$slug}.local")->value('id');
                DemoNewBusiness::on('2026-08-01', fn (): int => app(\App\Modules\Insurance\Underwriting\Application\UnderwritingLimits::class)->acceptDefaults(CarbonImmutable::parse('2026-08-01'), $admin));
                $this->story($context['accounts']);
                foreach (SetupProgress::STEPS as $step) {
                    app(SetupProgress::class)->complete($step, $this->users['finance_manager']);
                }
                (new RegulatoryDemoSeeder())->run($this->entityId, $this->users); // market gap G5: Q3 technical provisions posted, Q3 returns with one filed
            });
            (new AdminUserSeeder())->run();
            // Fix F3: the default approval limits (A-55), set by the tenant admin after the story, so the story's approvals stay as they were and the
            // next claim payment from 500,000 routes to the finance manager and then the CFO.
            TenantContext::run($context['tenant_id'], fn (): int => app(ApprovalPolicyService::class)->acceptDefaults(CarbonImmutable::today(),
                (string) DB::table('users')->where('email', "admin@{$slug}.local")->value('id')));
        });
        dispatch_sync(new OutboxRelayJob());

        return true;
    }

    /** @param array<string, string> $accounts role → account id */
    private function story(array $accounts): void
    {
        $day = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
        [$officer, $manager, $claimsOfficer, $claimsManager, $accountant, $finance] = [$this->users['branch_officer'], $this->users['branch_manager'],
            $this->users['claims_officer'], $this->users['claims_manager'], $this->users['accountant'], $this->users['finance_manager']];

        // Configuration: VAT, the commission scheme, four products, the bank account and the bank balance brought forward. GA-35: commission comes from a
        // compensation scheme on the products (Distribution D4/D5), whose one rule pays agents 10% of premium received, so the salaried BDO earns none.
        app(TaxRateSetup::class)->ensure('BD', 'VAT', 1500, true, $day('2026-01-01'), $finance);
        $schemes = app(CompensationSchemeService::class);
        $scheme = $schemes->createScheme('AGENCY-NL', 'Agency commission (non-life)', 'commission', $day('2026-01-01'), null,
            ['allowed_producer_types' => ['agent'], 'non_life_commission_allowed' => true], $finance); // A-18: non-life commission allowed for this scheme (verify)
        $schemes->addRule($scheme, CompensationRuleRequest::fromArray(['basis' => 'premium_received', 'policy_year_from' => 1, 'policy_year_to' => 99,
            'effective_from' => '2026-01-01', 'producer_type' => 'agent', 'rate_bp' => 1000]), $finance);
        $catalogue = app(ProductCatalogue::class);
        $product = function (string $code, string $name, string $lob, string $class, int $termMonths = 12) use ($catalogue, $finance, $scheme): string {
            $product = $catalogue->createProduct($code, $name, $lob, $finance, 'non_life');
            // Phase 3 R1 (class, risk schema, coverages); R7: the story's premiums are paid after issue, so the demo products issue on credit (A-117).
            // Gap audit GA-44 (D-71, A-182): premium is earned 1/365 per day on cover, so a policy starting mid-August earns its August days in August.
            $catalogue->addVersion($product->id, ['effective_from' => '2026-01-01', 'term_months' => $termMonths, 'earning_method' => 'daily_365',
                'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true], ...DemoRatingCatalogue::versionTerms($class), 'allow_credit_issue' => true,
                'compensation_scheme_id' => $scheme], $finance);

            return $product->id;
        };
        $products = ['MOTOR' => $product('MOTOR', 'Motor Comprehensive', 'motor', 'motor'), 'FIRE' => $product('FIRE', 'Fire and Allied Perils', 'fire', 'fire'),
            'MARINE' => $product('MARINE', 'Marine Cargo', 'marine', 'marine_cargo'),
            // GA-35: a one-month short-period fire cover (seasonal stock), so the story has a policy near expiry to renew. Rated on the fire tariff (placeholder, verify).
            'FIRE-SP' => $product('FIRE-SP', 'Fire Short Period (seasonal stock)', 'fire', 'fire', 1)];
        DemoRatingPlans::seed($finance, $this->users['cfo']); // Phase 3 R3: placeholder tariffs and duties (verify)
        $this->bankAccountId = app(BankAccountService::class)->create($this->entityId, $accounts['bank_main'], 'City Bank', '****4471', 'BDT', $finance)->id;
        $journals = app(ManualJournalService::class);
        // GA-35: the balance brought forward is the paid-up share capital, not retained earnings (nothing has been earned yet).
        $shareCapital = (string) Str::uuid7();
        DB::table('accounts')->insert(['id' => $shareCapital, 'tenant_id' => TenantContext::id(), 'entity_id' => $this->entityId, 'code' => '3000', 'name' => 'Share Capital',
            'type' => 'equity', 'normal_side' => 'credit', 'is_postable' => true, 'is_control' => false, 'control_subledger' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $opening = $journals->create(new ManualJournalRequest($this->entityId, $day('2026-08-01'), 'Bank balance brought forward', JournalKind::Manual, 'Opening balance at City Bank: paid-up share capital', 'BDT', [
            new ManualJournalLine($accounts['bank_main'], Side::Debit, 2_000_000_00, ['branch' => $this->branchId], 'City Bank'),
            new ManualJournalLine($shareCapital, Side::Credit, 2_000_000_00, ['branch' => $this->branchId], 'Paid-up share capital'),
        ]), $accountant);
        $journals->submit($opening->id, $accountant);
        $journals->approve($opening->id, $finance);

        // People: five customers, and the two producers.
        $parties = app(PartyService::class);
        $customer = [];
        foreach (['Rahima Akter' => PartyKind::Individual, 'Karim Hossain' => PartyKind::Individual, 'Dhaka Garments Ltd' => PartyKind::Organization,
            'Meghna Traders' => PartyKind::Organization, 'Chittagong Shipping Co' => PartyKind::Organization] as $name => $kind) {
            $customer[$name] = $parties->create($kind, $name, null, [PartyRoleType::Customer, PartyRoleType::Policyholder], $officer)->id;
        }
        $agent = app(AgentService::class)->create($parties->create(PartyKind::Individual, 'Jamal Uddin', null, [PartyRoleType::Agent], $manager)->id, 'AG-001', $this->branchId, null, null, $manager)->id;
        $bdo = app(ProducerService::class)->create(new CreateProducer($parties->create(PartyKind::Individual, 'Nasima Akter', null, [PartyRoleType::Agent], $manager)->id,
            'BDO-001', 'bdo', $this->branchId, employeeId: (string) Str::uuid7(), joinedOn: $day('2026-01-01')), $manager)->id;
        foreach ([$agent => 'AG-001', $bdo => 'BDO-001'] as $producer => $code) {
            app(LicenceService::class)->record(new RecordLicence($producer, "IDRA-{$code}", 'non_life', $day('2026-01-01'), $day('2027-12-31')), $manager);
        }

        $lifecycle = app(PolicyLifecycle::class);
        // Phase 3 R7: sold through quotation → proposal → policy on the story's day; the premium comes from the tariff (placeholder, verify).
        $sell = fn (string $product, string $holder, ?string $producer, string $inception, array $risk, int $installments, bool $issue = true): string
            => DemoNewBusiness::sell($this->branchId, $product, $holder, $producer, $inception, $risk, $officer, $installments, $issue);
        $motor = fn (string $registration, string $chassis, int $sumInsuredMinor, int $cc, int $year, int $driverAge, int $claimFreeYears, string $type = 'private'): array => [
            'vehicle_type' => $type, 'registration_no' => $registration, 'chassis_no' => $chassis, 'engine_cc' => $cc, 'seats' => $type === 'motorcycle' ? 2 : 5,
            'year_of_manufacture' => $year, 'driver_age' => $driverAge, 'sum_insured' => $sumInsuredMinor, 'ncb_years' => $claimFreeYears];
        $fire = fn (string $address, string $occupancy, int $sumInsuredMinor): array => ['occupancy' => $occupancy, 'construction_class' => 'class_1', 'address' => $address, 'sum_insured' => $sumInsuredMinor];
        $firstInstallment = fn (string $policyId): int => (int) DB::table('installments')->where('policy_id', $policyId)->orderBy('no')->value('amount_minor');
        $major = fn (int $minor): string => intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
        $receipts = app(ReceiptService::class);
        $receive = function (?string $policyId, int $amountMinor, string $on, ?string $reference) use ($receipts, $manager, $day): string {
            $installment = $policyId === null ? null : DB::table('installments')->where('policy_id', $policyId)->orderBy('no')->value('id');
            $allocations = $installment === null ? [] : [new AllocationLine((string) $installment, $amountMinor)];

            return $receipts->record(new RecordReceiptRequest($this->entityId, $this->branchId, null, 'bank_transfer', $amountMinor, 'BDT', $day($on), $this->bankAccountId,
                $reference, $allocations), $manager)->id;
        };

        // August (Part A days 1–3): issue, receive, claim, match the bank, close the month.
        $motorPolicy = $sell($products['MOTOR'], $customer['Rahima Akter'], $agent, '2026-08-01', $motor('DHA-METRO-GA-11-2201', 'NZE141-0012201', 1_000_000_00, 1500, 2019, 38, 1), 1);
        $firePolicy = $sell($products['FIRE'], $customer['Dhaka Garments Ltd'], $bdo, '2026-08-05', $fire('Plot 12, Tejgaon Industrial Area, Dhaka', 'factory', 4_800_000_00), 4);
        $marine = $sell($products['MARINE'], $customer['Chittagong Shipping Co'], $agent, '2026-08-12', ['voyage_type' => 'import', 'conveyance' => 'sea', 'commodity' => 'Cotton yarn',
            'from_port' => 'Singapore', 'to_port' => 'Chattogram', 'sum_insured' => 1_800_000_00], 1);
        $toCancel = $sell($products['FIRE'], $customer['Meghna Traders'], $bdo, '2026-08-20', $fire('Warehouse 4, Khatunganj, Chattogram', 'warehouse', 3_000_000_00), 1);
        $receipt = ['motor' => $firstInstallment($motorPolicy), 'fire' => $firstInstallment($firePolicy), 'marine' => $firstInstallment($marine)];
        $receive($motorPolicy, $receipt['motor'], '2026-08-03', 'TRF RAHIMA MOTOR');
        $receive($firePolicy, $receipt['fire'], '2026-08-08', 'DGL FIRE Q1');
        $receive($marine, $receipt['marine'], '2026-08-14', 'CSC MARINE');

        $claims = app(ClaimService::class);
        $payments = app(ClaimPaymentService::class);
        $collision = $claims->register($motorPolicy, $day('2026-08-17'), 'Rear collision on the Dhaka–Mymensingh highway', $claimsOfficer, $day('2026-08-18'));
        $claims->reserve($collision->id, 200_000_00, 'Surveyor estimate', $claimsOfficer, $day('2026-08-19'));
        $payment = $payments->approve($collision->id, 180_000_00, $customer['Rahima Akter'], $claimsManager, $day('2026-08-25'));
        $payments->requestRelease($payment->id, $claimsManager, $this->bankAccountId);
        $payments->release($payment->id, $finance, $day('2026-08-27'));
        $claims->close($collision->id, 'Settled at 180,000', $claimsManager, $day('2026-08-28'));

        $this->importStatement("date,description,reference,amount\n2026-08-01,Balance brought forward,,2000000.00\n2026-08-04,Transfer,TRF RAHIMA MOTOR,{$major($receipt['motor'])}\n"
            ."2026-08-08,Transfer,DGL FIRE Q1,{$major($receipt['fire'])}\n2026-08-15,Transfer,CSC MARINE,{$major($receipt['marine'])}\n2026-08-27,Claim payment,,-180000.00\n", 'city-bank-2026-08.csv', matchAll: true);
        $this->closeMonth('2026-08-01');

        // September (open): more business, suspense, a claim waiting, the statement to match.
        $september = $sell($products['MOTOR'], $customer['Karim Hossain'], $agent, '2026-09-01', $motor('DHA-METRO-KA-22-3302', 'AXIO-0023302', 800_000_00, 1200, 2021, 45, 0), 1);
        $receipt['september'] = $firstInstallment($september);
        $receive($september, $receipt['september'], '2026-09-02', 'KARIM MOTOR');
        $unreferenced = $sell($products['FIRE'], $customer['Meghna Traders'], $bdo, '2026-09-03', $fire('Shop 18, Reazuddin Bazar, Chattogram', 'shop', 4_000_000_00), 2);
        $receipt['unreferenced'] = $firstInstallment($unreferenced);
        $suspense = $receive(null, $receipt['unreferenced'], '2026-09-04', null);
        app(SuspenseService::class)->allocate((string) DB::table('suspense_items')->where('receipt_id', $suspense)->value('id'),
            (string) DB::table('installments')->where('policy_id', $unreferenced)->orderBy('no')->value('id'), $receipt['unreferenced'], $accountant, $day('2026-09-06'));
        $lifecycle->cancel($toCancel, $day('2026-09-05'), 'Customer sold the insured stock', $manager);
        $sell($products['MOTOR'], $customer['Rahima Akter'], $agent, '2026-09-08', $motor('DHA-HA-33-4403', 'FZS-0034403', 250_000_00, 150, 2023, 29, 0, 'motorcycle'), 1);
        $receive(null, 8_500_00, '2026-09-10', 'DEP 7781');
        // The quote to follow up: an issued quotation for a car starting on 20 September, given on the story's last day.
        DemoNewBusiness::sell($this->branchId, $products['MOTOR'], $customer['Karim Hossain'], $agent, '2026-09-12', $motor('DHA-METRO-TA-44-5504', 'PREMIO-0045504', 1_200_000_00, 1600, 2022, 45, 2),
            $officer, issue: false);

        $this->coverNoteAndRenewal($products, $customer, $agent, $officer, $motor, $fire);

        $cargo = $claims->register($marine, $day('2026-09-07'), 'Cargo wetted in transit to Chattogram port', $claimsOfficer, $day('2026-09-09'));
        $claims->reserve($cargo->id, 150_000_00, 'Surveyor estimate', $claimsOfficer, $day('2026-09-10'));

        $september = "date,description,reference,amount\n2026-09-03,Transfer,KARIM MOTOR,{$major($receipt['september'])}\n2026-09-05,Deposit,,{$major($receipt['unreferenced'])}\n2026-09-09,Bank charges,,-350.00\n"
            ."2026-09-11,Cash deposit,DEP 7781,8500.00\n2026-09-12,Transfer,TT 9921,52000.00\n";
        File::ensureDirectoryExists(dirname(storage_path(self::STATEMENT_FILE)));
        File::put(storage_path(self::STATEMENT_FILE), $september);
        $this->importStatement($september, basename(self::STATEMENT_FILE), matchAll: false);
        $this->postQueuedEvents();
    }

    /**
     * GA-35: a cover note on an approved motor proposal while the customer arranges payment (Head Office, 11 September), and in the Chittagong branch a one-month fire
     * cover sold by the branch-scoped officer on 3 September that expires on 2 October, with its renewal quotation offered from the expiry register on 12 September.
     *
     * @param array<string, string> $products
     * @param array<string, string> $customer
     * @param callable(string, string, int, int, int, int, int, string=): array<string, mixed> $motor
     * @param callable(string, string, int): array<string, mixed> $fire
     */
    private function coverNoteAndRenewal(array $products, array $customer, string $agent, string $officer, callable $motor, callable $fire): void
    {
        DemoNewBusiness::on('2026-09-11', function () use ($products, $customer, $agent, $officer, $motor): void {
            $today = CarbonImmutable::parse('2026-09-11');
            $quotations = app(QuotationService::class);
            $quotation = $quotations->issue($quotations->saveDraft(new QuotationTerms($this->branchId, $products['MOTOR'], $customer['Dhaka Garments Ltd'], $agent, $today->addDays(4),
                $motor('DHA-METRO-GHA-15-6605', 'HIACE-0056605', 1_800_000_00, 2700, 2024, 41, 0, 'commercial')), null, $officer)->id, $today, $officer);
            $proposals = app(ProposalService::class);
            $proposal = $proposals->createFromQuotation($quotation->id, $officer);
            $proposals->verifyKyc($proposal->id, 'trade_licence', 'TRAD-DSCC-2019-114455', $officer); // demo trade licence number
            if ($proposals->submit($proposal->id, $officer)->status !== ProposalStatus::Approved) {
                throw new RuntimeException('Demo cover note proposal was referred.');
            }
            app(CoverNoteService::class)->issue($proposal->id, $today, $today->addDays(29), $officer);
        });

        $ctgOfficer = $this->users[self::SECOND_BRANCH_OFFICER];
        $ctg = $this->secondBranchId;
        $shortPeriod = DemoNewBusiness::sell($ctg, $products['FIRE-SP'], $customer['Meghna Traders'], null, '2026-09-03',
            $fire('Godown 9, Khatunganj, Chattogram', 'warehouse', 2_500_000_00), $ctgOfficer, 1);
        DemoNewBusiness::on('2026-09-12', function () use ($shortPeriod, $ctgOfficer): void {
            app(ExpiryRegister::class)->build(CarbonImmutable::parse('2026-09-12'));
            app(RenewalQuotations::class)->offerNow((string) DB::table('expiry_register')->where('policy_id', $shortPeriod)->value('id'), $ctgOfficer);
        });
    }

    /** Imports a statement as the accountant; `matchAll` matches every line to the ledger line of the same amount (August, before the close). */
    private function importStatement(string $csv, string $fileName, bool $matchAll): void
    {
        $this->postQueuedEvents(); // so their bank lines exist to match
        app(StatementImport::class)->import($this->bankAccountId, $csv, $fileName, $this->users['accountant']);
        if (! $matchAll) {
            return;
        }
        $matcher = app(BankMatcher::class);
        $gl = (string) DB::table('bank_accounts')->where('id', $this->bankAccountId)->value('gl_account_id');
        foreach (DB::table('bank_statement_lines')->where('bank_account_id', $this->bankAccountId)->where('match_status', 'unmatched')->orderBy('posted_on')->get(['id', 'amount_minor', 'posted_on']) as $line) {
            $candidates = array_values(array_filter($matcher->unmatchedJournalLines($gl, null, CarbonImmutable::parse((string) $line->posted_on)->addDays(3)),
                fn (array $l): bool => $l['amount_minor'] === (int) $line->amount_minor));
            $matcher->match((string) $line->id, [$candidates[0]['journal_line_id'] ?? throw new RuntimeException("No ledger line for statement line {$line->posted_on}.")], $this->users['accountant']);
        }
    }

    /**
     * Posts the accounting events queued so far, oldest first. Inside the story's transaction the normal after-commit dispatch waits for the
     * commit, so the demo posts them itself; the later relay finds them posted and does nothing (PostingEngine's status check).
     */
    private function postQueuedEvents(): void
    {
        foreach (DB::table('accounting_events')->where('status', 'queued')->orderBy('created_at')->orderBy('id')->pluck('id') as $eventId) {
            app(PostingEngine::class)->post((string) $eventId);
        }
        $failed = DB::table('accounting_events')->where('status', 'failed')->get(['event_type', 'failure_reason']);
        if ($failed->isNotEmpty()) {
            throw new RuntimeException('Demo accounting events failed: '.$failed->map(fn (object $e): string => "{$e->event_type} ({$e->failure_reason})")->implode(', '));
        }
    }

    /** Part A step 11–13: the finance manager runs every close task in order; any blocked task stops the demo with its reason. */
    private function closeMonth(string $starts): void
    {
        $this->postQueuedEvents();
        $close = app(PeriodCloseService::class);
        $finance = $this->users['finance_manager'];
        $runId = $close->start((string) DB::table('fiscal_periods')->where('starts', $starts)->value('id'), $finance);
        foreach (DB::table('period_close_tasks')->where('close_run_id', $runId)->orderBy('order_no')->get(['id', 'code']) as $task) {
            $close->execute((string) $task->id, $finance, $task->code === 'accruals' ? 'No accruals for August' : null);
            $this->postQueuedEvents(); // the earning task's events, as the after-commit dispatch would outside this transaction
            $done = DB::table('period_close_tasks')->where('id', $task->id)->first(['status', 'result']);
            if ($done === null || $done->status !== 'done') {
                throw new RuntimeException("Close task {$task->code} did not pass: ".($done->result ?? 'no result'));
            }
        }
    }

    /** @return array<string, string> role code → user id */
    private function roleUsers(string $tenantId, string $slug): array
    {
        $users = [];
        foreach (self::ROLES as $code) {
            $id = (string) Str::uuid7();
            DB::table('users')->insert(['id' => $id, 'tenant_id' => $tenantId, 'email' => str_replace('_', '.', $code)."@{$slug}.local", 'name' => ucfirst(str_replace('_', ' ', $code)),
                'password' => Hash::make((string) config('erp.seed.admin_password')), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            // GA-20: every demo role is on the user's timeline, like one given in Admin → Users. Still tenant-wide: the story's services (parties, products,
            // printing) check some permissions tenant-wide, so branch-scoped demo roles wait for the branch filtering work.
            [$scopeType, $scopeId] = ['tenant', $tenantId];
            $roleId = (string) DB::table('roles')->where('code', $code)->value('id');
            DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $id, 'role_id' => $roleId, 'scope_type' => $scopeType, 'scope_id' => $scopeId]);
            app(Audit::class)->record('user_role.assigned', AuditSubject::of('user', $id), null, ['role_id' => $roleId, 'role_code' => $code, 'scope_type' => $scopeType, 'scope_id' => $scopeId],
                'Part A demo', 'platform.manage_users', Actor::system());
            $users[$code] = $id;
        }
        // GA-35: the Chittagong branch and its officer, whose branch officer role is scoped to that branch only (G2).
        $this->secondBranchId = (string) Str::uuid7();
        DB::table('branches')->insert(['id' => $this->secondBranchId, 'tenant_id' => $tenantId, 'entity_id' => $this->entityId, 'code' => self::SECOND_BRANCH['code'],
            'name' => self::SECOND_BRANCH['name'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'tenant_id' => $tenantId, 'email' => self::SECOND_BRANCH_OFFICER."@{$slug}.local", 'name' => 'Branch officer (Chittagong)',
            'password' => Hash::make((string) config('erp.seed.admin_password')), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', 'branch_officer')->value('id'), 'scope_type' => 'branch', 'scope_id' => $this->secondBranchId]);
        $users[self::SECOND_BRANCH_OFFICER] = $id;

        return $users;
    }
}
