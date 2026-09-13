<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Rendering;

/**
 * The fonts of printed documents (UX brief §2: IBM Plex Sans with Noto Sans Bengali in the same stack), self-hosted in resources/fonts/documents
 * (SIL Open Font License, copied from the @fontsource packages the app already uses; regular and semibold, Latin and Bengali subsets). They are
 * embedded in the page as data URIs, so the headless browser shapes Bangla with the real font without network access or installed fonts, and the
 * editor's sandboxed preview frame shows the same glyphs.
 */
final class DocumentFonts
{
    /** file name => [family, weight] */
    public const FILES = [
        'ibm-plex-sans-latin-400-normal.woff2' => ['IBM Plex Sans', 400],
        'ibm-plex-sans-latin-600-normal.woff2' => ['IBM Plex Sans', 600],
        'noto-sans-bengali-bengali-400-normal.woff2' => ['Noto Sans Bengali', 400],
        'noto-sans-bengali-bengali-600-normal.woff2' => ['Noto Sans Bengali', 600],
    ];

    private static ?string $css = null;

    /** The @font-face rules with every font embedded. */
    public static function css(): string
    {
        if (self::$css !== null) {
            return self::$css;
        }
        $css = '';
        foreach (self::FILES as $file => [$family, $weight]) {
            $bytes = file_get_contents(resource_path('fonts/documents/'.$file));
            if ($bytes === false) {
                throw new \RuntimeException("Document font {$file} is missing from resources/fonts/documents.");
            }
            $css .= "@font-face{font-family:'{$family}';font-style:normal;font-weight:{$weight};font-display:block;src:url(data:font/woff2;base64,".base64_encode($bytes).") format('woff2');}\n";
        }

        return self::$css = $css;
    }
}
