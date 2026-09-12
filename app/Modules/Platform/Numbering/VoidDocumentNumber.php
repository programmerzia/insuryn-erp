<?php

declare(strict_types=1);

namespace App\Modules\Platform\Numbering;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Support\Facades\DB;

/** Design §2.1: manual void of a document number requires `numbering.void` and a reason, and is audited. */
final class VoidDocumentNumber
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly DocumentNumberer $numberer,
        private readonly Audit $audit,
    ) {}

    public function __invoke(string $documentNumberId, string $reason, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'numbering.void');

        DB::transaction(function () use ($documentNumberId, $reason, $actorUserId): void {
            $this->numberer->void($documentNumberId, $reason, $actorUserId);
            $this->audit->record('document_number.voided', AuditSubject::of('document_number', $documentNumberId),
                ['status' => 'reserved'], ['status' => 'voided'], trim($reason), 'numbering.void', Actor::user($actorUserId));
        });
    }
}
