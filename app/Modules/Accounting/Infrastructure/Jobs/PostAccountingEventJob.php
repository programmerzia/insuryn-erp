<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Jobs;

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Platform\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PostAccountingEventJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable, InteractsWithQueue, SerializesModels, TenantAware;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300, 300];
    }

    public function __construct(string $tenantId, public readonly string $eventId)
    {
        $this->tenantId = $tenantId;
    }

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(PostingEngine $engine): void
    {
        $this->withTenant(fn () => $engine->post($this->eventId));
    }
}
