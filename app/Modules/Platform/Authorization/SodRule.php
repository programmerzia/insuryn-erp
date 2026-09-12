<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

/**
 * A segregation-of-duties conflict (design §7.3, table sod_rules). A permission ending in `.*` matches
 * every permission of that context. `appliesTo`: `user` — nobody may hold both (checked at role
 * assignment and per object); `object` — one person may hold both but never exercise both on the same
 * object (e.g. claim.reserve ✕ claim.approve on the same claim).
 */
final readonly class SodRule
{
    public function __construct(
        public string $code,
        public string $permissionA,
        public string $permissionB,
        public string $mode,
        public string $appliesTo,
    ) {}

    public function blocks(): bool
    {
        return $this->mode === 'block';
    }

    /** The permission on the other side of the conflict from $permission, or null when this rule does not involve it. */
    public function conflictFor(string $permission): ?string
    {
        return match (true) {
            self::matches($this->permissionA, $permission) => $this->permissionB,
            self::matches($this->permissionB, $permission) => $this->permissionA,
            default => null,
        };
    }

    public static function matches(string $pattern, string $permission): bool
    {
        return str_ends_with($pattern, '.*')
            ? str_starts_with($permission, substr($pattern, 0, -1))
            : $pattern === $permission;
    }
}
