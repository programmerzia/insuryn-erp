<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Application\Returns;

use App\Modules\Insurance\Regulatory\Application\RegulatoryPeriod;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Market gap G5: a period's set of regulatory returns. Generating stores each form's table as it stands (a snapshot, status draft); a reviewer marks a form
 * reviewed; `regulatory.file` marks it filed with the filing date and the regulator's reference, after which it is never regenerated. Every step is audited.
 * Generating again replaces the drafts and reviewed forms that are not filed (a reviewed form goes back to draft, as its figures may have changed).
 */
final class RegulatoryReturnService
{
    public function __construct(
        private readonly ReturnFormBuilder $builder,
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
        private readonly BusinessClock $clock,
    ) {}

    /** @return int the number of forms generated (filed forms are left as they are) */
    public function generate(string $entityId, RegulatoryPeriod $period, string $actorUserId): int
    {
        $this->permissions->authorize($actorUserId, 'reports.regulatory');
        $count = 0;
        foreach (ReturnFormBuilder::codes() as $code) {
            $form = $this->builder->build($code, $entityId, $period);
            DB::transaction(function () use ($entityId, $period, $code, $form, $actorUserId, &$count): void {
                $existing = DB::table('regulatory_returns')->where('entity_id', $entityId)->where('form_code', $code)->where('period_key', $period->key)->lockForUpdate()->first(['id', 'status']);
                if ($existing !== null && $existing->status === 'filed') {
                    return;
                }
                $now = CarbonImmutable::now();
                $id = $existing === null ? (string) Str::uuid7() : (string) $existing->id;
                $values = ['status' => 'draft', 'snapshot' => json_encode($form, JSON_THROW_ON_ERROR), 'generated_by' => $actorUserId, 'generated_at' => $now,
                    'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => $now];
                if ($existing === null) {
                    DB::table('regulatory_returns')->insert($values + ['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'form_code' => $code,
                        'period_key' => $period->key, 'period_start' => $period->start->toDateString(), 'period_end' => $period->end->toDateString(), 'created_at' => $now]);
                } else {
                    DB::table('regulatory_returns')->where('id', $id)->update($values);
                }
                $this->audit->record('regulatory_return.generated', AuditSubject::of('regulatory_return', $id), $existing === null ? null : ['status' => (string) $existing->status],
                    ['form' => $code, 'period' => $period->key, 'status' => 'draft'], null, 'reports.regulatory', Actor::user($actorUserId));
                $count++;
            });
        }

        return $count;
    }

    /** @throws BusinessRuleViolation RETURN_NOT_DRAFT */
    public function review(string $returnId, string $actorUserId): void
    {
        $this->permissions->authorizeAny($actorUserId, ['reports.regulatory', 'regulatory.file']);
        DB::transaction(function () use ($returnId, $actorUserId): void {
            $return = DB::table('regulatory_returns')->where('id', $returnId)->lockForUpdate()->first(['id', 'status', 'form_code', 'period_key']);
            abort_if($return === null, 404);
            if ($return->status !== 'draft') {
                throw new BusinessRuleViolation('RETURN_NOT_DRAFT', 'This return has already been reviewed or filed. Refresh the page to see where it stands.');
            }
            DB::table('regulatory_returns')->where('id', $returnId)->update(['status' => 'reviewed', 'reviewed_by' => $actorUserId, 'reviewed_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);
            $this->audit->record('regulatory_return.reviewed', AuditSubject::of('regulatory_return', $returnId), ['status' => 'draft'], ['status' => 'reviewed', 'form' => $return->form_code,
                'period' => $return->period_key], null, 'reports.regulatory', Actor::user($actorUserId));
        });
    }

    /**
     * ASSUMPTION A-270: a return is filed on a date between the start of its period and today, with the regulator's reference (acknowledgement number).
     *
     * @throws BusinessRuleViolation RETURN_ALREADY_FILED | RETURN_NOT_REVIEWED | RETURN_FILING_DATE_INVALID | RETURN_REFERENCE_REQUIRED
     */
    public function file(string $returnId, CarbonImmutable $filedOn, string $reference, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'regulatory.file');
        $reference = trim($reference);
        if ($reference === '') {
            throw new BusinessRuleViolation('RETURN_REFERENCE_REQUIRED', 'Enter the reference the regulator gave for this filing.');
        }
        DB::transaction(function () use ($returnId, $filedOn, $reference, $actorUserId): void {
            $return = DB::table('regulatory_returns')->where('id', $returnId)->lockForUpdate()->first(['id', 'entity_id', 'status', 'form_code', 'period_key', 'period_start']);
            abort_if($return === null, 404);
            if ($return->status === 'filed') {
                throw new BusinessRuleViolation('RETURN_ALREADY_FILED', 'This return is already filed. Refresh the page to see its filing.');
            }
            if ($return->status !== 'reviewed') {
                throw new BusinessRuleViolation('RETURN_NOT_REVIEWED', 'Mark the return reviewed before filing it.');
            }
            if ($filedOn->toDateString() < (string) $return->period_start || $filedOn->greaterThan($this->clock->today((string) $return->entity_id))) {
                throw new BusinessRuleViolation('RETURN_FILING_DATE_INVALID', 'The filing date must be in or after the return\'s period and not later than today.');
            }
            DB::table('regulatory_returns')->where('id', $returnId)->update(['status' => 'filed', 'filed_on' => $filedOn->toDateString(), 'filing_reference' => $reference,
                'filed_by' => $actorUserId, 'filed_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);
            $this->audit->record('regulatory_return.filed', AuditSubject::of('regulatory_return', $returnId), ['status' => (string) $return->status],
                ['status' => 'filed', 'form' => $return->form_code, 'period' => $return->period_key, 'filed_on' => $filedOn->toDateString(), 'filing_reference' => $reference],
                null, 'regulatory.file', Actor::user($actorUserId));
        });
    }

    /**
     * The period's forms: each form's stored snapshot when generated, otherwise the live figures (not generated yet).
     *
     * @return list<array{id: string|null, code: string, title: string, status: string, generated_at: string|null, reviewed_at: string|null, filed_on: string|null,
     *     filing_reference: string|null, form: array<string, mixed>}>
     */
    public function forms(string $entityId, RegulatoryPeriod $period, bool $withLiveFigures = true): array
    {
        $stored = DB::table('regulatory_returns')->where('entity_id', $entityId)->where('period_key', $period->key)->get()->keyBy('form_code');
        $forms = [];
        foreach (ReturnFormBuilder::codes() as $code) {
            $row = $stored[$code] ?? null;
            /** @var array<string, mixed> $form */
            $form = $row !== null ? json_decode((string) $row->snapshot, true, 512, JSON_THROW_ON_ERROR) : ($withLiveFigures ? $this->builder->build($code, $entityId, $period) : []);
            $forms[] = ['id' => $row === null ? null : (string) $row->id, 'code' => $code, 'title' => ReturnFormBuilder::title($code), 'status' => $row === null ? 'not_generated' : (string) $row->status,
                'generated_at' => $row?->generated_at === null ? null : (string) $row->generated_at, 'reviewed_at' => $row?->reviewed_at === null ? null : (string) $row->reviewed_at,
                'filed_on' => $row?->filed_on === null ? null : (string) $row->filed_on, 'filing_reference' => $row?->filing_reference === null ? null : (string) $row->filing_reference, 'form' => $form];
        }

        return $forms;
    }
}
