<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Reinsurance\Application\CessionEngine;
use App\Modules\Insurance\Reinsurance\Application\FacultativeService;
use App\Modules\Insurance\Reinsurance\Application\ReinsurerStatements;
use App\Modules\Insurance\Reinsurance\Application\TreatyService;
use App\Modules\Insurance\Reinsurance\Domain\RiMath;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingDecisions;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use App\Modules\Insurance\Underwriting\Domain\Enums\ProposalStatus;
use App\Modules\Platform\Approvals\Decision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reinsurance MVP (G4) in the Part A demo (erp:demo). ASSUMPTION A-251…A-256: every figure and name is a demo placeholder, to verify.
 *
 * - setUp (before the first sale): Sadharan Bima Corporation as the state reinsurer, two foreign reinsurers (placeholder names), and FY2026 treaties
 *   (1 July 2026 – 30 June 2027) with the SBC share of 50%: motor quota share 40% (commission 25%), fire surplus with a retention of 5 crore and 9 lines
 *   (commission 30%), marine cargo quota share 50% (commission 27.5%). Every policy the story sells is then ceded as it is issued, and the claims follow.
 * - finish (end of the story): a 150 crore garment factory in Gazipur, referred to and approved by the CFO, whose 25 crore above the fire treaty's capacity is
 *   placed facultatively; the engine run over the story's policies (backfill: nothing is left uncovered); the Q3 2026 statements for SBC and Global Re.
 */
final class ReinsuranceDemoSeeder
{
    /** @param array<string, string> $users role code → user id */
    public static function setUp(string $entityId, array $users): void
    {
        $finance = $users['finance_manager'];
        $treaties = app(TreatyService::class);
        $treaties->registerReinsurer('Sadharan Bima Corporation', 'SBC', null, null, 'BD', true, $finance);
        $globalRe = $treaties->registerReinsurer('Global Re (placeholder)', 'GLOBALRE', 'AA-', 'S&P', 'CH', false, $finance);
        $asiaRe = $treaties->registerReinsurer('Asia Pacific Re (placeholder)', 'ASIAPACRE', 'A', 'AM Best', 'SG', false, $finance);
        $terms = fn (string $code, string $name, string $class, string $type, ?int $cessionBp, ?int $retention, ?int $lines, int $commissionBp): array => [
            'entity_id' => $entityId, 'code' => $code, 'name' => $name, 'class_code' => $class, 'underwriting_year' => 2026, 'period_from' => '2026-07-01', 'period_to' => '2027-06-30',
            'type' => $type, 'cession_bp' => $cessionBp, 'retention_minor' => $retention, 'lines' => $lines, 'commission_bp' => $commissionBp,
            'sbc_share_bp' => (int) config('erp.reinsurance.sbc_share_bp', 5000), 'currency' => 'BDT', 'status' => 'active'];
        $treaties->save(null, $terms('MOT-QS-FY26', 'Motor quota share FY2026', 'motor', 'quota_share', 4000, null, null, 2500), [$globalRe => 6000, $asiaRe => 4000], $finance);
        $treaties->save(null, $terms('FIRE-SUR-FY26', 'Fire surplus FY2026', 'fire', 'surplus', null, 5_000_000_000, 9, 3000), [$globalRe => 5000, $asiaRe => 5000], $finance);
        $treaties->save(null, $terms('MAR-QS-FY26', 'Marine cargo quota share FY2026', 'marine_cargo', 'quota_share', 5000, null, null, 2750), [$globalRe => 10_000], $finance);
    }

    /** @param array<string, string> $users role code → user id */
    public static function finish(string $entityId, string $branchId, string $fireProductId, array $users): void
    {
        $adminId = (string) DB::table('users')->where('email', 'like', 'admin@%')->orderBy('created_at')->value('id');
        [$officer, $finance, $cfo] = [$users['branch_officer'], $users['finance_manager'], $users['cfo']];
        $policyId = DemoNewBusiness::on('2026-09-09', function () use ($branchId, $fireProductId, $officer, $cfo, $adminId): string {
            $today = CarbonImmutable::parse('2026-09-09');
            // The CFO's fire underwriting limit raised to 200 crore for large industrial risks (placeholder, verify).
            app(UnderwritingLimits::class)->set('cfo', 'fire', 2_000_000_000_00, $today, $adminId);
            $customer = app(PartyService::class)->create(PartyKind::Organization, 'Meghna Knit Composite Ltd', null, [PartyRoleType::Customer, PartyRoleType::Policyholder], $officer)->id;
            $quotations = app(QuotationService::class);
            $quotation = $quotations->issue($quotations->saveDraft(new QuotationTerms($branchId, $fireProductId, $customer, null, $today,
                ['occupancy' => 'factory', 'construction_class' => 'class_1', 'address' => 'Plot 7, BSCIC Industrial Estate, Konabari, Gazipur', 'sum_insured' => 1_500_000_000_00]), null, $officer)->id, $today, $officer);
            $proposals = app(ProposalService::class);
            $proposal = $proposals->createFromQuotation($quotation->id, $officer);
            $proposals->verifyKyc($proposal->id, 'trade_licence', 'TRAD-GAZ-2021-778812', $officer); // demo trade licence number
            if ($proposals->submit($proposal->id, $officer)->status !== ProposalStatus::Approved) {
                app(UnderwritingDecisions::class)->decide($proposal->id, Decision::Approved, null, 'Large industrial risk: surplus treaty and facultative placement arranged', $cfo);
            }

            return app(PolicyLifecycle::class)->issueFromProposal($proposal->id, $today, $officer, 4)->id;
        });
        $position = DB::table('ri_policy_positions')->where('policy_id', $policyId)->first(['sum_insured_minor', 'net_premium_minor', 'above_capacity_minor']);
        if ($position === null || (int) $position->above_capacity_minor <= 0) {
            throw new RuntimeException('Demo: the large fire risk was expected above the fire treaty capacity.');
        }
        $above = (int) $position->above_capacity_minor;
        $asiaRe = (string) DB::table('reinsurers')->where('code', 'ASIAPACRE')->value('id');
        app(FacultativeService::class)->place($policyId, $asiaRe, RiMath::shareBp($above, (int) $position->sum_insured_minor), $above,
            RiMath::ratio((int) $position->net_premium_minor, $above, (int) $position->sum_insured_minor), 2000, 'FAC/APR/2026/0917', CarbonImmutable::parse('2026-09-11'), $finance);

        app(CessionEngine::class)->backfill($entityId, CarbonImmutable::parse('2026-09-12'));
        foreach (['SBC', 'GLOBALRE'] as $code) {
            app(ReinsurerStatements::class)->prepare($entityId, (string) DB::table('reinsurers')->where('code', $code)->value('id'), 2026, 3, $finance);
        }
    }
}
