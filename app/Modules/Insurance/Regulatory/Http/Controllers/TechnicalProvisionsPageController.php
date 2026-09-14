<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Regulatory\Application\Provisions\TechnicalProvisionCalculator;
use App\Modules\Insurance\Regulatory\Application\Provisions\TechnicalProvisionService;
use App\Modules\Insurance\Regulatory\Application\RegulatoryPeriod;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Market gap G5, Regulatory → Technical provisions: the quarter's run workbench — unearned premium, IBNR by both methods with the method chosen per class, the
 * paid triangles, the premium deficiency notes and the journal the run posts; prepare (recalculate) and mark reviewed with provisions.run, approve and post with
 * provisions.approve (the journal preview first).
 */
final class TechnicalProvisionsPageController
{
    private const AREA = ['provisions.run', 'provisions.approve', 'reports.regulatory'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly BusinessClock $clock,
    ) {}

    public function index(Request $request, TechnicalProvisionCalculator $calculator, SodGuard $sod): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $entity = PageSupport::entity();
        $today = $this->clock->today($entity['id']);
        $quarter = $this->quarter($request);
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $run = DB::table('technical_provision_runs')->where('entity_id', $entity['id'])->where('quarter_key', $quarter->key)->first();
        if ($run !== null) {
            /** @var array{classes: list<array<string, mixed>>, triangles: array<string, array<string, mixed>>, total_ibnr_minor: int, total_upr_minor: int, notes: list<string>} $results */
            $results = json_decode((string) $run->results, true, 512, JSON_THROW_ON_ERROR);
        } else {
            $prior = DB::table('technical_provision_runs')->where('entity_id', $entity['id'])->where('status', 'posted')->where('quarter_end', '<', $quarter->end->toDateString())
                ->whereNull('reversed_by_run_id')->orderByDesc('quarter_end')->value('results');
            $priorByClass = $prior === null ? [] : array_column(json_decode((string) $prior, true, 512, JSON_THROW_ON_ERROR)['classes'], 'ibnr_minor', 'class');
            $results = $calculator->calculate($entity['id'], $quarter, [], $priorByClass);
        }
        $names = DB::table('users')->whereIn('id', array_filter([$run?->prepared_by, $run?->reviewed_by, $run?->approved_by]))->pluck('name', 'id');
        $labels = array_column($results['classes'], 'label', 'class');
        $journal = [];
        foreach ($results['classes'] as $line) {
            if ((int) $line['prior_ibnr_minor'] > 0) {
                $journal[] = ['event' => 'IBNR_PROVISION_REVERSED', 'class' => (string) $line['label'], 'debit' => 'IBNR provision', 'credit' => 'Claims incurred – IBNR', 'amount' => $money((int) $line['prior_ibnr_minor'])];
            }
            if ((int) $line['ibnr_minor'] > 0) {
                $journal[] = ['event' => 'IBNR_PROVISION', 'class' => (string) $line['label'], 'debit' => 'Claims incurred – IBNR', 'credit' => 'IBNR provision', 'amount' => $money((int) $line['ibnr_minor'])];
            }
        }
        $percent = fn (int $bp): string => PageSupport::percent($bp);

        return Inertia::render('regulatory/Provisions', [
            'quarter' => ['key' => $quarter->key, 'label' => $quarter->label(), 'end' => $quarter->end->toDateString()],
            'quarters' => array_map(fn (string $key): array => ['value' => $key, 'label' => RegulatoryPeriod::fromKey($key)->label()], array_slice(RegulatoryPeriod::choices($today, 8), 0, 8)),
            'run' => $run === null ? null : ['id' => (string) $run->id, 'number' => $run->number, 'status' => (string) $run->status,
                'prepared' => ($names[$run->prepared_by] ?? '').' · '.CarbonImmutable::parse((string) $run->prepared_at)->format('j M Y H:i'),
                'reviewed' => $run->reviewed_by === null ? null : ($names[$run->reviewed_by] ?? '').' · '.CarbonImmutable::parse((string) $run->reviewed_at)->format('j M Y H:i'),
                'posted' => $run->approved_by === null ? null : ($names[$run->approved_by] ?? '').' · '.CarbonImmutable::parse((string) $run->posted_at)->format('j M Y H:i'),
                'prepared_by_me' => $run->prepared_by === $actor,
                'sod_blocked' => $run->status === 'reviewed' && $sod->wouldBlock($actor, 'provisions.approve', AuditSubject::of('technical_provision_run', (string) $run->id))],
            'classes' => array_map(fn (array $l): array => ['class' => (string) $l['class'], 'label' => (string) $l['label'], 'net_premium_base' => $money((int) $l['net_premium_base_minor']),
                'percentage_rate' => $percent((int) $l['percentage_bp']), 'percentage_ibnr' => $money((int) $l['percentage_ibnr_minor']), 'chain_ladder_available' => (bool) $l['chain_ladder_available'],
                'chain_ladder_ibnr' => $money((int) $l['chain_ladder_ibnr_minor']), 'paid_to_date' => $money((int) $l['paid_to_date_minor']), 'ultimate' => $money((int) $l['ultimate_minor']),
                'case_reserves' => $money((int) $l['case_reserves_minor']), 'method' => (string) $l['method'], 'fell_back' => (bool) $l['fell_back'], 'ibnr' => $money((int) $l['ibnr_minor']),
                'prior_ibnr' => $money((int) $l['prior_ibnr_minor']), 'movement' => $money((int) $l['movement_minor']), 'upr' => $money((int) $l['upr_minor']),
                'expected_loss_ratio' => $percent((int) $l['expected_loss_ratio_bp']), 'maintenance' => $percent((int) $l['maintenance_expense_bp']), 'expected_cost' => $money((int) $l['expected_cost_minor']),
                'deficiency' => $money((int) $l['deficiency_minor']), 'deficiency_note' => (string) $l['deficiency_note']], $results['classes']),
            'triangles' => array_map(fn (string $class, array $t): array => ['class' => $class, 'label' => (string) ($labels[$class] ?? $class), 'available' => (bool) $t['available'],
                'accident_quarters_with_paid' => (int) $t['accident_quarters_with_paid'], 'dev_labels' => $t['dev_labels'], 'factors' => $t['factors'],
                'rows' => array_map(fn (array $r): array => ['accident_quarter' => $r['accident_quarter'], 'cells' => array_map(fn (?int $c): ?string => $c === null ? null : $money($c), $r['cells']),
                    'paid_to_date' => $money((int) $r['paid_to_date_minor']), 'ultimate' => $money((int) $r['ultimate_minor']), 'unpaid' => $money((int) $r['unpaid_minor'])], $t['rows']),
                'unpaid' => $money((int) $t['unpaid_minor'])], array_keys($results['triangles']), array_values($results['triangles'])),
            'totals' => ['ibnr' => $money((int) $results['total_ibnr_minor']), 'upr' => $money((int) $results['total_upr_minor']),
                'prior' => $money((int) ($run->prior_ibnr_minor ?? array_sum(array_column($results['classes'], 'prior_ibnr_minor'))))],
            'journal' => $journal,
            'notes' => $results['notes'],
            'minQuarters' => (int) config('erp.regulatory.provisions.chain_ladder_min_accident_quarters', 4),
            'can' => ['run' => $this->permissions->has($actor, 'provisions.run'), 'approve' => $this->permissions->has($actor, 'provisions.approve')],
        ]);
    }

    public function prepare(Request $request, TechnicalProvisionService $service): RedirectResponse
    {
        /** @var array{quarter: string, methods?: array<string, string>|null} $data */
        $data = $request->validate(['quarter' => ['required', 'string'], 'methods' => ['nullable', 'array'],
            'methods.*' => [Rule::in([TechnicalProvisionCalculator::PERCENTAGE, TechnicalProvisionCalculator::CHAIN_LADDER])]]);
        $quarter = RegulatoryPeriod::fromKey($data['quarter']);
        $service->prepare(PageSupport::entity()['id'], $quarter, $data['methods'] ?? [], PageSupport::actor($request));

        return redirect('/regulatory/provisions?quarter='.$quarter->key)->with('status', "Technical provisions for {$quarter->label()} calculated as a draft.");
    }

    public function review(Request $request, string $run, TechnicalProvisionService $service): RedirectResponse
    {
        $service->review($run, PageSupport::actor($request));

        return back()->with('status', 'Run marked reviewed. Someone else approves and posts it.');
    }

    public function approve(Request $request, string $run, TechnicalProvisionService $service): RedirectResponse
    {
        $service->approve($run, PageSupport::actor($request));

        return back()->with('status', 'Technical provisions approved and posted.');
    }

    private function quarter(Request $request): RegulatoryPeriod
    {
        $key = $request->query('quarter');
        $quarter = is_string($key) && $key !== '' ? RegulatoryPeriod::fromKey($key) : RegulatoryPeriod::quarterOf($this->clock->today(PageSupport::entity()['id']));

        return $quarter->isQuarter() ? $quarter : RegulatoryPeriod::quarterOf($quarter->end);
    }
}
