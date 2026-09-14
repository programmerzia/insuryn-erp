<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

/**
 * Gap fix GA-04: optionally implemented by an ApprovalHandler so the approvals inbox shows what the approver decides on before approving — facts about
 * the object (`details`; a `date` value is a Y-m-d business date), what its link opens (`link_label`) and, when the final approval posts to the ledger,
 * the journal lines it posts (`lines`, amounts formatted). Read only for the approvals screen, not for the inbox counts on every page.
 */
interface PreviewsApprovalSubject
{
    /**
     * @return array{link_label: string, details: list<array{label: string, value: string, date?: bool}>,
     *     lines: list<array{account: string, name: string, debit: string|null, credit: string|null}>, posts_on_final_step: bool}
     */
    public function preview(string $objectId): array;
}
