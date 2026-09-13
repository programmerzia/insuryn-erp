<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Rendering;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/** Turns a complete, self-contained HTML document (fonts and images embedded) into PDF bytes. DECISION D-34: headless Chromium. */
interface PdfRenderer
{
    /** @throws BusinessRuleViolation DOCUMENT_PDF_FAILED when no PDF could be produced */
    public function render(string $html): string;
}
