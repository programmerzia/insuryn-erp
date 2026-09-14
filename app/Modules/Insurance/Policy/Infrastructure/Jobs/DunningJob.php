<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Infrastructure\Jobs;

use App\Modules\Insurance\Policy\Application\Dunning\DunningRun;
use App\Modules\Insurance\Policy\Application\Dunning\DunningRunResult;
use App\Modules\Platform\Jobs\RunsNightly;
use App\Modules\Platform\Tenancy\BusinessClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Spec §4 dunning and auto-lapse, nightly per tenant (D-07 loop) and entity, as of today. Gap fix GA-05: recorded per entity; finance can run it now. */
final class DunningJob implements ShouldQueue
{
    use Queueable, RunsNightly;

    public const KEY = 'dunning';

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(DunningRun $dunning): void
    {
        $this->eachTenant(function () use ($dunning): void {
            foreach (DB::table('legal_entities')->orderBy('code')->pluck('id') as $entityId) {
                // Slice 2.1b: each entity's own today (D-54).
                $this->logged(self::KEY, fn (): DunningRunResult => $dunning->run((string) $entityId, app(BusinessClock::class)->today((string) $entityId)), (string) $entityId);
            }
        });
    }
}
