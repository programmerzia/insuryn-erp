<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Domain\PostingRule;

/** What ExternalEventValidator hands back: the rule the event will post under and the dimensions rewritten to what the kernel stores. */
final readonly class ValidatedExternalEvent
{
    /** @param array<string, mixed> $dimensions */
    public function __construct(public PostingRule $rule, public array $dimensions) {}
}
