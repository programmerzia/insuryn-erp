<?php

declare(strict_types=1);

namespace App\Modules\Insurance\CoverNote\Infrastructure\Jobs;

use App\Modules\Insurance\CoverNote\Application\CoverNoteService;
use App\Modules\Platform\Jobs\RunsNightly;
use App\Modules\Platform\Tenancy\BusinessClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Slice R6: active cover notes past their last day expire, nightly per tenant (D-07 loop). Gap fix GA-05: recorded; finance can run it now. */
final class CoverNoteExpiryJob implements ShouldQueue
{
    use Queueable, RunsNightly;

    public const KEY = 'cover_note_expiry';

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(CoverNoteService $coverNotes): void
    {
        // Slice 2.1b: the company's today, read inside the tenant (D-54).
        $this->eachTenant(fn (): int => $this->logged(self::KEY, fn (): int => $coverNotes->expireDue(app(BusinessClock::class)->today())));
    }
}
