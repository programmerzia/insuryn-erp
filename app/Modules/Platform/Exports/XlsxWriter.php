<?php

declare(strict_types=1);

namespace App\Modules\Platform\Exports;

/**
 * DECISION D-28: a minimal, dependency-free Office Open XML workbook with one sheet of text cells (inline strings), for exports people open in a
 * spreadsheet. An .xlsx is a zip of five XML parts; ext-zip builds it. Every value is written as text, so codes and dates keep their exact form.
 */
final class XlsxWriter
{
    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     * @return string the workbook's bytes
     */
    public static function workbook(string $sheetName, array $header, array $rows): string
    {
        return self::workbookOfSheets([$sheetName => [$header, ...$rows]]);
    }

    /**
     * Market gap G5 (DECISION D-113): a workbook of several sheets, each a list of text rows (a return set: one sheet per form). Sheet names are cut to
     * Excel's 31 characters without the characters it refuses, and made unique.
     *
     * @param array<string, list<list<string>>> $sheets sheet name → rows
     * @return string the workbook's bytes
     */
    public static function workbookOfSheets(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file for the workbook.');
        }
        try {
            $zip = new \ZipArchive;
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot open the workbook archive.');
            }
            foreach (self::parts($sheets) as $name => $xml) {
                $zip->addFromString($name, $xml);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param array<string, list<list<string>>> $sheets
     * @return array<string, string> part name → XML
     */
    private static function parts(array $sheets): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
        $overrides = '';
        $entries = '';
        $relations = '';
        $parts = [];
        $used = [];
        $index = 0;
        foreach ($sheets as $sheetName => $rows) {
            $index++;
            $sheetRows = '';
            foreach ($rows as $r => $row) {
                $cells = '';
                foreach ($row as $c => $value) {
                    $cells .= sprintf('<c r="%s%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', self::column($c), $r + 1, self::escape($value));
                }
                $sheetRows .= sprintf('<row r="%d">%s</row>', $r + 1, $cells);
            }
            $base = mb_substr(trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', (string) $sheetName)), 0, 31);
            $name = $base === '' ? "Sheet{$index}" : $base;
            for ($n = 2; in_array(mb_strtolower($name), $used, true); $n++) {
                $name = mb_substr($base, 0, 28)." {$n}";
            }
            $used[] = mb_strtolower($name);
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $entries .= '<sheet name="'.self::escape($name).'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
            $relations .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
            $parts["xl/worksheets/sheet{$index}.xml"] = $xml.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>';
        }

        return [
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$overrides.'</Types>',
            '_rels/.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets>'.$entries.'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>',
            ...$parts,
        ];
    }

    /** Zero-based column index → spreadsheet column letters (0 → A, 26 → AA). */
    private static function column(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    private static function escape(string $value): string
    {
        // XML 1.0 forbids most control characters; drop them rather than write an unreadable workbook.
        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
