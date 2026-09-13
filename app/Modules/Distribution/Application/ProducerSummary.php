<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application;

/** What other contexts may know about a producer (read-only contract, design §1 "read-only query contracts"). */
final readonly class ProducerSummary
{
    public function __construct(
        public string $id,
        public string $partyId,
        public string $code,
        public string $type,
        public string $status,
        public string $channelId,
        public string $branchId,
        public ?string $parentProducerId,
        public ?string $commissionPlanId,
        public ?string $joinedOn,
        public ?string $employeeId = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public static function fromRow(\stdClass $row): self
    {
        return new self((string) $row->id, (string) $row->party_id, (string) $row->code, (string) $row->type, (string) $row->status, (string) $row->channel_id,
            (string) $row->branch_id, $row->parent_producer_id === null ? null : (string) $row->parent_producer_id,
            $row->commission_plan_id === null ? null : (string) $row->commission_plan_id, $row->joined_on === null ? null : substr((string) $row->joined_on, 0, 10),
            $row->employee_id === null ? null : (string) $row->employee_id);
    }
}
