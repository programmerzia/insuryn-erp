<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

use App\Modules\Accounting\Application\Close\CloseTaskDefinition;

/**
 * Design addendum §B.2.8 (PD-8): close tasks a business context adds to the month-end checklist, only for the entities that use it (a company without
 * payroll gets no payroll tasks). Implementations are container-tagged with this interface. Contributed tasks run before the trial balance.
 */
interface CloseTaskContributor
{
    /** @return list<CloseTaskDefinition> every task the context can add (to find a task of an existing run by its code) */
    public function definitions(): array;

    /** Whether the entity's close run lists these tasks. */
    public function appliesTo(string $entityId): bool;
}
