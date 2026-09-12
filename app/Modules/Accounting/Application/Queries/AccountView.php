<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Queries;

/** A chart-of-accounts entry as business modules may see it. */
final readonly class AccountView
{
    public function __construct(
        public string $id,
        public string $entityId,
        public string $code,
        public string $name,
        public string $type,
        public bool $isPostable,
        public bool $isControl,
        public ?string $currency,
        public string $status,
    ) {}

    public function acceptsPostings(): bool
    {
        return $this->isPostable && $this->status === 'active';
    }
}
