<?php

declare(strict_types=1);

namespace App\Modules\People\Payroll\Application;

use App\Modules\Platform\Documents\DocumentContents;
use App\Modules\Platform\Documents\DocumentStore;
use App\Modules\Platform\Documents\Rendering\PdfRenderer;
use App\Modules\Platform\Documents\Rendering\TemplateRenderer;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Money\MinorUnits;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The payslip PDF (design §B.10.1 "payslips as PDFs", EN/BN labels) through the R8 renderer: the same letterhead, page and footer as every generated document,
 * a fixed People body over the standard variable bag (parties, details, money = earnings, allocations = deductions, total = net pay), printed by headless
 * Chromium. The first print of a numbered payslip is kept in the document store on the payslip; later prints return the kept file.
 * ASSUMPTION A-286: the payslip wording and the Bangla labels are placeholders to verify with HR.
 */
final class PayslipDocument
{
    public function __construct(private readonly TemplateRenderer $renderer, private readonly PdfRenderer $pdf, private readonly DocumentStore $store) {}

    /** @return array{name: string, pdf: string} */
    public function render(string $payslipId, string $actorUserId, string $locale = 'en'): array
    {
        $slip = DB::table('payslips as p')->join('payroll_runs as r', 'r.id', '=', 'p.run_id')->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->join('legal_entities as le', 'le.id', '=', 'r.entity_id')->where('p.id', $payslipId)
            ->first(['p.*', 'r.period_year', 'r.period_month', 'r.number as run_number', 'r.status as run_status', 'r.currency', 'r.paid_on', 'e.code', 'e.full_name', 'e.tin_masked', 'le.name as company'])
            ?? throw new BusinessRuleViolation('PAYSLIP_UNKNOWN', 'That payslip does not exist.');
        $bn = $locale === 'bn';
        $name = 'payslip-'.($slip->number ?? 'preview-'.$slip->code)."-{$locale}.pdf";
        if ($slip->stored_document_id !== null && ! $bn) {
            $stored = $this->store->find((string) $slip->stored_document_id, 'payslip', $payslipId);
            if ($stored !== null) {
                return ['name' => $name, 'pdf' => (string) \Illuminate\Support\Facades\Storage::disk($stored->disk)->get($stored->storagePath)];
            }
        }
        $w = $bn
            ? ['title' => 'বেতন বিবরণী', 'employee' => 'কর্মী', 'company' => 'প্রতিষ্ঠান', 'period' => 'মাস', 'code' => 'কর্মী কোড', 'designation' => 'পদবি', 'department' => 'বিভাগ',
                'branch' => 'শাখা', 'grade' => 'গ্রেড', 'tin' => 'টিআইএন', 'bank' => 'ব্যাংক হিসাব', 'net' => 'নিট বেতন', 'status' => 'অবস্থা', 'preview' => 'খসড়া (অনুমোদিত নয়)',
                'earnings' => 'আয়', 'deductions' => 'কর্তন', 'employer' => 'প্রতিষ্ঠানের ভবিষ্য তহবিল অংশ']
            : ['title' => 'Payslip', 'employee' => 'Employee', 'company' => 'Employer', 'period' => 'Month', 'code' => 'Employee code', 'designation' => 'Designation', 'department' => 'Department',
                'branch' => 'Branch', 'grade' => 'Grade', 'tin' => 'TIN', 'bank' => 'Paid to', 'net' => 'Net pay', 'status' => 'Status', 'preview' => 'Preview (not approved)',
                'earnings' => 'Earnings', 'deductions' => 'Deductions', 'employer' => 'Employer provident fund contribution'];
        $money = fn (int $minor): string => MinorUnits::format($minor, (string) $slip->currency);
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode((string) $slip->employment_snapshot, true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, string>|null $bank */
        $bank = $slip->bank_account_snapshot === null ? null : json_decode((string) $slip->bank_account_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $month = CarbonImmutable::parse(sprintf('%04d-%02d-01', (int) $slip->period_year, (int) $slip->period_month))->format('F Y');
        $lines = DB::table('payslip_lines')->where('payslip_id', $payslipId)->orderBy('line_no')->get(['kind', 'label', 'amount_minor']);
        $employer = (int) $lines->where('kind', 'employer_contribution')->sum('amount_minor');

        $variables = [
            'company' => ['name' => (string) $slip->company],
            'document' => ['title' => $w['title'], 'number' => (string) ($slip->number ?? $w['preview']), 'date' => $month],
            'currency' => (string) $slip->currency,
            'parties' => [['role' => $w['employee'], 'name' => "{$slip->full_name} ({$slip->code})"], ['role' => $w['company'], 'name' => (string) $slip->company]],
            'details' => array_values(array_filter([
                ['label' => $w['period'], 'value' => $month], ['label' => $w['designation'], 'value' => (string) ($snapshot['designation'] ?? '')],
                ['label' => $w['department'], 'value' => (string) ($snapshot['department'] ?? '')], ['label' => $w['branch'], 'value' => (string) ($snapshot['branch'] ?? '')],
                ['label' => $w['grade'], 'value' => (string) ($snapshot['grade'] ?? '')], $slip->tin_masked === null ? null : ['label' => $w['tin'], 'value' => (string) $slip->tin_masked],
                $bank === null ? null : ['label' => $w['bank'], 'value' => trim(($bank['bank_name'] ?? '').' '.($bank['bank_branch'] ?? '').' '.($bank['account_no_masked'] ?? ''))],
                ['label' => $w['status'], 'value' => $slip->number === null ? $w['preview'] : ($slip->run_status === 'paid' ? "Paid {$slip->paid_on}" : 'Approved')],
                $employer > 0 ? ['label' => $w['employer'], 'value' => $money($employer)] : null,
            ])),
            'money' => array_values($lines->where('kind', 'earning')->map(fn (object $l): array => ['label' => (string) $l->label, 'amount' => $money((int) $l->amount_minor)])->all()),
            'allocations' => array_values($lines->where('kind', 'deduction')->map(fn (object $l): array => ['reference' => '', 'description' => (string) $l->label, 'amount' => $money((int) $l->amount_minor)])->all()),
            'total' => ['label' => $w['net'], 'amount' => $money((int) $slip->net_minor)],
        ];
        $body = <<<BLADE
<div class="doc-head">
  <h1>{{ \$document['title'] }} · {{ \$document['date'] }}</h1>
  <p class="meta">{{ \$document['number'] }}</p>
</div>
<table class="parties"><tbody>
@foreach(\$parties as \$party)
  <tr><th>{{ \$party['role'] }}</th><td>{{ \$party['name'] }}</td></tr>
@endforeach
</tbody></table>
<table class="details"><tbody>
@foreach(\$details as \$row)
  <tr><th>{{ \$row['label'] }}</th><td>{{ \$row['value'] }}</td></tr>
@endforeach
</tbody></table>
<h2>{$w['earnings']}</h2>
<table class="money"><tbody>
@foreach(\$money as \$row)
  <tr><td>{{ \$row['label'] }}</td><td class="num">{{ \$row['amount'] }}</td></tr>
@endforeach
</tbody></table>
<h2>{$w['deductions']}</h2>
<table class="money"><tbody>
@foreach(\$allocations as \$row)
  <tr><td>{{ \$row['description'] }}</td><td class="num">{{ \$row['amount'] }}</td></tr>
@endforeach
  <tr class="total"><td>{{ \$total['label'] }} ({{ \$currency }})</td><td class="num">{{ \$total['amount'] }}</td></tr>
</tbody></table>
<p class="closing">This is a computer-generated payslip.</p>
BLADE;
        $rendered = $this->renderer->document($body, null, $variables, $bn ? 'bn' : 'en', CarbonImmutable::now()->setTimezone(app(BusinessClock::class)->timezone()));
        $pdf = $this->pdf->render($rendered->html);
        if ($slip->number !== null && ! $bn) {
            DB::transaction(function () use ($payslipId, $name, $pdf, $actorUserId): void {
                $stored = $this->store->storeGenerated('payslip', $payslipId, new DocumentContents($name, $pdf), $actorUserId, 'Payslip (English)', ['kind' => 'payslip', 'locale' => 'en']);
                DB::table('payslips')->where('id', $payslipId)->whereNull('stored_document_id')->update(['stored_document_id' => $stored->id]);
            });
        }

        return ['name' => $name, 'pdf' => $pdf];
    }
}
