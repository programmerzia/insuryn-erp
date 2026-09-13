<?php

declare(strict_types=1);

namespace App\Http\Distribution;

use App\Http\Pages\PageSupport;
use App\Modules\Distribution\Application\Targets\TargetService;
use App\Modules\Insurance\Policy\Application\ProductionQuery;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Distribution design note §6 targets grid (slice D8): for a period and metric, every producer, branch and channel with its target (editable in
 * place), actual production and achievement. Branch and channel actuals add up their producers. Values are shown the way people type them:
 * money for premium and collections, a count for policies, a percentage for persistency.
 */
final class TargetsPageController
{
    private const AREA = ['agent.manage', 'commission.approve', 'reports.financial'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request, ProductionQuery $production, TargetService $targets): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $periodType = in_array($request->query('period_type'), ['monthly', 'quarterly', 'annual'], true) ? (string) $request->query('period_type') : 'monthly';
        $metric = in_array($request->query('metric'), ['premium', 'policies', 'persistency', 'collections'], true) ? (string) $request->query('metric') : 'premium';
        $start = self::alignedStart($periodType, CarbonImmutable::parse((string) $request->query('period_start', 'today')));
        $end = match ($periodType) { 'quarterly' => $start->addMonths(2)->endOfMonth(), 'annual' => $start->endOfYear(), default => $start->endOfMonth() };
        $currency = PageSupport::entity()['currency'];

        $producers = DB::table('producers as p')->leftJoin('parties as pa', 'pa.id', '=', 'p.party_id')->where('p.status', '<>', 'terminated')->orderBy('p.code')
            ->get(['p.id', 'p.code', 'pa.display_name', 'p.branch_id', 'p.channel_id']);
        $actuals = $production->metric($metric, $start, $end, array_values(array_map('strval', $producers->pluck('id')->all())));
        $format = fn (?int $value): ?string => $value === null ? null : match ($metric) {
            'premium', 'collections' => PageSupport::money($value, $currency),
            'persistency' => PageSupport::percent($value),
            default => (string) $value,
        };
        $row = function (string $type, string $id, string $code, string $name, int $actual) use ($targets, $periodType, $start, $metric, $format): array {
            $target = $targets->valueFor($type, $id, $periodType, $start, $metric);

            return ['id' => $id, 'code' => $code, 'name' => $name, 'target' => $format($target), 'actual' => $format($actual),
                'achievement_percent' => $target === null ? null : PageSupport::percent(intdiv(max(0, $actual) * 10_000, $target))];
        };
        $sumBy = function (string $column) use ($producers, $actuals): array {
            $sums = [];
            foreach ($producers as $p) {
                $sums[(string) $p->{$column}] = ($sums[(string) $p->{$column}] ?? 0) + ($actuals[(string) $p->id] ?? 0);
            }

            return $sums;
        };
        [$byBranch, $byChannel] = [$sumBy('branch_id'), $sumBy('channel_id')];

        return Inertia::render('distribution/targets/Index', [
            'periodType' => $periodType, 'periodStart' => $start->toDateString(), 'periodEnd' => $end->toDateString(), 'metric' => $metric,
            'rows' => [
                'producer' => $producers->map(fn (object $p): array => $row('producer', (string) $p->id, (string) $p->code, (string) $p->display_name, $actuals[(string) $p->id] ?? 0))->values()->all(),
                // Persistency does not add up across producers; branch and channel rows show no actual for it.
                'branch' => DB::table('branches')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => $row('branch', (string) $b->id, (string) $b->code, (string) $b->name,
                    $metric === 'persistency' ? 0 : ($byBranch[(string) $b->id] ?? 0)))->values()->all(),
                'channel' => DB::table('channels')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $c): array => $row('channel', (string) $c->id, (string) $c->code, (string) $c->name,
                    $metric === 'persistency' ? 0 : ($byChannel[(string) $c->id] ?? 0)))->values()->all(),
            ],
            'can' => ['edit' => $this->permissions->has($actor, 'agent.manage')],
        ]);
    }

    public function save(Request $request, TargetService $targets): RedirectResponse
    {
        /** @var array{subject_type: string, subject_id: string, period_type: string, period_start: string, metric: string, value: string} $data */
        $data = $request->validate(['subject_type' => ['required', Rule::in(['producer', 'branch', 'channel'])], 'subject_id' => ['required', 'uuid'], 'period_type' => ['required', Rule::in(['monthly', 'quarterly', 'annual'])],
            'period_start' => ['required', 'date_format:Y-m-d'], 'metric' => ['required', Rule::in(['premium', 'policies', 'persistency', 'collections'])], 'value' => ['required', 'string']]);
        $value = match ($data['metric']) {
            'premium', 'collections' => PageSupport::minor('value', $data['value'], PageSupport::entity()['currency']),
            'persistency' => PageSupport::basisPoints('value', $data['value']),
            default => ctype_digit(trim($data['value'])) ? (int) trim($data['value']) : throw \Illuminate\Validation\ValidationException::withMessages(['value' => 'Enter a whole number of policies.']),
        };
        $targets->set($data['subject_type'], $data['subject_id'], $data['period_type'], CarbonImmutable::parse($data['period_start']), $data['metric'], $value, PageSupport::actor($request));

        return back()->with('status', 'Target saved.');
    }

    private static function alignedStart(string $periodType, CarbonImmutable $day): CarbonImmutable
    {
        $start = match ($periodType) { 'quarterly' => $day->firstOfQuarter(), 'annual' => $day->startOfYear(), default => $day->startOfMonth() };

        return $start->startOfDay();
    }
}
