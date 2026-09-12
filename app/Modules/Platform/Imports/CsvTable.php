<?php

declare(strict_types=1);

namespace App\Modules\Platform\Imports;

use InvalidArgumentException;

/**
 * A CSV file with a header row, read into rows keyed by field name (spec §7 Import: "upload → parse").
 * Row numbers are file line numbers: the header is row 1, the first data row is row 2.
 */
final readonly class CsvTable
{
    /** @param array<int, array<string, string>> $rows row number → field → trimmed value */
    private function __construct(public array $rows) {}

    /**
     * @param array<string, string> $columns field → header name expected in the file
     * @param list<string> $requiredFields fields whose header must be present
     *
     * @throws InvalidArgumentException when the file is empty, too large, or misses a required header
     */
    public static function parse(string $contents, array $columns, array $requiredFields, int $maxRows): self
    {
        $lines = preg_split('/\r\n|\n|\r/', ltrim($contents, "\u{FEFF}")) ?: [];
        $header = array_map(fn (?string $h): string => strtolower(trim((string) $h)), str_getcsv((string) array_shift($lines), escape: ''));
        $missing = array_values(array_filter($requiredFields, fn (string $field): bool => ! in_array(strtolower($columns[$field] ?? $field), $header, true)));
        if ($missing !== []) {
            throw new InvalidArgumentException('Missing column(s): '.implode(', ', array_map(fn (string $f): string => $columns[$f] ?? $f, $missing)));
        }

        $positions = [];
        foreach ($columns as $field => $headerName) {
            $index = array_search(strtolower($headerName), $header, true);
            if ($index !== false) {
                $positions[$field] = $index;
            }
        }

        $rows = [];
        foreach ($lines as $offset => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, escape: '');
            $rows[$offset + 2] = array_map(fn (int $index): string => trim((string) ($cells[$index] ?? '')), $positions);
            if (count($rows) > $maxRows) {
                throw new InvalidArgumentException("The file has more than {$maxRows} rows.");
            }
        }

        return new self($rows);
    }
}
