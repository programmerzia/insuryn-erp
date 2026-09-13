<?php

declare(strict_types=1);

namespace App\Http\Home;

use App\Http\Pages\PageSupport;
use App\Http\Setup\SetupWizard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /home: the signed-in user's work queues (UX brief §5). Every signed-in user may open it; a role without queues sees an empty home.
 * A tenant's first sign-in — no products and setup not finished — goes to the setup wizard instead (session S1) for anyone who can do a step.
 */
final class HomeController
{
    public function __invoke(Request $request, WorkQueues $queues, SetupWizard $setup): Response|RedirectResponse
    {
        if ($setup->shouldOpenFor(PageSupport::actor($request))) {
            return redirect('/setup');
        }

        return Inertia::render('home/Index', ['queues' => $queues->blocks(PageSupport::actor($request))]);
    }
}
