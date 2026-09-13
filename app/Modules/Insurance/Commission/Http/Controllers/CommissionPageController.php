<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Commission\Application\CommissionPayoutService;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Commission\Application\CommissionStatementQuery;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Commission screens: plans, payout statements (approve, then pay by someone else) and an agent's statement. */
final class CommissionPageController
{
    public const AREA = ['commission.manage_plans', 'commission.approve', 'commission.pay', 'reports.financial'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $entity = PageSupport::entity();

        return Inertia::render('commission/Index', [
            'plans' => DB::table('commission_plans')->orderBy('code')->get(['id', 'code', 'name', 'rate_bp', 'withholding_jurisdiction', 'withholding_tax_type', 'status'])
                ->map(fn (object $p): array => ['id' => (string) $p->id, 'code' => (string) $p->code, 'name' => (string) $p->name, 'rate_percent' => self::percent((int) $p->rate_bp),
                    'withholding' => $p->withholding_tax_type === null ? null : "{$p->withholding_tax_type} ({$p->withholding_jurisdiction})", 'status' => (string) $p->status])->values()->all(),
            'statements' => DB::table('commission_statements as s')->join('producers as a', 'a.id', '=', 's.agent_id')->where('s.entity_id', $entity['id'])->orderByDesc('s.approved_on')->limit(100)
                ->get(['s.id', 's.number', 'a.code', 's.agent_id', 's.up_to', 's.gross_minor', 's.withholding_minor', 's.net_minor', 's.currency', 's.status', 's.paid_on'])
                ->map(fn (object $s): array => ['id' => (string) $s->id, 'number' => (string) $s->number, 'agent_code' => (string) $s->code, 'agent_id' => (string) $s->agent_id, 'up_to' => (string) $s->up_to,
                    'gross' => PageSupport::money((int) $s->gross_minor, (string) $s->currency), 'withholding' => PageSupport::money((int) $s->withholding_minor, (string) $s->currency),
                    'net' => PageSupport::money((int) $s->net_minor, (string) $s->currency), 'status' => (string) $s->status, 'paid_on' => $s->paid_on])->values()->all(),
            'agents' => DB::table('producers')->where('status', 'active')->orderBy('code')->get(['id', 'code'])->map(fn (object $a): array => (array) $a)->values()->all(),
            'bankAccounts' => DB::table('bank_accounts')->where('entity_id', $entity['id'])->where('status', 'active')->get(['id', 'bank_name', 'account_no_masked'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'can' => ['plans' => $this->permissions->has($actor, 'commission.manage_plans'), 'approve' => $this->permissions->has($actor, 'commission.approve'), 'pay' => $this->permissions->has($actor, 'commission.pay')],
        ]);
    }

    public function storePlan(Request $request, CommissionPlanService $plans): RedirectResponse
    {
        /** @var array{code: string, name: string, rate_percent: string, withholding_jurisdiction?: string|null, withholding_tax_type?: string|null} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:255'], 'rate_percent' => ['required', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'withholding_jurisdiction' => ['nullable', 'string', 'max:16'], 'withholding_tax_type' => ['nullable', 'string', 'max:64']]);
        [$whole, $fraction] = array_pad(explode('.', $data['rate_percent'], 2), 2, '');
        $plan = $plans->create($data['code'], $data['name'], (int) $whole * 100 + (int) str_pad($fraction, 2, '0'), ($data['withholding_jurisdiction'] ?? '') === '' ? null : $data['withholding_jurisdiction'],
            ($data['withholding_tax_type'] ?? '') === '' ? null : $data['withholding_tax_type'], PageSupport::actor($request));

        return redirect('/commission')->with('status', "Plan {$plan->code} created.");
    }

    public function approve(Request $request, CommissionPayoutService $payouts): RedirectResponse
    {
        /** @var array{agent_id: string, up_to: string, on: string} $data */
        $data = $request->validate(['agent_id' => ['required', 'uuid'], 'up_to' => ['required', 'date_format:Y-m-d'], 'on' => ['required', 'date_format:Y-m-d']]);
        $statement = $payouts->approve($data['agent_id'], CarbonImmutable::parse($data['up_to']), PageSupport::actor($request), CarbonImmutable::parse($data['on']));

        return redirect('/commission')->with('status', "Statement {$statement->number} approved; someone else must pay it.");
    }

    public function pay(Request $request, string $statement, CommissionPayoutService $payouts): RedirectResponse
    {
        /** @var array{paid_on: string, bank_account_id?: string|null} $data */
        $data = $request->validate(['paid_on' => ['required', 'date_format:Y-m-d'], 'bank_account_id' => ['nullable', 'uuid']]);
        $paid = $payouts->pay($statement, ($data['bank_account_id'] ?? '') === '' ? null : $data['bank_account_id'], PageSupport::actor($request), CarbonImmutable::parse($data['paid_on']));

        return redirect('/commission')->with('status', "Statement {$paid->number} paid.");
    }

    public function statement(Request $request, string $agent, CommissionStatementQuery $statements): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();
        $from = self::date($request, 'from', CarbonImmutable::today()->startOfMonth());
        $to = self::date($request, 'to', CarbonImmutable::today());
        $result = $statements->statement($agent, $from, $to);
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);

        return Inertia::render('commission/Statement', [
            'agent' => ['id' => $agent, 'code' => (string) DB::table('producers')->where('id', $agent)->value('code')],
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'statement' => ['opening_payable' => $money($result['opening_payable_minor']), 'closing_payable' => $money($result['closing_payable_minor']),
                'entries' => array_map(fn (array $e): array => ['id' => $e['id'], 'earned_on' => $e['earned_on'], 'kind' => $e['kind'], 'policy_number' => $e['policy_number'],
                    'base' => $money($e['base_minor']), 'rate_percent' => $e['rate_bp'] === null ? null : self::percent($e['rate_bp']), 'amount' => $money($e['amount_minor']),
                    'withholding' => $money($e['withholding_minor']), 'net' => $money($e['net_minor']), 'status' => $e['status']], $result['entries']),
                'totals' => ['earned' => $money($result['totals']['earned_minor']), 'clawback' => $money($result['totals']['clawback_minor']),
                    'withholding' => $money($result['totals']['withholding_minor']), 'net' => $money($result['totals']['net_minor'])]],
        ]);
    }

    private static function percent(int $basisPoints): string
    {
        return sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100);
    }

    private static function date(Request $request, string $key, CarbonImmutable $default): CarbonImmutable
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value) : $default;
    }
}
