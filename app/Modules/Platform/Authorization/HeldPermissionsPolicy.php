<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

use Illuminate\Support\Facades\DB;

/**
 * Design §7.3 enforcement point (1), for anything that changes what a user holds: being given a role, or a held role gaining permissions.
 * A user must not hold both sides of a user-level conflict (block-mode rules refuse, warn-mode rules are returned), and the auditor role never
 * combines with write permissions.
 */
final class HeldPermissionsPolicy
{
    public function __construct(private readonly SodGuard $sod) {}

    /**
     * @param list<string> $keeps permissions the user holds regardless of the change
     * @param list<string> $gains permissions the change adds
     * @return list<SodViolation> warnings from warn-mode rules
     *
     * @throws SodViolation
     */
    public function check(string $userId, string $roleCode, array $keeps, array $gains): array
    {
        $this->assertAuditorStaysReadOnly($userId, $roleCode, $keeps, $gains);

        return $this->userLevelConflicts($userId, $keeps, $gains);
    }

    /**
     * @param list<string> $keeps
     * @param list<string> $gains
     */
    private function assertAuditorStaysReadOnly(string $userId, string $roleCode, array $keeps, array $gains): void
    {
        $isAuditor = $roleCode === RoleTemplates::AUDITOR
            || DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $userId)->where('r.code', RoleTemplates::AUDITOR)->exists();
        if (! $isAuditor) {
            return;
        }
        $writes = array_values(array_diff([...$keeps, ...$gains], RoleTemplates::READ_ONLY_PERMISSIONS));
        if ($writes !== []) {
            throw new SodViolation('AUDITOR_WRITE_PERMISSION', $userId, $writes[0], 'audit.view', 'AUDITOR_READ_ONLY',
                "An auditor cannot also hold write permissions ({$writes[0]}).");
        }
    }

    /**
     * @param list<string> $keeps
     * @param list<string> $gains
     * @return list<SodViolation>
     */
    private function userLevelConflicts(string $userId, array $keeps, array $gains): array
    {
        $held = array_values(array_unique([...$keeps, ...$gains]));
        $warnings = [];
        foreach ($gains as $permission) {
            foreach ($this->sod->rulesInvolving($permission) as $rule) {
                if ($rule->appliesTo !== 'user') {
                    continue;
                }
                $conflictPattern = (string) $rule->conflictFor($permission);
                $conflicting = array_values(array_filter($held, fn (string $p): bool => $p !== $permission && SodRule::matches($conflictPattern, $p)));
                if ($conflicting === []) {
                    continue;
                }
                $violation = new SodViolation('SOD_CONFLICT', $userId, $permission, $conflicting[0], $rule->code,
                    "Segregation of duties ({$rule->code}): a user cannot hold both {$permission} and {$conflicting[0]}.");
                if ($rule->blocks()) {
                    throw $violation;
                }
                $warnings[] = $violation;
            }
        }

        return $warnings;
    }
}
