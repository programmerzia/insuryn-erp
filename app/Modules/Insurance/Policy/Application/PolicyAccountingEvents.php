<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Accounting Event Mapper for policies (design §3.1, §8.2): turns a policy transaction into the kernel's accounting event
 * inside the same database transaction. Idempotency keys follow the rules: <EVENT>:{policy_transaction_id}.
 */
final class PolicyAccountingEvents
{
    public function __construct(private readonly SubmitAccountingEvent $submit) {}

    /** Design §4.1. */
    public function issued(Policy $policy, PolicyTransaction $transaction, CarbonImmutable $transactionDate): void
    {
        $this->submitFor($policy, $transaction, 'POLICY_ISSUED', $transactionDate, $policy->inception, [
            'gross_premium' => $transaction->premium_delta_minor, 'net_premium' => $transaction->net_delta_minor, 'tax' => $transaction->tax_delta_minor,
        ]);
    }

    /** Design §5.4 endorse (delta). */
    public function endorsed(Policy $policy, PolicyTransaction $transaction): void
    {
        $this->submitFor($policy, $transaction, 'POLICY_ENDORSED', $transaction->effective_date, $transaction->effective_date, [
            'gross_premium_delta' => $transaction->premium_delta_minor, 'net_premium_delta' => $transaction->net_delta_minor, 'tax_delta' => $transaction->tax_delta_minor,
        ]);
    }

    /**
     * Design §4.4 event A.
     *
     * @param array{earned_to_date: int, unearned_remaining: int, tax_reversal: int, receivable_outstanding: int, refund_due: int} $amounts
     */
    public function cancelled(Policy $policy, PolicyTransaction $transaction, array $amounts): void
    {
        $this->submitFor($policy, $transaction, 'POLICY_CANCELLED', $transaction->effective_date, $transaction->effective_date, [
            'unearned_remaining' => $amounts['unearned_remaining'], 'tax_reversal' => $amounts['tax_reversal'],
            'receivable_outstanding' => $amounts['receivable_outstanding'], 'refund_due' => $amounts['refund_due'],
        ]);
    }

    /**
     * Event dimensions for everything posted about this policy (design §4 dims B, P, POL, CU, AG).
     *
     * @return array<string, string>
     */
    public static function dimensions(Policy $policy): array
    {
        $product = DB::table('products')->where('id', $policy->product_id)->first(['code', 'lob']);

        return array_filter([
            'branch' => $policy->branch_id, 'product' => $policy->product_id, 'product_code' => (string) $product?->code, 'lob' => (string) $product?->lob,
            'channel' => $policy->channel, 'policy' => $policy->id, 'customer' => $policy->policyholder_party_id, 'agent' => $policy->agent_id,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, int> $payload */
    private function submitFor(Policy $policy, PolicyTransaction $transaction, string $eventType, CarbonImmutable $transactionDate, CarbonImmutable $effectiveDate, array $payload): void
    {
        ($this->submit)(
            entityId: $policy->entity_id, eventType: $eventType, sourceType: 'policy_transaction', sourceId: $transaction->id,
            idempotencyKey: $eventType.':'.$transaction->id, transactionDate: $transactionDate, effectiveDate: $effectiveDate,
            currency: $policy->currency, payload: $payload,
            dimensions: self::dimensions($policy), sourceVersion: $transaction->policy_version,
        );
    }
}
