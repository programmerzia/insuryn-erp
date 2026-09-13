<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

/**
 * Optionally implemented by an ApprovalHandler so the approvals inbox can show what is being approved without Platform knowing the modules:
 * a title, the amount in minor units (null when not monetary) with its currency, and the page to open.
 */
interface DescribesApprovalSubject
{
    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    public function describe(string $objectId): array;
}
