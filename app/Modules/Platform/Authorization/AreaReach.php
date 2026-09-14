<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authorization;

use Illuminate\Database\Query\Builder;

/**
 * Where a user holds any of a screen area's permissions (design §7.2: `user_roles.scope` restricts to an entity or branch, and branch users never see other
 * branches): everywhere (a tenant role), in whole entities, or in single branches. Screens open when the reach is not empty; lists and pickers are limited
 * to it with `constrain`, and a record page checks the record's own branch (PermissionChecker::authorizeAny with its scope).
 */
final readonly class AreaReach
{
    /**
     * @param list<string> $entityIds
     * @param list<string> $branchIds
     */
    public function __construct(
        public bool $tenantWide,
        public array $entityIds,
        public array $branchIds,
    ) {}

    /** A tenant-wide reach: for screens that still open tenant-wide only. */
    public static function everywhere(): self
    {
        return new self(true, [], []);
    }

    public function isEmpty(): bool
    {
        return ! $this->tenantWide && $this->entityIds === [] && $this->branchIds === [];
    }

    /** Limits $query to rows whose entity or branch is within reach; unchanged for a tenant-wide reach. */
    public function constrain(Builder $query, string $entityColumn, string $branchColumn): Builder
    {
        if ($this->tenantWide) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereIn($entityColumn, $this->entityIds)->orWhereIn($branchColumn, $this->branchIds));
    }
}
