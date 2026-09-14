<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Platform\Documents\Rendering\PdfRenderer;
use ArrayObject;

/**
 * The fake PDF renderer of fakePdfRenderer() (slice R8) as a named class: PHPStan intermittently lost the generic docblocks on the anonymous
 * class it replaced (missingType.generics on tests/Pest.php, depending on the analysis order).
 */
final class FakePdfRenderer implements PdfRenderer
{
    /** @param ArrayObject<int, string> $pages */
    public function __construct(private readonly ArrayObject $pages) {}

    public function render(string $html): string
    {
        $this->pages->append($html);

        return "%PDF-1.7\n% fake render ".count($this->pages).' '.hash('sha256', $html)."\n%%EOF\n";
    }
}
