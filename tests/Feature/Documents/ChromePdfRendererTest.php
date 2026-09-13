<?php

declare(strict_types=1);

use App\Modules\Platform\Documents\Rendering\ChromePdfRenderer;
use App\Modules\Platform\Documents\Rendering\PdfRenderer;
use App\Modules\Platform\Documents\Templates\DefaultDocumentTemplates;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Documents\Templates\DocumentVariables;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;

/**
 * Slice R8, DECISION D-34: the real headless Chromium render of a Bangla policy schedule. Not skipped when Chrome is missing — the renderer is
 * part of the product (config erp.documents.chrome_binary, env ERP_CHROME_BINARY; GitHub's ubuntu runners have /usr/bin/google-chrome).
 */
it('prints a Bangla policy schedule to a real PDF with the Bengali font embedded', function (): void {
    expect(app(PdfRenderer::class))->toBeInstanceOf(ChromePdfRenderer::class);
    $rendered = app(App\Modules\Platform\Documents\Rendering\TemplateRenderer::class)->document(DefaultDocumentTemplates::body(DocumentTemplateCode::PolicySchedule, 'bn'), null,
        DocumentVariables::demo(DocumentTemplateCode::PolicySchedule, 'bn'), 'bn', CarbonImmutable::parse('2026-09-14 10:30', 'Asia/Dhaka'));
    expect($rendered->html)->toContain('পলিসি তফসিল');

    $pdf = app(PdfRenderer::class)->render($rendered->html);

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(8_000)
        ->and(rtrim($pdf))->toEndWith('%%EOF')
        // Chromium embeds the subset of each font it used; the Bengali glyphs come from Noto Sans Bengali, not a fallback.
        ->and($pdf)->toContain('NotoSansBengali');
});

it('refuses with a clear reason when the Chrome binary cannot print', function (): void {
    config(['erp.documents.chrome_binary' => '/nonexistent/chrome']);

    expect(thrownBy(fn () => app(PdfRenderer::class)->render('<p>x</p>'), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_PDF_FAILED');
});
