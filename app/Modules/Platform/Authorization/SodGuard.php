<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Design §7.3 enforcement point (2): before `$actorUserId` exercises `$permission` on an object, look in
 * the object's audit history for the same person exercising a conflicting permission. Works even when
 * both permissions come from different roles, because it looks at what was done, not what is held.
 * Every maker action must therefore be audited with its permission (Audit::record).
 */
final class SodGuard
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @return list<SodViolation> warnings from warn-mode rules (each also audited as `sod.warning`)
     *
     * @throws SodViolation the first block-mode conflict
     */
    public function assert(string $actorUserId, string $permission, AuditSubject $object): array
    {
        $warnings = [];
        foreach ($this->rulesInvolving($permission) as $rule) {
            $conflicting = (string) $rule->conflictFor($permission);
            if (! $this->actorExercised($actorUserId, $conflicting, $object)) {
                continue;
            }
            $violation = new SodViolation('SOD_CONFLICT', $actorUserId, $permission, $conflicting, $rule->code,
                "Segregation of duties ({$rule->code}): the user who exercised {$conflicting} on {$object->type} {$object->id} cannot exercise {$permission} on it.");
            if ($rule->blocks()) {
                throw $violation;
            }
            $this->audit->record('sod.warning', $object, null, ['rule' => $rule->code, 'conflicting_permission' => $conflicting],
                $violation->getMessage(), $permission, Actor::user($actorUserId));
            $warnings[] = $violation;
        }

        return $warnings;
    }

    /** Read-only twin of assert() for offering a next step: true when a block-mode rule would refuse. Records nothing, not even warnings. */
    public function wouldBlock(string $actorUserId, string $permission, AuditSubject $object): bool
    {
        foreach ($this->rulesInvolving($permission) as $rule) {
            if ($rule->blocks() && $this->actorExercised($actorUserId, (string) $rule->conflictFor($permission), $object)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<SodRule> */
    public function rulesInvolving(string $permission): array
    {
        return array_values(array_filter($this->rules(), fn (SodRule $rule): bool => $rule->conflictFor($permission) !== null));
    }

    /** @return list<SodRule> */
    private function rules(): array
    {
        return array_values(DB::table('sod_rules')->orderBy('code')->get(['code', 'permission_a', 'permission_b', 'mode', 'applies_to'])
            ->map(fn (object $row): SodRule => new SodRule((string) $row->code, (string) $row->permission_a, (string) $row->permission_b, (string) $row->mode, (string) $row->applies_to))
            ->all());
    }

    private function actorExercised(string $actorUserId, string $permissionPattern, AuditSubject $object): bool
    {
        return DB::table('audit_events')
            ->where('object_type', $object->type)->where('object_id', $object->id)->where('actor_user_id', $actorUserId)
            ->where(fn (Builder $q) => str_ends_with($permissionPattern, '.*')
                ? $q->where('permission', 'like', substr($permissionPattern, 0, -1).'%')
                : $q->where('permission', $permissionPattern))
            ->where('action', '<>', 'sod.warning')
            ->exists();
    }
}
