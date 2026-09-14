<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Application\Provisions;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Insurance\Regulatory\Application\RegulatoryPeriod;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Market gap G5: the quarterly technical provisions run, draft → reviewed → posted. The finance manager prepares it (provisions.run: calculates, and recalculates
 * with a method per class while it is not posted) and marks it reviewed; the CFO approves it (provisions.approve), never on a run they prepared or reviewed
 * (SoD object rule provisions.run ✕ provisions.approve), which posts, dated the quarter end, IBNR_PROVISION per class (Dr claims incurred – IBNR, Cr IBNR provision)
 * and IBNR_PROVISION_REVERSED per class of the prior posted run (DECISION D-112: the prior quarter's provision is released by its own event on the same day,
 * so the quarter's expense is the movement and every journal names its run).
 */
final class TechnicalProvisionService
{
    public function __construct(
        private readonly TechnicalProvisionCalculator $calculator,
        private readonly SubmitAccountingEvent $submit,
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly Audit $audit,
        private readonly DocumentNumberer $numbers,
    ) {}

    /**
     * @param array<string, string> $methods class → percentage | chain_ladder
     *
     * @throws BusinessRuleViolation PROVISIONS_QUARTER_REQUIRED | PROVISIONS_ALREADY_POSTED
     */
    public function prepare(string $entityId, RegulatoryPeriod $quarter, array $methods, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, 'provisions.run');
        if (! $quarter->isQuarter()) {
            throw new BusinessRuleViolation('PROVISIONS_QUARTER_REQUIRED', 'Technical provisions are run for a quarter. Choose a quarter such as 2026-Q3.');
        }
        $existing = DB::table('technical_provision_runs')->where('entity_id', $entityId)->where('quarter_key', $quarter->key)->first(['id', 'status', 'number']);
        if ($existing !== null && $existing->status === 'posted') {
            throw new BusinessRuleViolation('PROVISIONS_ALREADY_POSTED', 'The technical provisions for this quarter are already posted. Next quarter\'s run releases them.');
        }
        $number = $existing === null ? $this->numbers->reserve(new DocumentNumberScope($entityId, null, 'technical_provision_run', 'TPR', $quarter->end), $actorUserId) : null;

        return DB::transaction(function () use ($entityId, $quarter, $methods, $actorUserId, $existing, $number): string {
            $prior = DB::table('technical_provision_runs')->where('entity_id', $entityId)->where('status', 'posted')->where('quarter_end', '<', $quarter->end->toDateString())
                ->whereNull('reversed_by_run_id')->orderByDesc('quarter_end')->first(['id', 'results', 'total_ibnr_minor']);
            $priorByClass = [];
            if ($prior !== null) {
                /** @var array{classes: list<array{class: string, ibnr_minor: int}>} $priorResults */
                $priorResults = json_decode((string) $prior->results, true, 512, JSON_THROW_ON_ERROR);
                $priorByClass = array_column($priorResults['classes'], 'ibnr_minor', 'class');
            }
            $results = $this->calculator->calculate($entityId, $quarter, $methods, $priorByClass);
            $chosen = array_column($results['classes'], 'method', 'class');
            $now = CarbonImmutable::now();
            $values = ['status' => 'draft', 'methods' => json_encode((object) $chosen, JSON_THROW_ON_ERROR), 'results' => json_encode($results, JSON_THROW_ON_ERROR),
                'total_ibnr_minor' => $results['total_ibnr_minor'], 'prior_run_id' => $prior?->id, 'prior_ibnr_minor' => (int) ($prior->total_ibnr_minor ?? 0),
                'prepared_by' => $actorUserId, 'prepared_at' => $now, 'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => $now];
            if ($existing === null) {
                $id = (string) Str::uuid7();
                $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
                DB::table('technical_provision_runs')->insert($values + ['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'number' => $number?->number,
                    'quarter_key' => $quarter->key, 'quarter_start' => $quarter->start->toDateString(), 'quarter_end' => $quarter->end->toDateString(), 'currency' => $currency, 'created_at' => $now]);
                if ($number !== null) {
                    $this->numbers->markUsed($number->id, 'technical_provision_run', $id);
                }
            } else {
                $id = (string) $existing->id;
                DB::table('technical_provision_runs')->where('id', $id)->update($values);
            }
            $this->audit->record('technical_provision_run.prepared', AuditSubject::of('technical_provision_run', $id), $existing === null ? null : ['status' => (string) $existing->status],
                ['quarter' => $quarter->key, 'status' => 'draft', 'methods' => $chosen, 'total_ibnr_minor' => $results['total_ibnr_minor']], null, 'provisions.run', Actor::user($actorUserId));

            return $id;
        });
    }

    /** @throws BusinessRuleViolation PROVISIONS_NOT_DRAFT */
    public function review(string $runId, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'provisions.run');
        DB::transaction(function () use ($runId, $actorUserId): void {
            $run = DB::table('technical_provision_runs')->where('id', $runId)->lockForUpdate()->first(['id', 'status']);
            abort_if($run === null, 404);
            if ($run->status !== 'draft') {
                throw new BusinessRuleViolation('PROVISIONS_NOT_DRAFT', 'This technical provisions run has already been reviewed or posted. Refresh the page.');
            }
            DB::table('technical_provision_runs')->where('id', $runId)->update(['status' => 'reviewed', 'reviewed_by' => $actorUserId, 'reviewed_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);
            $this->audit->record('technical_provision_run.reviewed', AuditSubject::of('technical_provision_run', $runId), ['status' => 'draft'], ['status' => 'reviewed'], null, 'provisions.run', Actor::user($actorUserId));
        });
    }

    /** @throws BusinessRuleViolation PROVISIONS_NOT_REVIEWED | SOD_CONFLICT */
    public function approve(string $runId, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'provisions.approve');
        $this->sod->assert($actorUserId, 'provisions.approve', AuditSubject::of('technical_provision_run', $runId));
        DB::transaction(function () use ($runId, $actorUserId): void {
            $run = DB::table('technical_provision_runs')->where('id', $runId)->lockForUpdate()->first();
            abort_if($run === null, 404);
            if ($run->status !== 'reviewed') {
                throw new BusinessRuleViolation('PROVISIONS_NOT_REVIEWED', 'Only a reviewed technical provisions run can be approved. Mark it reviewed first, or refresh the page.');
            }
            $quarterEnd = CarbonImmutable::parse((string) $run->quarter_end);
            $entityId = (string) $run->entity_id;
            $branchId = $this->branch($entityId);
            if ($run->prior_run_id !== null) {
                $prior = DB::table('technical_provision_runs')->where('id', $run->prior_run_id)->lockForUpdate()->first(['id', 'results', 'reversed_by_run_id']);
                if ($prior !== null && $prior->reversed_by_run_id === null) {
                    /** @var array{classes: list<array{class: string, ibnr_minor: int}>} $priorResults */
                    $priorResults = json_decode((string) $prior->results, true, 512, JSON_THROW_ON_ERROR);
                    foreach ($priorResults['classes'] as $line) {
                        $this->post('IBNR_PROVISION_REVERSED', (string) $prior->id, $run, $line['class'], $line['ibnr_minor'], $branchId, $quarterEnd);
                    }
                    DB::table('technical_provision_runs')->where('id', $prior->id)->update(['reversed_by_run_id' => $runId, 'updated_at' => CarbonImmutable::now()]);
                }
            }
            /** @var array{classes: list<array{class: string, ibnr_minor: int}>} $results */
            $results = json_decode((string) $run->results, true, 512, JSON_THROW_ON_ERROR);
            foreach ($results['classes'] as $line) {
                $this->post('IBNR_PROVISION', $runId, $run, $line['class'], $line['ibnr_minor'], $branchId, $quarterEnd);
            }
            DB::table('technical_provision_runs')->where('id', $runId)->update(['status' => 'posted', 'approved_by' => $actorUserId, 'posted_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);
            $this->audit->record('technical_provision_run.posted', AuditSubject::of('technical_provision_run', $runId), ['status' => 'reviewed'],
                ['status' => 'posted', 'total_ibnr_minor' => (int) $run->total_ibnr_minor, 'prior_run_id' => $run->prior_run_id], null, 'provisions.approve', Actor::user($actorUserId));
        });
    }

    /** An event per class with an amount; the idempotency key names the run whose provision it sets or releases. */
    private function post(string $eventType, string $keyRunId, \stdClass $run, string $class, int $amount, string $branchId, CarbonImmutable $on): void
    {
        if ($amount <= 0) {
            return;
        }
        ($this->submit)(
            entityId: (string) $run->entity_id, eventType: $eventType, sourceType: 'technical_provision_run', sourceId: (string) $run->id,
            idempotencyKey: "{$eventType}:{$keyRunId}:{$class}", transactionDate: $on, effectiveDate: $on, currency: (string) $run->currency,
            payload: ['amount' => $amount, 'run_id' => $keyRunId, 'class' => $class, 'quarter' => (string) $run->quarter_key],
            dimensions: ['branch' => $branchId, 'lob' => $class],
        );
    }

    /** ASSUMPTION A-267: the provision is booked to the head office branch (erp.regulatory.provisions.branch_code), else the entity's first branch by code. */
    private function branch(string $entityId): string
    {
        $code = (string) config('erp.regulatory.provisions.branch_code', 'HO');

        return (string) (DB::table('branches')->where('entity_id', $entityId)->where('code', $code)->value('id')
            ?? DB::table('branches')->where('entity_id', $entityId)->orderBy('code')->value('id')
            ?? throw new BusinessRuleViolation('PROVISIONS_NO_BRANCH', 'Set up a branch for this company before posting technical provisions.'));
    }
}
