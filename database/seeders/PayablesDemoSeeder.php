<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Finance\Bank\Application\BankMatcher;
use App\Modules\Finance\Bank\Application\StatementImport;
use App\Modules\Finance\Payables\Application\BankPaymentFile;
use App\Modules\Finance\Payables\Application\BillService;
use App\Modules\Finance\Payables\Application\PaymentRunService;
use App\Modules\Finance\Payables\Application\SupplierService;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Slices 2.3/2.4 accounts payable in the Part A story (September, Padma General Insurance, Head Office): six Dhaka suppliers — the office landlord, DESCO,
 * Grameenphone, a Motijheel garage used by claims, a surveyor firm and a stationery supplier — with their bills entered by the accountant and approved by
 * the finance manager (office rent 85,000; a garage bill on the motor collision claim, a survey bill on the cargo claim; a stationery bill still waiting
 * for approval), one payment run prepared, approved and released by the CFO with its BEFTN file and its bank statement line matched, and one run
 * approved and waiting for the CFO's release.
 *
 * ASSUMPTION A-248 (demo placeholders): supplier names, TINs, BINs, routing and account numbers are invented for the demo; tax rates are A-243 placeholders.
 */
final class PayablesDemoSeeder
{
    /** @var array{0: string, 1: string, 2: string} branch, accountant, finance manager */
    private array $context = ['', '', ''];

    /**
     * @param array<string, string> $users role code → user id (accountant, finance_manager, cfo)
     */
    public function run(string $entityId, string $branchId, string $bankAccountId, array $users): void
    {
        $day = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
        [$accountant, $finance, $cfo] = [$users['accountant'], $users['finance_manager'], $users['cfo']];

        $account = function (string $code, string $name) use ($entityId): string {
            $existing = DB::table('accounts')->where('entity_id', $entityId)->where('code', $code)->value('id');
            if (is_string($existing)) {
                return $existing;
            }
            $id = (string) Str::uuid7();
            DB::table('accounts')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'code' => $code, 'name' => $name, 'type' => 'expense',
                'normal_side' => 'debit', 'is_postable' => true, 'is_control' => false, 'control_subledger' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        };
        $accounts = ['rent' => $account('5400', 'Office Rent'), 'utility' => $account('5410', 'Electricity and Utilities'), 'telecom' => $account('5420', 'Telephone and Internet'),
            'supplies' => $account('5500', 'Printing and Stationery'), 'claims' => $account('5110', 'Claim Survey and Assessment Fees')];

        $suppliers = app(SupplierService::class);
        $supplier = fn (string $code, string $name, string $category, int $terms, string $default, string $tin, ?string $bin, string $bank, string $branch, string $routing, string $number, string $mobile, string $address)
            => $suppliers->create($entityId, ['code' => $code, 'name' => $name, 'category' => $category, 'payment_terms_days' => $terms, 'tin' => $tin, 'bin' => $bin, 'vat_registered' => $bin !== null,
                'default_account_id' => $default, 'bank_name' => $bank, 'bank_branch' => $branch, 'routing_no' => $routing, 'account_name' => $name, 'account_no' => $number,
                'mobile' => $mobile, 'address' => $address], $accountant);
        $landlord = $supplier('SUP-GTP', 'Gulshan Tower Properties Ltd', 'rent', 5, $accounts['rent'], '482915730016', '000482915-0101', 'Dutch-Bangla Bank', 'Gulshan', '090261726', '1051100048291', '+8801711482915', 'Gulshan Tower, 31 Gulshan Avenue, Dhaka 1212');
        $desco = $supplier('SUP-DESCO', 'Dhaka Electric Supply Company Ltd (DESCO)', 'utility', 15, $accounts['utility'], '100231456789', '000100231-0202', 'Sonali Bank', 'Local Office, Motijheel', '200274330', '0002634012345', '+8801713001122', '22/B Faruque Sarani, Nikunja-2, Dhaka 1229');
        $gp = $supplier('SUP-GP', 'Grameenphone Ltd', 'telecom', 15, $accounts['telecom'], '100256789012', '000100256-0101', 'Standard Chartered Bank', 'Gulshan', '215261726', '01125678901', '+8801711000121', 'GP House, Bashundhara, Baridhara, Dhaka 1229');
        $garage = $supplier('SUP-MAW', 'Motijheel Auto Works', 'repairs', 7, $accounts['claims'], '317260948351', null, 'Islami Bank Bangladesh', 'Motijheel', '125274245', '20501770100345', '+8801819274245', '41 Dilkusha C/A, Motijheel, Dhaka 1000');
        $surveyor = $supplier('SUP-BSL', 'Bengal Surveyors and Loss Assessors Ltd', 'professional', 15, $accounts['claims'], '529381764205', '000529381-0303', 'BRAC Bank', 'Motijheel', '060274030', '1501203456789001', '+8801730274030', '9 Rajuk Avenue, Motijheel, Dhaka 1000');
        $stationery = $supplier('SUP-NSH', 'Nilkhet Stationery House', 'supplies', 10, $accounts['supplies'], '618274950312', null, 'Pubali Bank', 'Nilkhet', '175270353', '3521901012345', '+8801915270353', '12 Nilkhet Road, Dhaka 1205');

        $claim = fn (string $description): ?string => ($id = DB::table('claims')->where('description', $description)->value('id')) === null ? null : (string) $id;
        $collision = $claim('Rear collision on the Dhaka–Mymensingh highway');
        $cargo = $claim('Cargo wetted in transit to Chattogram port');
        $policyOf = fn (?string $claimId): ?string => $claimId === null ? null : (string) DB::table('claims')->where('id', $claimId)->value('policy_id');

        $bills = app(BillService::class);
        $this->context = [$branchId, $accountant, $finance];
        $rent = $this->bill($landlord->id, 'GTP/RENT/2026-09', '2026-09-01', 'September office rent, Head Office (Gulshan Tower, 7th floor)',
            [['description' => 'Office rent, September 2026', 'account_id' => $accounts['rent'], 'net_minor' => 85_000_00]]);
        $power = $this->bill($desco->id, 'DESCO-0927-4471', '2026-09-03', 'Electricity, August meter reading',
            [['description' => 'Electricity, account 4471-0927', 'account_id' => $accounts['utility'], 'net_minor' => 18_450_00]]);
        $phone = $this->bill($gp->id, 'GP-CORP-778812', '2026-09-05', 'Corporate mobile and internet, August',
            [['description' => 'Corporate mobile plans (24 SIMs)', 'account_id' => $accounts['telecom'], 'net_minor' => 8_400_00],
                ['description' => 'Dedicated internet 50 Mbps', 'account_id' => $accounts['telecom'], 'net_minor' => 3_600_00]]);
        $garageBill = $this->bill($garage->id, 'MAW-2026-0912', '2026-09-09', 'Towing and damage assessment for the motor collision claim',
            [['description' => 'Towing from the Dhaka–Mymensingh highway', 'account_id' => $accounts['claims'], 'net_minor' => 4_500_00, 'claim_id' => $collision, 'policy_id' => $policyOf($collision)],
                ['description' => 'Damage assessment and repair estimate', 'account_id' => $accounts['claims'], 'net_minor' => 7_500_00, 'claim_id' => $collision, 'policy_id' => $policyOf($collision)]]);
        $surveyBill = $this->bill($surveyor->id, 'BSL/SV/2026/118', '2026-09-10', 'Marine cargo survey, Chattogram port',
            [['description' => 'Survey of wetted cotton yarn cargo', 'account_id' => $accounts['claims'], 'net_minor' => 25_000_00, 'claim_id' => $cargo, 'policy_id' => $policyOf($cargo)]]);
        $this->bill($stationery->id, 'NSH-3321', '2026-09-11', 'Printed policy stationery and toner',
            [['description' => 'Policy schedule paper and envelopes', 'account_id' => $accounts['supplies'], 'net_minor' => 4_200_00],
                ['description' => 'Toner cartridges', 'account_id' => $accounts['supplies'], 'net_minor' => 2_300_00]], approve: false);
        $this->postQueuedEvents();

        // Payment run 1: rent, electricity and phone — prepared by the accountant, approved by the finance manager, released by the CFO; BEFTN file and bank line.
        $runs = app(PaymentRunService::class);
        $released = $runs->create($entityId, $bankAccountId, $day('2026-09-08'), [$rent->id, $power->id, $phone->id], $accountant);
        $runs->submit($released->id, $accountant);
        $runs->approve($released->id, $finance);
        $released = $runs->release($released->id, $cfo);
        app(BankPaymentFile::class)->generate($released->id, $cfo);
        $this->postQueuedEvents();

        // Payment run 2: the garage and surveyor bills — approved, waiting for the CFO to release.
        $waiting = $runs->create($entityId, $bankAccountId, $day('2026-09-14'), [$garageBill->id, $surveyBill->id], $accountant);
        $runs->submit($waiting->id, $accountant);
        $runs->approve($waiting->id, $finance);

        // The bank debited the released run as one BEFTN batch: its statement line matched to the run's bank credit.
        $major = intdiv($released->total_minor, 100).'.'.str_pad((string) ($released->total_minor % 100), 2, '0', STR_PAD_LEFT);
        app(StatementImport::class)->import($bankAccountId, "date,description,reference,amount\n2026-09-08,BEFTN supplier payments,{$released->number},-{$major}\n", 'city-bank-2026-09-beftn.csv', $accountant);
        $line = (string) DB::table('bank_statement_lines')->where('bank_account_id', $bankAccountId)->where('reference', $released->number)->value('id');
        $journalLine = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.source_type', 'payment_run')->where('j.source_id', $released->id)
            ->where('l.role_code', 'bank_main')->value('l.id');
        if ($journalLine === null) {
            throw new RuntimeException('Demo payment run did not post to the bank.');
        }
        app(BankMatcher::class)->match($line, [(string) $journalLine], $accountant);
    }

    /** @param list<array{description: string, account_id: string, net_minor: int, vat_minor?: int|null, claim_id?: string|null, policy_id?: string|null}> $lines */
    private function bill(string $supplierId, string $reference, string $date, string $description, array $lines, bool $approve = true): \App\Modules\Finance\Payables\Domain\Models\ApBill
    {
        [$branchId, $maker, $approver] = $this->context;
        $bills = app(BillService::class);
        $submitted = $bills->submit($bills->create($branchId, $supplierId, $reference, CarbonImmutable::parse($date), null, $description, $lines, $maker)->id, $maker);

        return $approve ? $bills->approve($submitted->id, $approver) : $submitted;
    }

    private function postQueuedEvents(): void
    {
        foreach (DB::table('accounting_events')->where('status', 'queued')->orderBy('created_at')->orderBy('id')->pluck('id') as $eventId) {
            app(PostingEngine::class)->post((string) $eventId);
        }
        $failed = DB::table('accounting_events')->where('status', 'failed')->get(['event_type', 'failure_reason']);
        if ($failed->isNotEmpty()) {
            throw new RuntimeException('Payables demo events failed: '.$failed->map(fn (object $e): string => "{$e->event_type} ({$e->failure_reason})")->implode(', '));
        }
    }
}
