<?php

declare(strict_types=1);

namespace App\Http\Close;

use App\Http\Pages\PageSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Gap fix GA-05: "Run now" on the close screen's nightly jobs panel (NightlyJobs). */
final class NightlyJobsController
{
    public function run(Request $request, string $job, NightlyJobs $jobs): RedirectResponse
    {
        $summary = $jobs->runNow($job, PageSupport::actor($request));
        $done = implode(', ', array_map(fn (string $key, int|string $value): string => $value.' '.str_replace('_', ' ', $key), array_keys($summary), $summary));

        return back()->with('status', 'Ran now'.($done === '' ? '.' : ": {$done}."));
    }
}
