<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents;

/** A document given as bytes rather than an upload (imports, later generated PDFs): its file name and its contents. */
final readonly class DocumentContents
{
    public function __construct(
        public string $name,
        public string $contents,
    ) {}
}
