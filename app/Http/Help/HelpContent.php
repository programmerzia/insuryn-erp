<?php

declare(strict_types=1);

namespace App\Http\Help;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "How this works" words (session S3): resources/help/<module>.<locale>.md, written from market cross-check Part A and kept out of code so
 * they can be edited without a release of the screens. Rendered as HTML with any raw HTML in the file escaped and unsafe links dropped.
 */
final class HelpContent
{
    public const MODULES = ['policies', 'receipts', 'bank', 'claims', 'commission', 'accounting', 'close', 'reports'];

    public const LOCALES = ['en', 'bn'];

    /** @return array{title: string, html: string} */
    public function for(string $module, string $locale): array
    {
        if (! in_array($module, self::MODULES, true) || ! in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException("No help for {$module} in {$locale}.");
        }
        $markdown = (string) file_get_contents(resource_path("help/{$module}.{$locale}.md"));
        preg_match('/^#\s+(.+)$/m', $markdown, $title);

        return ['title' => trim($title[1] ?? $module), 'html' => $this->render((string) preg_replace('/^#\s+.+$/m', '', $markdown, 1))];
    }

    public function render(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}
