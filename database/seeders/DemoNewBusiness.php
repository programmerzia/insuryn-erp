<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Domain\Enums\ProposalStatus;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Phase 3 slice R7: the demo stories sell rated products the way a branch does — quotation (rated on the tariff), proposal with KYC, underwriting, policy issued
 * from the approved proposal — through the application services. A story is dated (Part A's August and September, the local demo's July to September), so each
 * sale runs with the clock set to its day: quotations must not start in the past, and proposals, underwriting and validity read today. The clock is put back
 * afterwards. Demo only; never used by the application.
 */
final class DemoNewBusiness
{
    /**
     * An issued quotation on $day; with $issue, also its proposal (KYC verified, submitted) and the policy issued the same day. Returns the policy id, or the
     * quotation id when not issuing. A proposal the rules refer stops the story with the reasons: demo risks are chosen to be approved automatically.
     *
     * @param array<string, mixed> $risk
     * @param list<string> $coverages optional coverages
     */
    public static function sell(string $branchId, string $productId, string $customerId, ?string $producerId, string $day, array $risk, string $sellerId,
        int $installments = 1, bool $issue = true, array $coverages = []): string
    {
        return self::on($day, function () use ($branchId, $productId, $customerId, $producerId, $day, $risk, $sellerId, $installments, $issue, $coverages): string {
            $today = CarbonImmutable::parse($day);
            $quotations = app(QuotationService::class);
            $quotation = $quotations->issue($quotations->saveDraft(new QuotationTerms($branchId, $productId, $customerId, $producerId, $today, $risk, $coverages), null, $sellerId)->id,
                $today, $sellerId);
            if (! $issue) {
                return $quotation->id;
            }
            $proposals = app(ProposalService::class);
            $proposal = $proposals->createFromQuotation($quotation->id, $sellerId);
            $proposals->verifyKyc($proposal->id, 'nid', '1990000000000', $sellerId); // demo identity document number
            $submitted = $proposals->submit($proposal->id, $sellerId);
            if ($submitted->status !== ProposalStatus::Approved) {
                throw new RuntimeException("Demo proposal {$submitted->number} was referred: ".json_encode($submitted->referral_reasons));
            }

            return app(PolicyLifecycle::class)->issueFromProposal($proposal->id, $today, $sellerId, $installments)->id;
        });
    }

    /**
     * Runs $fn with the clock on $day at 10:00, then restores the clock as it was.
     *
     * @template T
     *
     * @param callable(): T $fn
     * @return T
     */
    public static function on(string $day, callable $fn): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse("{$day} 10:00"));
        try {
            return $fn();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
