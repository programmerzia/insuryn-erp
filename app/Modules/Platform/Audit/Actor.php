<?php

declare(strict_types=1);

namespace App\Modules\Platform\Audit;

/** Who performed an audited action (audit_events.actor_type: user | system | integration). */
final readonly class Actor
{
    private function __construct(
        public ?string $userId,
        public string $type,
    ) {}

    public static function user(string $userId): self
    {
        return new self($userId, 'user');
    }

    public static function system(): self
    {
        return new self(null, 'system');
    }
}
