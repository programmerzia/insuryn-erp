<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Insurance\Commission\Domain\Models\CommissionEntry;
use App\Modules\Insurance\Policy\Application\PolicyAccountingEvents;
use App\Modules\Insurance\Policy\Domain\Models\Policy;

/** Accounting Event Mapper for commission (design §4.5, §4.4 event C), called inside the source transaction. */
final class CommissionAccountingEvents
{
    public function __construct(private readonly SubmitAccountingEvent $submit) {}

    /** The rule recomputes amount and withholding from base and rates with the same half-even rounding as the entry. */
    public function earned(CommissionEntry $entry, Policy $policy, int $withholdingBp): void
    {
        $this->submitFor($entry, $policy, 'COMMISSION_EARNED', ['base' => $entry->base_minor, 'rate_bp' => (int) $entry->rate_bp, 'withholding_bp' => $withholdingBp]);
    }

    public function clawedBack(CommissionEntry $entry, Policy $policy): void
    {
        $this->submitFor($entry, $policy, 'COMMISSION_CLAWBACK', ['amount' => -$entry->amount_minor, 'withholding' => -$entry->withholding_minor]);
    }

    /** @param array<string, int> $payload */
    private function submitFor(CommissionEntry $entry, Policy $policy, string $eventType, array $payload): void
    {
        ($this->submit)(
            entityId: $entry->entity_id, eventType: $eventType, sourceType: 'commission_entry', sourceId: $entry->id,
            idempotencyKey: $eventType.':'.$entry->id, transactionDate: $entry->earned_on, effectiveDate: $entry->earned_on,
            currency: $entry->currency, payload: $payload + ['commission_entry_id' => $entry->id],
            dimensions: PolicyAccountingEvents::dimensions($policy),
        );
    }
}
