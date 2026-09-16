<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Http\Ledger\LedgerApiCatalogue;
use App\Modules\Accounting\Application\Integration\LedgerScope;
use App\Modules\Accounting\Application\Integration\PreviewExternalEvent;
use App\Modules\Accounting\Application\Integration\SubmitExternalEvent;
use App\Modules\Platform\Administration\IntegrationAccounts;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Present the ledger HTTP API inside the web app (docs/plan/api-accounting-v1.md): preview and post the same payloads the API accepts,
 * show curl examples, and let tenant admins create integration users.
 */
final class LedgerApiDemoPageController
{
    public function index(Request $request): Response
    {
        $scope = LedgerScope::resolve();
        $branchId = (string) DB::table('branches')->where('entity_id', $scope->entityId)->orderBy('code')->value('id');
        $tenant = DB::table('tenants')->where('id', TenantContext::id())->first(['id', 'slug']);
        $sample = [
            'event_type' => 'PREMIUM_RECEIVED',
            'idempotency_key' => 'PREMIUM_RECEIVED:DEMO-'.Str::upper(Str::random(6)),
            'transaction_date' => app(BusinessClock::class)->today($scope->entityId)->toDateString(),
            'currency' => $scope->currency,
            'payload' => ['amount' => 5_000_000],
            'dimensions' => [
                'branch' => $branchId, 'product' => (string) Str::uuid7(), 'product_code' => 'MOTOR', 'lob' => 'motor',
                'channel' => 'agent', 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(),
                'agent' => (string) Str::uuid7(), 'claim' => (string) Str::uuid7(),
            ],
            'source' => ['type' => 'receipt', 'id' => 'RCT-DEMO-1', 'number' => 'RCT-HO-DEMO-001'],
        ];
        $integrationUsers = DB::table('users')->where('kind', 'integration')->where('status', 'active')->orderBy('email')
            ->get(['id', 'email', 'name'])->map(fn (object $u): array => ['id' => (string) $u->id, 'email' => (string) $u->email, 'name' => (string) $u->name])->all();

        return Inertia::render('accounting/LedgerApiDemo', [
            'tenant' => ['id' => (string) ($tenant->id ?? ''), 'slug' => (string) ($tenant->slug ?? '')],
            'sample' => $sample,
            'integrationUsers' => $integrationUsers,
            'openapiPath' => '/docs/api/ledger-v1.openapi.json',
            'endpoints' => LedgerApiCatalogue::endpoints(),
            'implementationSteps' => LedgerApiCatalogue::implementationSteps(),
            'integrationEmail' => 'integration@nonlife.local',
            'can' => ['manageIntegration' => app(PermissionChecker::class)->has((string) $request->user()?->getAuthIdentifier(), 'platform.manage_users')],
            'result' => $request->session()->get('ledger_demo_result'),
        ]);
    }

    public function preview(Request $request, PreviewExternalEvent $preview): RedirectResponse
    {
        $data = $this->validatedBody($request);
        $scope = LedgerScope::resolve();
        $effective = CarbonImmutable::parse($data['effective_date'] ?? $data['transaction_date']);
        $result = $preview->preview($scope->entityId, $data['event_type'], $effective, strtoupper($data['currency']), $data['payload'], $data['dimensions']);

        return redirect()->route('accounting.ledger-api')->with('ledger_demo_result', ['kind' => 'preview', 'data' => $result]);
    }

    public function post(Request $request, SubmitExternalEvent $submit): RedirectResponse
    {
        $data = $this->validatedBody($request);
        $scope = LedgerScope::resolve();
        $transaction = CarbonImmutable::parse($data['transaction_date']);
        $effective = CarbonImmutable::parse($data['effective_date'] ?? $data['transaction_date']);
        $result = $submit->submit(
            $scope->entityId, $data['event_type'], $data['idempotency_key'], $transaction, $effective,
            strtoupper($data['currency']), $data['payload'], $data['dimensions'], $data['source'],
            $request->user()?->getAuthIdentifier(), true,
        );
        $event = $result->event->fresh() ?? $result->event;
        $journal = $result->journals[0] ?? null;

        return redirect()->route('accounting.ledger-api')->with('ledger_demo_result', [
            'kind' => 'posted',
            'data' => [
                'event_id' => $event->id,
                'status' => $event->status->value,
                'journal_id' => $journal?->id,
                'journal_number' => $journal?->number,
            ],
        ]);
    }

    public function createIntegrationUser(Request $request, IntegrationAccounts $accounts): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
        ]);
        try {
            $accounts->create($data['name'], $data['email'], $data['password'], (string) $request->user()?->getAuthIdentifier());
        } catch (BusinessRuleViolation $e) {
            return redirect()->route('accounting.ledger-api')->withErrors(['integration' => $e->getMessage()]);
        }

        return redirect()->route('accounting.ledger-api')->with('status', "Integration user {$data['email']} created. Obtain a token with POST /api/v1/tokens.");
    }

    /** @return array{event_type: string, idempotency_key: string, transaction_date: string, effective_date?: string, currency: string, payload: array<string, mixed>, dimensions: array<string, mixed>, source: array{type: string, id: string, number?: string|null}} */
    private function validatedBody(Request $request): array
    {
        if (is_string($request->input('body'))) {
            $parsed = json_decode($request->input('body'), true);
            if (! is_array($parsed)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['body' => 'The payload must be valid JSON.']);
            }
            $request->merge($parsed);
        }

        /** @var array{event_type: string, idempotency_key: string, transaction_date: string, effective_date?: string, currency: string, payload: array<string, mixed>, dimensions: array<string, mixed>, source: array{type: string, id: string, number?: string|null}} $data */
        $data = $request->validate([
            'body' => ['sometimes', 'string'],
            'event_type' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'max:200'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'effective_date' => ['sometimes', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'payload' => ['required', 'array'],
            'dimensions' => ['required', 'array'],
            'source' => ['required', 'array'],
            'source.type' => ['required', 'string', 'max:64'],
            'source.id' => ['required', 'string', 'max:128'],
            'source.number' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        return $data;
    }
}
