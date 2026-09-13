<?php

declare(strict_types=1);

namespace App\Modules\Platform\Preferences\Http;

use App\Http\Pages\PageSupport;
use App\Modules\Platform\Preferences\UserPreferences;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** PUT /preferences/{key} {value}: saves one interface preference for the signed-in user (no page reload; the screen already shows it). */
final class PreferencesController
{
    public function __invoke(Request $request, string $key, UserPreferences $preferences): Response
    {
        $preferences->set(PageSupport::actor($request), $key, $request->json('value', $request->input('value')));

        return response()->noContent();
    }
}
