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
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Party\Application\AgentService;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Platform\Approvals\ApprovalPolicyService;
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
 * - Padma General Insurance, Head Office; 3 products (motor, fire, marine; VAT 15% included, monthly earning); 5 customers;
 *   2 producers — Jamal Uddin, an agent on 10% commission, and Nasima Akter, a salaried BDO with none (the zero-commission case).
 * - August: three policies issued and paid by bank transfer, one issued and later cancelled; a motor claim registered, reserved at
 *   200,000, approved at 180,000, paid by finance and closed (the 20,000 left released); the bank statement fully matched; the month closed and locked.
 * - September (open): a policy paid, a payment without a reference allocated from suspense, one still in suspense, a policy unpaid, a quote to
 *   follow up, the cancellation, a marine claim reserved at 150,000; the September statement imported with three lines to match and two exceptions
 *   (bank charges and an unknown transfer), also written to storage/app/demo/city-bank-2026-09.csv.
 *
 * Everything goes through the application services, acted by one user per §7.2 role (`<role>@<slug>.local`, admin password). A tenant that
 * already has policies is left as it is; the whole story is one transaction, so a failure leaves nothing behind.
 */
final class PartADemoSeeder extends Seeder
{
    public const STATEMENT_FILE = 'app/demo/city-bank-2026-09.csv';

    private const ROLES = ['branch_officer', 'branch_manager', 'claims_officer', 'claims_manager', 'accountant', 'finance_manager', 'cfo', 'auditor'];

    /** @var array<string, string> role code → user id */
    private array $users = [];

    private string $entityId = '';

    private string $branchId = '';

    private string $bankAccountId = '';

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
                $this->entityId = $context['entity_id'];
                $this->branchId = $context['branch_id'];
                $this->users = $this->roleUsers($context['tenant_id'], $slug);
                $this->story($context['accounts']);
                foreach (SetupProgress::STEPS as $step) {
                    app(SetupProgress::class)->complete($step, $this->users['finance_manager']);
                }
            });
            (new AdminUserSeeder())->run();
            // Fix F3: the default approval limits (A-55), set by the tenant admin after the story, so the story's approvals stay as they were and the
            // next claim payment from 500,000 routes to the finance manager and then the CFO.
            TenantContext::run($context['tenant_id'], fn (): int => app(ApprovalPolicyService::class)->acceptDefaults(CarbonImmutable::today(),
                (string) DB::table('users')->where('email', "admin@{$slug}.local")->value('id')));
            // Phase 3 R5: placeholder underwriting limits (A-90, flagged verify).
            TenantContext::run($context['tenant_id'], fn (): int => app(\App\Modules\Insurance\Underwriting\Application\UnderwritingLimits::class)->acceptDefaults(CarbonImmutable::today(),
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

        // Configuration: VAT, three products, the bank account and the bank balance brought forward. The commission plan goes on the agent, not the
        // products (a product's plan would pay every producer, A-7), so the salaried BDO earns none.
        app(TaxRateSetup::class)->ensure('BD', 'VAT', 1500, true, $day('2026-01-01'), $finance);
        $plan = app(CommissionPlanService::class)->create('AGENT10', 'Agent commission 10%', 1000, null, null, $finance);
        $catalogue = app(ProductCatalogue::class);
        $product = function (string $code, string $name, string $lob, string $class) use ($catalogue, $finance): string {
            $product = $catalogue->createProduct($code, $name, $lob, $finance, 'non_life');
            $catalogue->addVersion($product->id, ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly',
                'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true], ...DemoRatingCatalogue::versionTerms($class)], $finance); // Phase 3 R1

            return $product->id;
        };
        $products = ['MOTOR' => $product('MOTOR', 'Motor Comprehensive', 'motor', 'motor'), 'FIRE' => $product('FIRE', 'Fire and Allied Perils', 'fire', 'fire'),
            'MARINE' => $product('MARINE', 'Marine Cargo', 'marine', 'marine_cargo')];
        DemoRatingPlans::seed($finance, $this->users['cfo']); // Phase 3 R3: placeholder tariffs and duties (verify)
        $this->bankAccountId = app(BankAccountService::class)->create($this->entityId, $accounts['bank_main'], 'City Bank', '****4471', 'BDT', $finance)->id;
        $journals = app(ManualJournalService::class);
        $opening = $journals->create(new ManualJournalRequest($this->entityId, $day('2026-08-01'), 'Bank balance brought forward', JournalKind::Manual, 'Opening balance at City Bank', 'BDT', [
            new ManualJournalLine($accounts['bank_main'], Side::Debit, 2_000_000_00, ['branch' => $this->branchId], 'City Bank'),
            new ManualJournalLine($accounts['retained_earnings'], Side::Credit, 2_000_000_00, ['branch' => $this->branchId], null),
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
        $agent = app(AgentService::class)->create($parties->create(PartyKind::Individual, 'Jamal Uddin', null, [PartyRoleType::Agent], $manager)->id, 'AG-001', $this->branchId, null, $plan->id, $manager)->id;
        $bdo = app(ProducerService::class)->create(new CreateProducer($parties->create(PartyKind::Individual, 'Nasima Akter', null, [PartyRoleType::Agent], $manager)->id,
            'BDO-001', 'bdo', $this->branchId, employeeId: (string) Str::uuid7(), joinedOn: $day('2026-01-01')), $manager)->id;
        foreach ([$agent => 'AG-001', $bdo => 'BDO-001'] as $producer => $code) {
            app(LicenceService::class)->record(new RecordLicence($producer, "IDRA-{$code}", 'non_life', $day('2026-01-01'), $day('2027-12-31')), $manager);
        }

        $lifecycle = app(PolicyLifecycle::class);
        $sell = function (string $product, string $holder, ?string $producer, string $inception, int $premiumMinor, int $installments, bool $issue = true) use ($lifecycle, $officer, $day): string {
            $policy = $lifecycle->quote(new QuoteRequest($this->entityId, $this->branchId, $product, $holder, $producer, $day($inception), $premiumMinor, 'BDT', $installments), $officer);
            if ($issue) {
                $lifecycle->issue($policy->id, $day($inception), $officer);
            }

            return $policy->id;
        };
        $receipts = app(ReceiptService::class);
        $receive = function (?string $policyId, int $amountMinor, string $on, ?string $reference) use ($receipts, $manager, $day): string {
            $installment = $policyId === null ? null : DB::table('installments')->where('policy_id', $policyId)->orderBy('no')->value('id');
            $allocations = $installment === null ? [] : [new AllocationLine((string) $installment, $amountMinor)];

            return $receipts->record(new RecordReceiptRequest($this->entityId, $this->branchId, null, 'bank_transfer', $amountMinor, 'BDT', $day($on), $this->bankAccountId,
                $reference, $allocations), $manager)->id;
        };

        // August (Part A days 1–3): issue, receive, claim, match the bank, close the month.
        $motor = $sell($products['MOTOR'], $customer['Rahima Akter'], $agent, '2026-08-01', 12_000_00, 1);
        $fire = $sell($products['FIRE'], $customer['Dhaka Garments Ltd'], $bdo, '2026-08-05', 48_000_00, 4);
        $marine = $sell($products['MARINE'], $customer['Chittagong Shipping Co'], $agent, '2026-08-12', 30_000_00, 1);
        $toCancel = $sell($products['FIRE'], $customer['Meghna Traders'], $bdo, '2026-08-20', 18_000_00, 1);
        $receive($motor, 12_000_00, '2026-08-03', 'TRF RAHIMA MOTOR');
        $receive($fire, 12_000_00, '2026-08-08', 'DGL FIRE Q1');
        $receive($marine, 30_000_00, '2026-08-14', 'CSC MARINE');

        $claims = app(ClaimService::class);
        $payments = app(ClaimPaymentService::class);
        $collision = $claims->register($motor, $day('2026-08-17'), 'Rear collision on the Dhaka–Mymensingh highway', $claimsOfficer, $day('2026-08-18'));
        $claims->reserve($collision->id, 200_000_00, 'Surveyor estimate', $claimsOfficer, $day('2026-08-19'));
        $payment = $payments->approve($collision->id, 180_000_00, $customer['Rahima Akter'], $claimsManager, $day('2026-08-25'));
        $payments->requestRelease($payment->id, $claimsManager, $this->bankAccountId);
        $payments->release($payment->id, $finance, $day('2026-08-27'));
        $claims->close($collision->id, 'Settled at 180,000', $claimsManager, $day('2026-08-28'));

        $this->importStatement("date,description,reference,amount\n2026-08-01,Balance brought forward,,2000000.00\n2026-08-04,Transfer,TRF RAHIMA MOTOR,12000.00\n"
            ."2026-08-08,Transfer,DGL FIRE Q1,12000.00\n2026-08-15,Transfer,CSC MARINE,30000.00\n2026-08-27,Claim payment,,-180000.00\n", 'city-bank-2026-08.csv', matchAll: true);
        $this->closeMonth('2026-08-01');

        // September (open): more business, suspense, a claim waiting, the statement to match.
        $september = $sell($products['MOTOR'], $customer['Karim Hossain'], $agent, '2026-09-01', 9_500_00, 1);
        $receive($september, 9_500_00, '2026-09-02', 'KARIM MOTOR');
        $unreferenced = $sell($products['FIRE'], $customer['Meghna Traders'], $bdo, '2026-09-03', 24_000_00, 2);
        $receipt = $receive(null, 12_000_00, '2026-09-04', null);
        app(SuspenseService::class)->allocate((string) DB::table('suspense_items')->where('receipt_id', $receipt)->value('id'),
            (string) DB::table('installments')->where('policy_id', $unreferenced)->orderBy('no')->value('id'), 12_000_00, $accountant, $day('2026-09-06'));
        $lifecycle->cancel($toCancel, $day('2026-09-05'), 'Customer sold the insured stock', $manager);
        $sell($products['MOTOR'], $customer['Rahima Akter'], $agent, '2026-09-08', 15_000_00, 1);
        $receive(null, 8_500_00, '2026-09-10', 'DEP 7781');
        $sell($products['MOTOR'], $customer['Karim Hossain'], $agent, '2026-09-20', 11_000_00, 1, issue: false);

        $cargo = $claims->register($marine, $day('2026-09-07'), 'Cargo wetted in transit to Chattogram port', $claimsOfficer, $day('2026-09-09'));
        $claims->reserve($cargo->id, 150_000_00, 'Surveyor estimate', $claimsOfficer, $day('2026-09-10'));

        $september = "date,description,reference,amount\n2026-09-03,Transfer,KARIM MOTOR,9500.00\n2026-09-05,Deposit,,12000.00\n2026-09-09,Bank charges,,-350.00\n"
            ."2026-09-11,Cash deposit,DEP 7781,8500.00\n2026-09-12,Transfer,TT 9921,52000.00\n";
        File::ensureDirectoryExists(dirname(storage_path(self::STATEMENT_FILE)));
        File::put(storage_path(self::STATEMENT_FILE), $september);
        $this->importStatement($september, basename(self::STATEMENT_FILE), matchAll: false);
        $this->postQueuedEvents();
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
            DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => 'tenant', 'scope_id' => $tenantId]);
            $users[$code] = $id;
        }

        return $users;
    }
}
