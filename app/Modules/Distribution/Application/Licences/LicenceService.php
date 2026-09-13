<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Licences;

use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Producer licences (Distribution design note §1, §3): record a licence (a renewal is a new licence), suspend or revoke one. */
final class LicenceService
{
    public const CLASSES = ['life', 'non_life', 'both'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ProducerDirectory $producers,
        private readonly Audit $audit,
    ) {}

    /** @return string the licence id */
    public function record(RecordLicence $licence, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, 'agent.manage');
        if (! in_array($licence->class, self::CLASSES, true)) {
            throw new BusinessRuleViolation('INVALID_LICENCE_CLASS', "Licence class {$licence->class} is not one of life, non_life or both.");
        }
        if ($licence->expiresOn->lessThan($licence->issuedOn)) {
            throw new BusinessRuleViolation('LICENCE_DATES_INVALID', 'A licence cannot expire before it is issued.');
        }
        $this->producers->get($licence->producerId);

        return DB::transaction(function () use ($licence, $actorUserId): string {
            $id = (string) Str::uuid7();
            DB::table('producer_licences')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'producer_id' => $licence->producerId, 'authority' => $licence->authority,
                'licence_no' => $licence->licenceNo, 'class' => $licence->class, 'issued_on' => $licence->issuedOn->toDateString(), 'expires_on' => $licence->expiresOn->toDateString(),
                'document_id' => $licence->documentId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('producer_licence.recorded', AuditSubject::of('producer', $licence->producerId), null,
                ['licence_id' => $id, 'authority' => $licence->authority, 'licence_no' => $licence->licenceNo, 'class' => $licence->class,
                    'issued_on' => $licence->issuedOn->toDateString(), 'expires_on' => $licence->expiresOn->toDateString()], null, 'agent.manage', Actor::user($actorUserId));

            return $id;
        });
    }

    public function suspend(string $licenceId, string $reason, string $actorUserId): void
    {
        $this->changeStatus($licenceId, 'suspended', $reason, $actorUserId);
    }

    public function revoke(string $licenceId, string $reason, string $actorUserId): void
    {
        $this->changeStatus($licenceId, 'revoked', $reason, $actorUserId);
    }

    public function reinstate(string $licenceId, string $reason, string $actorUserId): void
    {
        $this->changeStatus($licenceId, 'active', $reason, $actorUserId);
    }

    private function changeStatus(string $licenceId, string $status, string $reason, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'agent.manage');

        DB::transaction(function () use ($licenceId, $status, $reason, $actorUserId): void {
            $licence = DB::table('producer_licences')->where('id', $licenceId)->lockForUpdate()->first(['producer_id', 'status', 'licence_no']);
            if ($licence === null) {
                throw new \Illuminate\Database\RecordsNotFoundException("Licence {$licenceId} does not exist.");
            }
            if ($licence->status === 'revoked') {
                throw new BusinessRuleViolation('LICENCE_REVOKED', "Licence {$licence->licence_no} is revoked; record a new licence instead.");
            }
            DB::table('producer_licences')->where('id', $licenceId)->update(['status' => $status, 'status_reason' => $reason, 'updated_at' => now()]);
            $this->audit->record("producer_licence.{$status}", AuditSubject::of('producer', (string) $licence->producer_id), ['licence_id' => $licenceId, 'status' => (string) $licence->status],
                ['licence_id' => $licenceId, 'status' => $status], $reason, 'agent.manage', Actor::user($actorUserId));
        });
    }
}
