<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Http\Controllers;

use App\Modules\Distribution\Application\Licences\IdraRegisterExport;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Licences API (slice D2): list and record per producer, suspend / revoke / reinstate, the IDRA register export (CSV, or XLSX with format=xlsx).
 * The register export is also routed on the web (F6) for the producers queue's export control, with the same permission and output.
 */
final class LicenceController
{
    public function __construct(
        private readonly LicenceService $licences,
        private readonly ProducerDirectory $producers,
        private readonly PermissionChecker $permissions,
    ) {}

    public function index(Request $request, string $producer): JsonResponse
    {
        $this->permissions->authorizeAny((string) $request->user()?->getAuthIdentifier(), ['agent.manage', 'reports.regulatory']);
        $this->producers->get($producer);

        return response()->json(['data' => array_values(DB::table('producer_licences')->where('producer_id', $producer)->orderByDesc('expires_on')->get()
            ->map(fn (\stdClass $l): array => self::present($l))->all())]);
    }

    public function store(Request $request, string $producer): JsonResponse
    {
        /** @var array{licence_no: string, class: string, issued_on: string, expires_on: string, authority?: string|null, document_id?: string|null} $data */
        $data = $request->validate([
            'authority' => ['sometimes', 'nullable', 'string', 'max:32'],
            'licence_no' => ['required', 'string', 'max:64', Rule::unique('producer_licences', 'licence_no')->where('authority', $request->input('authority') ?: 'IDRA')],
            'class' => ['required', Rule::in(LicenceService::CLASSES)],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'expires_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'document_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $id = $this->licences->record(new RecordLicence($producer, $data['licence_no'], $data['class'], CarbonImmutable::parse($data['issued_on']),
            CarbonImmutable::parse($data['expires_on']), ($data['authority'] ?? null) ?: 'IDRA', $data['document_id'] ?? null), (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => self::present(DB::table('producer_licences')->where('id', $id)->first() ?? throw new \LogicException('Licence vanished.'))], 201);
    }

    public function changeStatus(Request $request, string $licence, string $action): JsonResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $actor = (string) $request->user()?->getAuthIdentifier();
        match ($action) {
            'suspend' => $this->licences->suspend($licence, $data['reason'], $actor),
            'revoke' => $this->licences->revoke($licence, $data['reason'], $actor),
            default => $this->licences->reinstate($licence, $data['reason'], $actor),
        };

        return response()->json(['data' => self::present(DB::table('producer_licences')->where('id', $licence)->first() ?? throw new \LogicException('Licence vanished.'))]);
    }

    public function register(Request $request, IdraRegisterExport $export): Response
    {
        $this->permissions->authorize((string) $request->user()?->getAuthIdentifier(), 'reports.regulatory');
        /** @var array{as_of?: string, format?: string} $data */
        $data = $request->validate(['as_of' => ['sometimes', 'date_format:Y-m-d'], 'format' => ['sometimes', Rule::in(['csv', 'xlsx'])]]);
        $asOf = CarbonImmutable::parse($data['as_of'] ?? app(BusinessClock::class)->today()->toDateString());
        [$body, $extension, $type] = ($data['format'] ?? 'csv') === 'xlsx'
            ? [$export->xlsx($asOf), 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            : [$export->csv($asOf), 'csv', 'text/csv; charset=UTF-8'];

        return response()->streamDownload(function () use ($body): void {
            echo $body;
        }, "idra-agent-register-{$asOf->toDateString()}.{$extension}", ['Content-Type' => $type]);
    }

    /** @return array<string, mixed> */
    private static function present(\stdClass $licence): array
    {
        return ['id' => (string) $licence->id, 'producer_id' => (string) $licence->producer_id, 'authority' => (string) $licence->authority, 'licence_no' => (string) $licence->licence_no,
            'class' => (string) $licence->class, 'issued_on' => (string) $licence->issued_on, 'expires_on' => (string) $licence->expires_on, 'status' => (string) $licence->status,
            'status_reason' => $licence->status_reason, 'document_id' => $licence->document_id];
    }
}
