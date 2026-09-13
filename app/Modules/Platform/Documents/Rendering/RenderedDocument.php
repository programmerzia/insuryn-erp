<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Rendering;

/** A printable page: the HTML and the SHA-256 of its rendered letterhead and body (the reference in its footer). */
final readonly class RenderedDocument
{
    public function __construct(
        public string $html,
        public string $contentSha256,
    ) {}
}
