<?php

declare(strict_types=1);

namespace App\Http\Portal;

use App\Http\Pages\PageSupport;
use App\Http\Portal\OpenApi\PortalOperation;
use App\Modules\Distribution\Application\Hierarchy\HierarchyQuery;
use App\Modules\Distribution\Application\Licences\LicenceRegistry;
use App\Modules\Distribution\Application\ProducerSummary;
use App\Modules\Distribution\Application\Targets\TargetService;
use App\Modules\Insurance\Collections\Application\AgentCashPositionQuery;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\ProductionQuery;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Producer portal endpoints (Distribution design note §5, slice D9), composed at the app layer over Distribution and Insurance. Every read is limited
 * to the producer on the request (EnsureProducerPortal); a record of another producer is 404. The only write is recording a cash collection for the
 * producer's own policy, through ReceiptService as an agent collection (the producer holds the cash until it deposits it).
 */
final class PortalController
{
    #[PortalOperation(summary: 'Who I am: producer, channel, branch and place in the hierarchy', response: 'ProducerResponse')]
    public function me(Request $request, HierarchyQuery $hierarchy): JsonResponse
    {
        $producer = self::producer($request);
        $position = $hierarchy->positionAt($producer->id, app(BusinessClock::class)->today());

        return response()->json(['data' => ['id' => $producer->id, 'code' => $producer->code, 'name' => (string) DB::table('parties')->where('id', $producer->partyId)->value('display_name'),
            'type' => $producer->type, 'status' => $producer->status, 'channel' => (string) DB::table('channels')->where('id', $producer->channelId)->value('name'),
            'branch' => (string) DB::table('branches')->where('id', $producer->branchId)->value('code'), 'level' => $position?->level_code,
            'reports_to' => $position?->parent_producer_id === null ? null : (string) DB::table('producers')->where('id', $position->parent_producer_id)->value('code')]]);
    }

    #[PortalOperation(summary: 'My licence status', response: 'LicenceResponse')]
    public function licence(Request $request): JsonResponse
    {
        $producer = self::producer($request);
        $today = app(BusinessClock::class)->today()->toDateString();
        $licences = DB::table('producer_licences')->where('producer_id', $producer->id)->orderByDesc('expires_on')->get(['licence_no', 'authority', 'class', 'issued_on', 'expires_on', 'status']);
        $valid = $licences->first(fn (\stdClass $l): bool => $l->status === 'active' && (string) $l->issued_on <= $today && (string) $l->expires_on >= $today);

        return response()->json(['data' => ['valid' => $valid !== null, 'expires_on' => $valid === null ? null : (string) $valid->expires_on,
            'licences' => $licences->map(fn (\stdClass $l): array => (array) $l)->values()->all()]]);
    }

    #[PortalOperation(summary: 'My customers: policyholders of my policies', response: 'CustomerList')]
    public function customers(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('policies as p')->join('parties as c', 'c.id', '=', 'p.policyholder_party_id')->where('p.agent_id', self::producer($request)->id)
            ->where('p.status', '<>', 'quote')->groupBy('c.id', 'c.display_name')->orderBy('c.display_name')->selectRaw('c.id, c.display_name as name, count(*) as policies')->get()
            ->map(fn (\stdClass $c): array => ['id' => (string) $c->id, 'name' => (string) $c->name, 'policies' => (int) $c->policies])->values()->all()]);
    }

    #[PortalOperation(summary: 'My policies', response: 'PolicyList', query: ['status' => ['string', 'issued, active, lapsed, cancelled, expired, renewed']])]
    public function policies(Request $request): JsonResponse
    {
        /** @var array{status?: string} $data */
        $data = $request->validate(['status' => ['sometimes', Rule::in(['issued', 'active', 'lapsed', 'cancelled', 'expired', 'renewed'])]]);

        $status = $data['status'] ?? null;

        return response()->json(['data' => $this->policyQuery(self::producer($request))->when($status !== null, fn ($q) => $q->where('p.status', $status))
            ->orderByDesc('p.inception')->limit(500)->get()->map(fn (\stdClass $p): array => self::presentPolicy($p))->values()->all()]);
    }

    #[PortalOperation(summary: 'One of my policies, with its installments', response: 'PolicyDetailResponse', errors: [404])]
    public function policy(Request $request, string $policy): JsonResponse
    {
        $row = $this->policyQuery(self::producer($request))->where('p.id', $policy)->first() ?? abort(404);
        $installments = DB::table('installments')->where('policy_id', $policy)->orderBy('no')->get(['id', 'no', 'due_date', 'amount_minor', 'paid_minor', 'cancelled_minor'])
            ->map(fn (\stdClass $i): array => ['id' => (string) $i->id, 'no' => (int) $i->no, 'due_date' => (string) $i->due_date, 'amount_minor' => (int) $i->amount_minor,
                'outstanding_minor' => (int) $i->amount_minor - (int) $i->paid_minor - (int) $i->cancelled_minor])->values()->all();

        return response()->json(['data' => [...self::presentPolicy($row), 'installments' => $installments]]);
    }

    #[PortalOperation(summary: 'My policies due for renewal', response: 'PolicyList', query: ['within_days' => ['integer', 'Days ahead, default 60']])]
    public function renewalsDue(Request $request): JsonResponse
    {
        /** @var array{within_days?: int|string} $data */
        $data = $request->validate(['within_days' => ['sometimes', 'integer', 'min:1', 'max:366']]);
        $today = app(BusinessClock::class)->today();

        return response()->json(['data' => $this->policyQuery(self::producer($request))->whereIn('p.status', ['issued', 'active'])
            ->whereBetween('p.expiry', [$today->toDateString(), $today->addDays((int) ($data['within_days'] ?? 60))->toDateString()])->orderBy('p.expiry')->get()
            ->map(fn (\stdClass $p): array => self::presentPolicy($p))->values()->all()]);
    }

    #[PortalOperation(summary: 'Cash I collected and have not deposited yet', response: 'CollectionsToDepositResponse')]
    public function collectionsToDeposit(Request $request, AgentCashPositionQuery $cash): JsonResponse
    {
        $producer = self::producer($request);

        return response()->json(['data' => ['undeposited_minor' => $cash->undepositedMinor($producer->id), 'currency' => PageSupport::entity()['currency'],
            'collections' => DB::table('receipts')->where('collected_by_agent_id', $producer->id)->where('status', '<>', 'bounced')->orderByDesc('value_date')->limit(50)
                ->get(['number', 'value_date', 'amount_minor'])->map(fn (\stdClass $r): array => ['receipt_number' => (string) $r->number, 'value_date' => (string) $r->value_date, 'amount_minor' => (int) $r->amount_minor])->values()->all()]]);
    }

    #[PortalOperation(summary: 'Record cash I collected for one of my policies', response: 'CollectionResponse', request: 'CollectionRequest', status: 201, ability: 'portal:collect', errors: [404, 422])]
    public function recordCollection(Request $request, ReceiptService $receipts): JsonResponse
    {
        $producer = self::producer($request);
        /** @var array{installment_id: string, amount_minor: int|string, value_date: string, reference?: string|null} $data */
        $data = $request->validate(['installment_id' => ['required', 'uuid'], 'amount_minor' => ['required', 'integer', 'min:1'], 'value_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100']]);
        $policy = DB::table('installments as i')->join('policies as p', 'p.id', '=', 'i.policy_id')->where('i.id', $data['installment_id'])->where('p.agent_id', $producer->id)
            ->first(['p.entity_id', 'p.branch_id', 'p.policyholder_party_id', 'p.currency']) ?? abort(404);
        $receipt = $receipts->record(new RecordReceiptRequest((string) $policy->entity_id, (string) $policy->branch_id, (string) $policy->policyholder_party_id, 'cash', (int) $data['amount_minor'],
            (string) $policy->currency, CarbonImmutable::parse($data['value_date']), null, $data['reference'] ?? null, [new AllocationLine($data['installment_id'], (int) $data['amount_minor'])],
            null, $producer->id), (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => ['id' => $receipt->id, 'number' => $receipt->number, 'amount_minor' => $receipt->amount_minor, 'currency' => $receipt->currency,
            'value_date' => $receipt->value_date->toDateString()]], 201);
    }

    #[PortalOperation(summary: 'My commission statements', response: 'StatementList')]
    public function statements(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('commission_statements')->where('agent_id', self::producer($request)->id)->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (\stdClass $s): array => self::presentStatement($s))->values()->all()]);
    }

    #[PortalOperation(summary: 'One of my statements, with its entries', response: 'StatementDetailResponse', errors: [404])]
    public function statement(Request $request, string $statement): JsonResponse
    {
        $row = DB::table('commission_statements')->where('id', $statement)->where('agent_id', self::producer($request)->id)->first() ?? abort(404);
        $entries = DB::table('commission_entries as e')->leftJoin('policies as p', 'p.id', '=', 'e.policy_id')->where('e.statement_id', $statement)->orderBy('e.earned_on')
            ->get(['e.earned_on', 'e.kind', 'e.beneficiary_role', 'p.number', 'e.amount_minor', 'e.withholding_minor'])
            ->map(fn (\stdClass $e): array => ['earned_on' => (string) $e->earned_on, 'kind' => (string) $e->kind, 'role' => (string) $e->beneficiary_role, 'policy_number' => $e->number,
                'amount_minor' => (int) $e->amount_minor, 'withholding_minor' => (int) $e->withholding_minor])->values()->all();

        return response()->json(['data' => [...self::presentStatement($row), 'entries' => $entries]]);
    }

    #[PortalOperation(summary: 'My targets and achievement for a period', response: 'TargetList', query: ['period_type' => ['string', 'monthly, quarterly or annual (default monthly)'], 'period_start' => ['string', 'First day of the period (default: this month)']])]
    public function targets(Request $request, TargetService $targets, ProductionQuery $production): JsonResponse
    {
        $producer = self::producer($request);
        /** @var array{period_type?: string, period_start?: string} $data */
        $data = $request->validate(['period_type' => ['sometimes', Rule::in(['monthly', 'quarterly', 'annual'])], 'period_start' => ['sometimes', 'date_format:Y-m-d']]);
        $type = $data['period_type'] ?? 'monthly';
        $start = CarbonImmutable::parse($data['period_start'] ?? app(BusinessClock::class)->today()->startOfMonth()->toDateString());
        $end = match ($type) { 'quarterly' => $start->addMonths(2)->endOfMonth(), 'annual' => $start->endOfYear(), default => $start->endOfMonth() };

        $rows = [];
        foreach (['premium', 'policies', 'collections', 'persistency'] as $metric) {
            $target = $targets->valueFor('producer', $producer->id, $type, $start, $metric);
            if ($target === null) {
                continue;
            }
            $actual = $production->metric($metric, $start, $end, [$producer->id])[$producer->id] ?? 0;
            $rows[] = ['metric' => $metric, 'period_type' => $type, 'period_start' => $start->toDateString(), 'target' => $target, 'actual' => $actual,
                'achievement_bp' => intdiv(max(0, $actual) * 10_000, $target)];
        }

        return response()->json(['data' => $rows]);
    }

    private static function producer(Request $request): ProducerSummary
    {
        $producer = $request->attributes->get('producer');

        return $producer instanceof ProducerSummary ? $producer : abort(403);
    }

    private function policyQuery(ProducerSummary $producer): \Illuminate\Database\Query\Builder
    {
        return DB::table('policies as p')->join('products as pr', 'pr.id', '=', 'p.product_id')->join('parties as c', 'c.id', '=', 'p.policyholder_party_id')
            ->where('p.agent_id', $producer->id)->where('p.status', '<>', 'quote')
            ->select(['p.id', 'p.number', 'pr.name as product', 'c.display_name as policyholder', 'p.status', 'p.inception', 'p.expiry', 'p.gross_premium_minor', 'p.currency']);
    }

    /** @return array<string, mixed> */
    private static function presentPolicy(\stdClass $p): array
    {
        return ['id' => (string) $p->id, 'number' => $p->number, 'product' => (string) $p->product, 'policyholder' => (string) $p->policyholder, 'status' => (string) $p->status,
            'inception' => (string) $p->inception, 'expiry' => (string) $p->expiry, 'gross_premium_minor' => (int) $p->gross_premium_minor, 'currency' => (string) $p->currency];
    }

    /** @return array<string, mixed> */
    private static function presentStatement(\stdClass $s): array
    {
        return ['id' => (string) $s->id, 'number' => $s->number, 'period_end' => $s->period_end, 'earned_minor' => (int) $s->earned_minor, 'override_minor' => (int) $s->override_minor,
            'bonus_minor' => (int) $s->bonus_minor, 'clawback_minor' => (int) $s->clawback_minor, 'withholding_minor' => (int) $s->withholding_minor,
            'advances_recovered_minor' => (int) $s->advances_recovered_minor, 'net_minor' => (int) $s->net_minor, 'currency' => (string) $s->currency, 'status' => (string) $s->status,
            'paid_via' => (string) $s->paid_via, 'paid_on' => $s->paid_on];
    }
}
