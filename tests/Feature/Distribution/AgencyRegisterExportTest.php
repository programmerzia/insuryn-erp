<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Platform\Exports\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * F6: the producers queue offers the agency (IDRA) register as CSV and XLSX through an authenticated web route that returns the same export
 * as GET /api/distribution/licences/register, to reports.regulatory only.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    asTenant($this->ctx['tenant_id'], function (): void {
        DB::table('producer_licences')->where('producer_id', $this->world['agent_id'])->delete();
        $licences = app(LicenceService::class);
        $licences->record(new RecordLicence($this->world['agent_id'], 'IDRA-0001', 'both', CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2026-06-30')), $this->world['admin']);
        $licences->record(new RecordLicence($this->world['agent_id'], 'IDRA-0002 "renewed" & <new>', 'non_life', CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2029-06-30')), $this->world['admin']);
    });
});

/** @return array<string, string> every part of the workbook (a zip), each checked to be well-formed XML */
function xlsxParts(string $bytes): array
{
    $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-test');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $parts[$name] = (string) $zip->getFromIndex($i);
        expect(simplexml_load_string($parts[$name]))->not->toBeFalse();
    }
    $zip->close();
    unlink($path);
    expect(array_keys($parts))->toEqualCanonicalizing(['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml']);

    return $parts;
}

/** @return list<list<string>> the rows of the worksheet, cell text in column order */
function xlsxRows(string $bytes): array
{
    $sheet = simplexml_load_string(xlsxParts($bytes)['xl/worksheets/sheet1.xml']);
    if ($sheet === false) {
        throw new PHPUnit\Framework\AssertionFailedError('The worksheet is not well-formed XML.');
    }
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $cells[] = (string) $cell->is->t;
        }
        $rows[] = $cells;
    }

    return $rows;
}

it('downloads the agency register from the web route as the same CSV the API exports', function (): void {
    $reader = ($this->userWith)(['reports.regulatory']);
    $api = actingAs($reader)->get('/api/distribution/licences/register?as_of=2026-09-30', $this->headers)->assertOk()->streamedContent();
    $web = actingAs($reader)->get('/distribution/licences/register?format=csv&as_of=2026-09-30', $this->headers)->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertDownload('idra-agent-register-2026-09-30.csv');

    expect($web->streamedContent())->toBe($api)
        ->and(count(explode("\n", trim($api))))->toBe(3);
});

it('downloads the agency register as an XLSX workbook with the same rows', function (): void {
    $reader = ($this->userWith)(['reports.regulatory']);
    $csv = actingAs($reader)->get('/api/distribution/licences/register?as_of=2026-09-30', $this->headers)->assertOk()->streamedContent();
    $response = actingAs($reader)->get('/distribution/licences/register?format=xlsx&as_of=2026-09-30', $this->headers)->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->assertDownload('idra-agent-register-2026-09-30.xlsx');
    $expected = array_map(fn (string $line): array => str_getcsv($line, escape: ''), explode("\n", trim($csv)));

    expect(xlsxRows($response->streamedContent()))->toBe($expected)
        ->and($expected[2][0])->toBe('IDRA-0002 "renewed" & <new>')
        ->and(actingAs($reader)->get('/api/distribution/licences/register?format=xlsx&as_of=2026-09-30', $this->headers)->assertOk()->streamedContent())->toBe($response->streamedContent());
});

it('refuses the register download without reports.regulatory and shows the export control only to those who hold it', function (): void {
    $manager = ($this->userWith)(['agent.manage']);
    $reader = ($this->userWith)(['reports.regulatory']);

    foreach (['csv', 'xlsx'] as $format) {
        actingAs($manager)->get("/distribution/licences/register?format={$format}", $this->headers)->assertForbidden();
    }
    actingAs($reader)->get('/distribution/licences/register?format=pdf', $this->headers)->assertSessionHasErrors('format');

    actingAs($manager)->get('/distribution/producers', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('distribution/producers/Index')
        ->where('can.export_register', false)->where('can.manage', true));
    actingAs($reader)->get('/distribution/producers', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('distribution/producers/Index')
        ->where('can.export_register', true));
});

it('writes spreadsheet column letters past Z and escapes XML', function (): void {
    $header = array_map(fn (int $i): string => "c{$i}", range(0, 27));
    $workbook = XlsxWriter::workbook('Sheet/with:odd*name', $header, [array_map(fn (int $i): string => $i === 27 ? "a<b>&\"c\x01" : (string) $i, range(0, 27))]);
    $rows = xlsxRows($workbook);
    $parts = xlsxParts($workbook);

    expect($rows[0])->toBe($header)
        ->and($rows[1][26])->toBe('26')
        ->and($rows[1][27])->toBe('a<b>&"c')
        ->and($parts['xl/worksheets/sheet1.xml'])->toContain('<c r="A1"')->toContain('<c r="Z1"')->toContain('<c r="AA2"')->toContain('<c r="AB2"')
        ->and($parts['xl/workbook.xml'])->toContain('<sheet name="Sheet with odd name"');
});
