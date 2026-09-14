<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

/**
 * One document that holds a period's lock (slice 2.1b, D-55): what it is, its business date in the period, where it stands and what clears it.
 * `movable` is true for a manual journal pending approval, which its approver may move to the next open period.
 */
final readonly class PendingCloseDocument
{
    public function __construct(
        /** manual_journal | journal_reversal | claim_payment | refund | accounting_event */
        public string $type,
        public string $id,
        public string $label,
        public string $date,
        public string $status,
        public string $clearedBy,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $link = null,
        public bool $movable = false,
    ) {}

    /** @return array{type: string, id: string, label: string, date: string, status: string, cleared_by: string, amount_minor: int|null, currency: string|null, link: string|null, movable: bool} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'label' => $this->label, 'date' => $this->date, 'status' => $this->status, 'cleared_by' => $this->clearedBy,
            'amount_minor' => $this->amountMinor, 'currency' => $this->currency, 'link' => $this->link, 'movable' => $this->movable];
    }
}
