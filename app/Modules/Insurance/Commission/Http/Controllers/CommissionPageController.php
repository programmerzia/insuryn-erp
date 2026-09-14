<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Commission\Application\CommissionStatementQuery;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Commission history (GA-10, D-75): every commission statement ever approved or paid, whichever run made it, the Phase 1 commission plans (read-only; ASSUMPTION: A-192)
 * and an agent's statement of entries. Approving and paying happen only in the monthly statement run (`/distribution/statements`).
 */
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
            'statements' => DB::table('commission_statements as s')->join('producers as a', 'a.id', '=', 's.agent_id')->leftJoin('parties as pa', 'pa.id', '=', 'a.party_id')
                ->where('s.entity_id', $entity['id'])->where('s.status', '!=', 'draft')->orderByDesc('s.approved_on')->orderByDesc('s.number')->limit(500)
                ->get(['s.id', 's.number', 'a.code', 'pa.display_name', 's.agent_id', 's.up_to', 's.period_end', 's.gross_minor', 's.withholding_minor', 's.advances_recovered_minor', 's.net_minor', 's.currency', 's.status', 's.paid_via', 's.approved_on', 's.paid_on'])
                ->map(fn (object $s): array => ['id' => (string) $s->id, 'number' => (string) $s->number, 'agent_code' => (string) $s->code, 'agent_name' => (string) $s->display_name, 'agent_id' => (string) $s->agent_id,
                    'up_to' => (string) $s->up_to, 'period_end' => $s->period_end, 'gross' => PageSupport::money((int) $s->gross_minor, (string) $s->currency),
                    'withholding' => PageSupport::money((int) $s->withholding_minor, (string) $s->currency), 'advances' => PageSupport::money((int) $s->advances_recovered_minor, (string) $s->currency),
                    'net' => PageSupport::money((int) $s->net_minor, (string) $s->currency), 'status' => (string) $s->status, 'paid_via' => (string) $s->paid_via,
                    'approved_on' => $s->approved_on, 'paid_on' => $s->paid_on])->values()->all(),
            'can' => ['run' => $this->permissions->has($actor, 'commission.approve') || $this->permissions->has($actor, 'commission.pay')],
        ]);
    }

    public function statement(Request $request, string $agent, CommissionStatementQuery $statements): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();
        $from = self::date($request, 'from', app(BusinessClock::class)->today()->startOfMonth());
        $to = self::date($request, 'to', app(BusinessClock::class)->today());
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
