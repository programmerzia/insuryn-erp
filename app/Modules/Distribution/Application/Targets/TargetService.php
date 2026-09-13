<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Targets;

use App\Modules\Distribution\Domain\Incentives\IncentivePeriod;
use App\Modules\Distribution\Domain\Incentives\IncentiveTiers;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Targets (Distribution design note §1 targets, §4): one value per producer, branch or channel, period and metric — premium and collections in
 * minor units, policies as a count, persistency in basis points. Setting a target again replaces its value; every change is audited.
 */
final class TargetService
{
    private const SUBJECTS = ['producer' => 'producers', 'branch' => 'branches', 'channel' => 'channels'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    public function set(string $subjectType, string $subjectId, string $periodType, CarbonImmutable $periodStart, string $metric, int $value, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, 'agent.manage');
        try {
            $period = IncentivePeriod::starting($periodType, $periodStart);
        } catch (DomainException $invalid) {
            throw new BusinessRuleViolation('TARGET_INVALID', $invalid->getMessage());
        }
        $table = self::SUBJECTS[$subjectType] ?? null;
        if ($table === null || ! in_array($metric, IncentiveTiers::METRICS, true) || $value <= 0) {
            throw new BusinessRuleViolation('TARGET_INVALID', 'A target is for a producer, branch or channel, on premium, policies, persistency or collections, with a positive value.');
        }
        if (! DB::table($table)->where('id', $subjectId)->exists()) {
            throw new BusinessRuleViolation('TARGET_INVALID', "That {$subjectType} does not exist.");
        }

        return DB::transaction(function () use ($subjectType, $subjectId, $period, $metric, $value, $actorUserId): string {
            $key = ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'period_type' => $period->type, 'period_start' => $period->start->toDateString(), 'metric' => $metric];
            $existing = DB::table('targets')->where($key)->lockForUpdate()->first(['id', 'target_value']);
            $id = $existing === null ? (string) Str::uuid7() : (string) $existing->id;
            if ($existing === null) {
                DB::table('targets')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), ...$key, 'target_value' => $value, 'set_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('targets')->where('id', $id)->update(['target_value' => $value, 'set_by' => $actorUserId, 'updated_at' => now()]);
            }
            $this->audit->record('target.set', AuditSubject::of('target', $id), $existing === null ? null : ['target_value' => (int) $existing->target_value],
                [...$key, 'target_value' => $value], null, 'agent.manage', Actor::user($actorUserId));

            return $id;
        });
    }

    /** The target of a subject for a period and metric, or null. */
    public function valueFor(string $subjectType, string $subjectId, string $periodType, CarbonImmutable $periodStart, string $metric): ?int
    {
        $value = DB::table('targets')->where(['subject_type' => $subjectType, 'subject_id' => $subjectId, 'period_type' => $periodType,
            'period_start' => $periodStart->toDateString(), 'metric' => $metric])->value('target_value');

        return $value === null ? null : (int) $value;
    }
}
