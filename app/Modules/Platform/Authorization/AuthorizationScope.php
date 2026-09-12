<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

/**
 * Where an action happens (design §7.2 user_roles.scope): the entity and, when known, the branch.
 * A tenant role applies everywhere, an entity role within its entity and its branches, a branch role
 * only within that branch.
 */
final readonly class AuthorizationScope
{
    private function __construct(
        public ?string $entityId,
        public ?string $branchId,
    ) {}

    public static function tenant(): self
    {
        return new self(null, null);
    }

    public static function entity(string $entityId): self
    {
        return new self($entityId, null);
    }

    public static function branch(string $entityId, string $branchId): self
    {
        return new self($entityId, $branchId);
    }
}
