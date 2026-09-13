<?php

declare(strict_types=1);

namespace App\Http\Help;

use App\Http\Pages\PageSupport;
use App\Modules\Platform\Preferences\UserPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /help/{module}[?locale=bn]: the "How this works" panel's words (session S3) in the requested language, else the user's saved language.
 * GET /help/tour: the guided tour's steps (session S4). GET /help/roles: journal line captions by account role and side (session S5).
 */
final class HelpController
{
    public function __invoke(Request $request, string $module, HelpContent $help, UserPreferences $preferences): JsonResponse
    {
        abort_unless(in_array($module, ['tour', 'roles'], true) || in_array($module, HelpContent::MODULES, true), 404);
        $requested = $request->query('locale');
        $saved = $preferences->of(PageSupport::actor($request))['locale'] ?? 'en';
        $locale = is_string($requested) && in_array($requested, HelpContent::LOCALES, true) ? $requested : (is_string($saved) ? $saved : 'en');

        if ($module === 'roles') {
            return response()->json(['locale' => $locale, 'captions' => $help->roleCaptions($locale)]);
        }
        if ($module === 'tour') {
            return response()->json(['locale' => $locale, 'steps' => $help->tour($locale)]);
        }

        return response()->json(['module' => $module, 'locale' => $locale, ...$help->for($module, $locale)]);
    }
}
