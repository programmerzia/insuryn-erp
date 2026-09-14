<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Http\Controllers;

use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Policy\Domain\Models\Installment;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;
use App\Modules\Insurance\Policy\Http\Requests\QuotePolicyRequest;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PolicyController
{
    public function __construct(private readonly PolicyLifecycle $lifecycle) {}

    public function store(QuotePolicyRequest $request): JsonResponse
    {
        /** @var array{branch_id: string, product_id: string, policyholder_party_id: string, agent_id?: string|null, inception: string, premium_minor: int, installment_count?: int, payers?: list<array{party_id: string, share_bp: int}>} $data */
        $data = $request->validated();
        $branch = DB::table('branches')->where('id', $data['branch_id'])->first(['entity_id']);
        $currency = (string) DB::table('legal_entities')->where('id', $branch?->entity_id)->value('base_currency');
        $policy = $this->lifecycle->quote(new QuoteRequest((string) $branch?->entity_id, $data['branch_id'], $data['product_id'], $data['policyholder_party_id'],
            $data['agent_id'] ?? null, CarbonImmutable::parse($data['inception']), (int) $data['premium_minor'], $currency, (int) ($data['installment_count'] ?? 1),
            array_map(fn (array $p): \App\Modules\Insurance\Policy\Application\PayerShare => new \App\Modules\Insurance\Policy\Application\PayerShare($p['party_id'], (int) $p['share_bp']), $data['payers'] ?? [])), self::actor($request));

        return response()->json(['data' => $this->present($policy)], 201);
    }

    public function show(string $policy): JsonResponse
    {
        return response()->json(['data' => $this->present(Policy::query()->findOrFail($policy), true)]);
    }

    public function issue(Request $request, string $policy): JsonResponse
    {
        $data = $request->validate(['on' => ['sometimes', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->present($this->lifecycle->issue($policy, self::date($data['on'] ?? null), self::actor($request)))]);
    }

    public function endorse(Request $request, string $policy): JsonResponse
    {
        /** @var array{effective_date: string, premium_delta_minor: int, reason: string} $data */
        $data = $request->validate(['effective_date' => ['required', 'date_format:Y-m-d'], 'premium_delta_minor' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $this->present($this->lifecycle->endorse($policy, CarbonImmutable::parse($data['effective_date']), (int) $data['premium_delta_minor'], $data['reason'], self::actor($request)))]);
    }

    public function cancel(Request $request, string $policy): JsonResponse
    {
        /** @var array{cancel_date: string, reason: string} $data */
        $data = $request->validate(['cancel_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $this->present($this->lifecycle->cancel($policy, CarbonImmutable::parse($data['cancel_date']), $data['reason'], self::actor($request)))]);
    }

    public function lapse(Request $request, string $policy): JsonResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $this->present($this->lifecycle->lapse($policy, $data['reason'], self::actor($request)))]);
    }

    public function reinstate(Request $request, string $policy): JsonResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $this->present($this->lifecycle->reinstate($policy, $data['reason'], self::actor($request)))]);
    }

    public function renew(Request $request, string $policy): JsonResponse
    {
        return response()->json(['data' => $this->present($this->lifecycle->renew($policy, self::actor($request)))], 201);
    }

    /** @return array<string, mixed> */
    private function present(Policy $policy, bool $withDetail = false): array
    {
        $data = [
            'id' => $policy->id, 'number' => $policy->number, 'status' => $policy->status->value, 'version' => $policy->version,
            'branch_id' => $policy->branch_id, 'product_id' => $policy->product_id, 'product_version_id' => $policy->product_version_id,
            'policyholder_party_id' => $policy->policyholder_party_id, 'agent_id' => $policy->agent_id, 'channel' => $policy->channel,
            'inception' => $policy->inception->toDateString(), 'expiry' => $policy->expiry->toDateString(), 'currency' => $policy->currency,
            'gross_premium_minor' => $policy->gross_premium_minor, 'net_premium_minor' => $policy->net_premium_minor, 'tax_minor' => $policy->tax_minor,
            'cancel_date' => $policy->cancel_date?->toDateString(),
        ];
        if ($withDetail) {
            $data['transactions'] = $policy->transactions()->get()->map(fn (PolicyTransaction $t): array => ['id' => $t->id, 'type' => $t->type->value,
                'effective_date' => $t->effective_date->toDateString(), 'premium_delta_minor' => $t->premium_delta_minor, 'amounts' => $t->amounts])->values()->all();
            $data['installments'] = $policy->installments()->get()->map(fn (Installment $i): array => ['id' => $i->id, 'no' => $i->no, 'payer_party_id' => $i->payer_party_id,
                'due_date' => $i->due_date->toDateString(), 'amount_minor' => $i->amount_minor, 'paid_minor' => $i->paid_minor,
                'cancelled_minor' => $i->cancelled_minor, 'status' => $i->status->value])->values()->all();
        }

        return $data;
    }

    public function payers(string $policy, \App\Modules\Insurance\Policy\Application\PayerStatementQuery $statements): JsonResponse
    {
        return response()->json(['data' => $statements->forPolicy($policy)]);
    }

    public function dunningNotices(Request $request, \App\Modules\Platform\Authorization\PermissionChecker $permissions): JsonResponse
    {
        /** @var array{entity_id: string, from: string, to: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $permissions->authorize(self::actor($request), 'receipt.allocate', \App\Modules\Platform\Authorization\AuthorizationScope::entity($data['entity_id']));
        $notices = DB::table('dunning_notices as n')->join('policies as p', 'p.id', '=', 'n.policy_id')->where('n.entity_id', $data['entity_id'])
            ->whereBetween('n.issued_on', [$data['from'], $data['to']])->orderBy('n.issued_on')->orderBy('n.level')
            ->get(['n.id', 'n.policy_id', 'p.number as policy_number', 'n.installment_id', 'n.level', 'n.days_overdue', 'n.outstanding_minor', 'n.issued_on'])
            ->map(fn (object $n): array => ['id' => (string) $n->id, 'policy_id' => (string) $n->policy_id, 'policy_number' => $n->policy_number, 'installment_id' => (string) $n->installment_id,
                'level' => (int) $n->level, 'days_overdue' => (int) $n->days_overdue, 'outstanding_minor' => (int) $n->outstanding_minor, 'issued_on' => (string) $n->issued_on])->values()->all();

        return response()->json(['data' => $notices]);
    }

    private static function date(?string $value): CarbonImmutable
    {
        return $value === null ? app(BusinessClock::class)->today() : CarbonImmutable::parse($value);
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
