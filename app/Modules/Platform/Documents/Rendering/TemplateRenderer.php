<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Rendering;

use App\Modules\Platform\Documents\Templates\DocumentVariables;
use App\Modules\Platform\Documents\Templates\TemplateBodyGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;

/**
 * Blade string → HTML (slice R8). A body and a letterhead are compiled only after TemplateBodyGuard accepts them, and only with the variable bag
 * (DocumentVariables): nothing else from the application is in scope, and every value is escaped. The layout adds the page setup, the fonts, the
 * letterhead (the entity name when the template has none) and the footer with the time generated and a short reference: the first 12 characters
 * of the SHA-256 of the rendered letterhead and body, which generated_documents keeps as content_sha256 (ASSUMPTION: A-105).
 */
final class TemplateRenderer
{
    /**
     * @param array<string, mixed> $variables the bag (normalised by DocumentVariables)
     * @throws BusinessRuleViolation DOCUMENT_TEMPLATE_UNSAFE, DOCUMENT_TEMPLATE_RENDER_FAILED
     */
    public function fragment(string $blade, array $variables): string
    {
        $problems = TemplateBodyGuard::problems($blade, DocumentVariables::NAMES);
        if ($problems !== []) {
            throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_UNSAFE', implode(' ', $problems));
        }
        $bag = DocumentVariables::normalise($variables);
        $level = ob_get_level();
        try {
            // Compiled views are keyed by the body's hash and kept (Blade caches the view name per body, so deleting the file would break the next render).
            return Blade::render($blade, $bag);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $message = trim((string) preg_replace('/\s*\(View: .*\)$/s', '', $e->getMessage()));

            throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_RENDER_FAILED', 'The template could not be rendered: '.($message === '' ? class_basename($e) : $message).'. Check the variable names against the variables list.');
        }
    }

    /**
     * The complete printable page.
     *
     * @param array<string, mixed> $variables
     */
    public function document(string $body, ?string $letterhead, array $variables, string $locale, CarbonImmutable $generatedAt): RenderedDocument
    {
        $bag = DocumentVariables::normalise($variables);
        $company = is_array($bag['company']) ? (string) ($bag['company']['name'] ?? '') : '';
        $letterheadHtml = $letterhead === null || trim($letterhead) === ''
            ? '<div class="entity">'.e($company).'</div>'
            : $this->fragment($letterhead, $bag);
        $bodyHtml = $this->fragment($body, $bag);
        $contentSha256 = hash('sha256', $letterheadHtml."\n".$bodyHtml);
        $title = is_array($bag['document']) ? (string) ($bag['document']['title'] ?? '') : '';
        $reference = substr($contentSha256, 0, 12);
        $at = $generatedAt->format('j M Y H:i');
        $footer = $locale === 'bn' ? "প্রস্তুত {$at} · রেফারেন্স {$reference}" : "Generated {$at} · Reference {$reference}";
        $lang = $locale === 'bn' ? 'bn' : 'en';
        $stack = $locale === 'bn' ? "'Noto Sans Bengali', 'IBM Plex Sans'" : "'IBM Plex Sans', 'Noto Sans Bengali'";
        $fonts = DocumentFonts::css();

        $html = <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<title>{$this->escape($title)}</title>
<style>
{$fonts}
@page { size: A4; margin: 16mm 16mm 20mm 16mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { font-family: {$stack}, sans-serif; font-size: 10pt; line-height: 1.45; color: #1b1f24; background: #ffffff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.page { padding-bottom: 12mm; }
.letterhead { border-bottom: 1.5pt solid #1f5f8b; padding-bottom: 3mm; margin-bottom: 6mm; }
.letterhead .entity { font-size: 15pt; font-weight: 600; color: #1f5f8b; }
h1 { font-size: 14pt; font-weight: 600; margin: 0 0 1mm; }
h2 { font-size: 10.5pt; font-weight: 600; margin: 6mm 0 2mm; }
p { margin: 0 0 2mm; }
.meta { color: #5b6470; }
.doc-head { margin-bottom: 4mm; }
table { width: 100%; border-collapse: collapse; margin: 0 0 2mm; page-break-inside: auto; }
tr { page-break-inside: avoid; }
th, td { text-align: left; vertical-align: top; padding: 1.6mm 2mm; border-bottom: 0.5pt solid #d5d9de; }
thead th { background: #f6f7f9; font-weight: 600; }
tbody th { width: 34%; font-weight: 500; color: #5b6470; }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.money td.num { width: 32%; }
tr.total td { font-weight: 600; border-top: 1pt solid #1b1f24; border-bottom: 1pt solid #1b1f24; }
.terms { margin: 0; padding-left: 5mm; }
.closing { margin-top: 6mm; }
.signatures { display: flex; gap: 8mm; margin-top: 18mm; }
.signatures > div { flex: 1; padding-top: 2mm; min-height: 6mm; }
.signatures > div:not(:empty) { border-top: 0.5pt solid #1b1f24; }
.footer { position: fixed; left: 0; right: 0; bottom: 0; font-size: 7.5pt; color: #5b6470; border-top: 0.5pt solid #d5d9de; padding-top: 1.5mm; background: #ffffff; }
</style>
</head>
<body>
<div class="page">
<header class="letterhead">{$letterheadHtml}</header>
<main>{$bodyHtml}</main>
</div>
<footer class="footer">{$this->escape($footer)}</footer>
</body>
</html>
HTML;

        return new RenderedDocument($html, $contentSha256);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
