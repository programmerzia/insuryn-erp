<?php

declare(strict_types=1);

namespace App\Modules\Platform\Audit;

/** The business object an audit row is about: a type code (e.g. `journal`) and its id. */
final readonly class AuditSubject
{
    private function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function of(string $type, string $id): self
    {
        return new self($type, $id);
    }
}
