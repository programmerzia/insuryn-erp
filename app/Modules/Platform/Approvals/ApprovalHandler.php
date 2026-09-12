<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

/**
 * Completes the business action behind an approval. The module that owns the object type registers
 * its handler with ApprovalHandlerRegistry, so Platform never depends on the modules above it.
 */
interface ApprovalHandler
{
    /** @param array<string, mixed> $context the request context plus `requested_by` */
    public function approved(string $objectId, string $finalApproverId, array $context): void;

    /** @param array<string, mixed> $context the request context plus `requested_by` */
    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void;
}
