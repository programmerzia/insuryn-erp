<?php

declare(strict_types=1);

namespace App\Http\Home;

use App\Http\Pages\PageSupport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** GET /home: the signed-in user's work queues (UX brief §5). Every signed-in user may open it; a role without queues sees an empty home. */
final class HomeController
{
    public function __invoke(Request $request, WorkQueues $queues): Response
    {
        return Inertia::render('home/Index', ['queues' => $queues->blocks(PageSupport::actor($request))]);
    }
}
