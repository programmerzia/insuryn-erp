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
    public const MODULES = ['quotes', 'policies', 'receipts', 'bank', 'claims', 'commission', 'accounting', 'close', 'reports'];

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

    /**
     * Session S4 guided tour: resources/help/tour.<locale>.md, one `## <step id>` section per step with a `### title` and its text.
     *
     * @return list<array{id: string, title: string, html: string}>
     */
    public function tour(string $locale): array
    {
        if (! in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException("No tour in {$locale}.");
        }
        $markdown = (string) file_get_contents(resource_path("help/tour.{$locale}.md"));
        $steps = [];
        foreach (preg_split('/^## /m', $markdown) ?: [] as $index => $section) {
            if ($index === 0) {
                continue; // the tour's own title
            }
            [$id, $rest] = array_pad(explode("\n", $section, 2), 2, '');
            preg_match('/^###\s+(.+)$/m', $rest, $title);
            $steps[] = ['id' => trim($id), 'title' => trim($title[1] ?? ''), 'html' => $this->render((string) preg_replace('/^###\s+.+$/m', '', $rest, 1))];
        }

        return $steps;
    }

    /**
     * Session S5: the plain caption of a journal line by account role and side, from the table in resources/help/roles.<locale>.md.
     *
     * @return array<string, array{debit: string, credit: string}>
     */
    public function roleCaptions(string $locale): array
    {
        if (! in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException("No captions in {$locale}.");
        }
        $captions = [];
        foreach (file(resource_path("help/roles.{$locale}.md"), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $cells = array_map('trim', explode('|', trim($line, " |")));
            if (count($cells) === 3 && preg_match('/^[a-z][a-z_]*$/', $cells[0]) === 1) {
                $captions[$cells[0]] = ['debit' => $cells[1], 'credit' => $cells[2]];
            }
        }

        return $captions;
    }

    public function render(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}
