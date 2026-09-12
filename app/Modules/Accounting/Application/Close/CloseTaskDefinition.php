<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Close;

/** One row of design §5.7's task table. */
final readonly class CloseTaskDefinition
{
    /** @param list<string> $dependsOn task codes that must be done or skipped first */
    public function __construct(
        public int $orderNo,
        public string $code,
        public CloseTaskKind $kind,
        public array $dependsOn,
        public string $ownerRole,
        public string $permission,
        public bool $skippable = false,
        public ?string $subledger = null,
    ) {}
}
