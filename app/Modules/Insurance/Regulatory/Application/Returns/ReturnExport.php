<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Application\Returns;

use App\Modules\Platform\Documents\Rendering\DocumentFonts;
use App\Modules\Platform\Documents\Rendering\PdfRenderer;
use App\Modules\Platform\Exports\XlsxWriter;
use App\Modules\Platform\Money\MinorUnits;

/**
 * Market gap G5: a set of returns (or one form) as an XLSX workbook — one sheet per form, headed with the form's title, the entity and the period, then each
 * section's heading, column headings and rows as the configuration names them — or as a PDF printed by the headless Chrome renderer of slice R8 (DECISION D-114:
 * the page is built here, not from an editable document template, because its tables follow the form configuration).
 */
final class ReturnExport
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    /** @param list<array<string, mixed>> $forms each form as ReturnFormBuilder::build() returns it, plus status fields */
    public function xlsx(array $forms): string
    {
        $sheets = [];
        foreach ($forms as $entry) {
            /** @var array{title: string, sheet: string, entity_name: string, period_label: string, currency: string, sections: list<array{title: string, columns: list<array{key: string, label: string, kind: string}>, rows: list<array<string, mixed>>, totals: array<string, mixed>|null}>, notes: list<string>} $form */
            $form = $entry['form'];
            $rows = [[$form['title']], [$form['entity_name']], ['Period: '.$form['period_label']], ['Amounts in '.$form['currency']], self::statusLine($entry), []];
            foreach ($form['sections'] as $section) {
                $rows[] = [$section['title']];
                $rows[] = array_column($section['columns'], 'label');
                foreach ($section['rows'] as $row) {
                    $rows[] = array_map(fn (array $c): string => self::cell($row[$c['key']] ?? null, $c['kind'], $form['currency']), $section['columns']);
                }
                if ($section['totals'] !== null) {
                    $rows[] = array_map(fn (array $c): string => self::cell($section['totals'][$c['key']] ?? null, $c['kind'], $form['currency']), $section['columns']);
                }
                if ($section['rows'] === []) {
                    $rows[] = ['Nothing to report for the period.'];
                }
                $rows[] = [];
            }
            foreach ($form['notes'] as $note) {
                $rows[] = ['Note: '.$note];
            }
            $sheets[$form['sheet']] = $rows;
        }

        return XlsxWriter::workbookOfSheets($sheets);
    }

    /** @param list<array<string, mixed>> $forms */
    public function pdf(array $forms): string
    {
        return $this->pdf->render($this->html($forms));
    }

    /** @param list<array<string, mixed>> $forms */
    public function html(array $forms): string
    {
        $e = fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = '';
        foreach ($forms as $entry) {
            /** @var array{title: string, entity_name: string, period_label: string, currency: string, sections: list<array{title: string, columns: list<array{key: string, label: string, kind: string}>, rows: list<array<string, mixed>>, totals: array<string, mixed>|null}>, notes: list<string>} $form */
            $form = $entry['form'];
            $body .= '<section class="form"><header><p class="regulator">'.$e((string) config('erp.regulatory.regulator')).'</p><h1>'.$e($form['title']).'</h1>'
                .'<p>'.$e($form['entity_name']).' · '.$e($form['period_label']).' · Amounts in '.$e($form['currency']).'</p><p class="status">'.$e(self::statusLine($entry)[0]).'</p></header>';
            foreach ($form['sections'] as $section) {
                $body .= '<h2>'.$e($section['title']).'</h2><table><thead><tr>';
                foreach ($section['columns'] as $c) {
                    $body .= '<th class="'.($c['kind'] === 'value' ? '' : 'num').'">'.$e($c['label']).'</th>';
                }
                $body .= '</tr></thead><tbody>';
                foreach ($section['rows'] as $row) {
                    $body .= self::htmlRow($row, $section['columns'], $form['currency'], $e, false);
                }
                if ($section['rows'] === []) {
                    $body .= '<tr><td colspan="'.count($section['columns']).'" class="empty">Nothing to report for the period.</td></tr>';
                }
                if ($section['totals'] !== null) {
                    $body .= self::htmlRow($section['totals'], $section['columns'], $form['currency'], $e, true);
                }
                $body .= '</tbody></table>';
            }
            foreach ($form['notes'] as $note) {
                $body .= '<p class="note">'.$e($note).'</p>';
            }
            $body .= '</section>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Regulatory returns</title><style>'.DocumentFonts::css()
            .'@page{size:A4 landscape;margin:14mm}body{font-family:"IBM Plex Sans","Noto Sans Bengali",sans-serif;font-size:9pt;color:#1a1a1a}'
            .'.form{page-break-after:always}.form:last-child{page-break-after:auto}h1{font-size:14pt;margin:2px 0}h2{font-size:10.5pt;margin:14px 0 4px}'
            .'.regulator{font-size:8pt;color:#555;margin:0}.status{color:#555}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:3px 5px;vertical-align:top}'
            .'th{background:#f1f1ee;font-weight:600;text-align:left}.num{text-align:right;font-variant-numeric:tabular-nums}tr.total td{font-weight:600;background:#fafaf7}'
            .'.empty{color:#666;font-style:italic}.note{font-size:8pt;color:#555;margin:6px 0 0}</style></head><body>'.$body.'</body></html>';
    }

    /** A cell as people read it: money in major units, a rate as a percentage, other values as they are. */
    public static function cell(mixed $value, string $kind, string $currency): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) && $kind === 'money') {
            return MinorUnits::format($value, $currency);
        }
        if (is_int($value) && $kind === 'rate') {
            return intdiv($value, 100).'.'.str_pad((string) (abs($value) % 100), 2, '0', STR_PAD_LEFT).'%';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{0: string}
     */
    private static function statusLine(array $entry): array
    {
        $status = (string) ($entry['status'] ?? 'not_generated');

        return [match ($status) {
            'filed' => 'Filed on '.($entry['filed_on'] ?? '').', reference '.($entry['filing_reference'] ?? ''),
            'reviewed' => 'Reviewed, not filed yet',
            'draft' => 'Draft',
            default => 'Preview (not generated)',
        }];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{key: string, label: string, kind: string}> $columns
     * @param callable(string): string $e
     */
    private static function htmlRow(array $row, array $columns, string $currency, callable $e, bool $total): string
    {
        $html = '<tr'.($total ? ' class="total"' : '').'>';
        foreach ($columns as $c) {
            $html .= '<td class="'.($c['kind'] === 'value' && ! is_int($row[$c['key']] ?? null) ? '' : 'num').'">'.$e(self::cell($row[$c['key']] ?? null, $c['kind'], $currency)).'</td>';
        }

        return $html.'</tr>';
    }
}
