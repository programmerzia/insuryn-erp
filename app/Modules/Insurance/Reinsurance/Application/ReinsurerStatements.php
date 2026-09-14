<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Application;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The quarterly reinsurance account statement per reinsurer (ri.view to read, ri.manage_treaties to prepare): opening balance, premium ceded, commission,
 * claims recoverable and the closing balance due to (positive) or from (negative) the reinsurer, with its share of outstanding claims at the quarter end.
 * Calendar quarters (A-260). Preparing again replaces the figures of the same statement (its number stays), so a late cession is picked up.
 */
final class ReinsurerStatements
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly DocumentNumberer $numbers,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{premium_minor: int, commission_minor: int, claims_recoverable_minor: int, net_minor: int}
     */
    public static function movements(string $entityId, string $reinsurerId, string $from, string $to): array
    {
        $cessions = DB::table('ri_cessions')->where('entity_id', $entityId)->where('reinsurer_id', $reinsurerId)->whereBetween('accounting_date', [$from, $to])
            ->selectRaw('coalesce(sum(premium_minor), 0) as premium, coalesce(sum(commission_minor), 0) as commission')->first();
        $recoverable = (int) DB::table('ri_claim_shares')->where('entity_id', $entityId)->where('reinsurer_id', $reinsurerId)->where('kind', 'recoverable')
            ->whereBetween('recorded_on', [$from, $to])->sum('amount_minor');
        $premium = (int) ($cessions->premium ?? 0);
        $commission = (int) ($cessions->commission ?? 0);

        return ['premium_minor' => $premium, 'commission_minor' => $commission, 'claims_recoverable_minor' => $recoverable, 'net_minor' => $premium - $commission - $recoverable];
    }

    /** @throws BusinessRuleViolation RI_REINSURER_INACTIVE | RI_QUARTER_INVALID */
    public function prepare(string $entityId, string $reinsurerId, int $year, int $quarter, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, TreatyService::MANAGE);
        if (! DB::table('reinsurers')->where('id', $reinsurerId)->exists()) {
            throw new BusinessRuleViolation('RI_REINSURER_INACTIVE', 'Choose an active reinsurer.');
        }
        if ($quarter < 1 || $quarter > 4 || $year < 2000 || $year > 2100) {
            throw new BusinessRuleViolation('RI_QUARTER_INVALID', 'Choose a quarter from 1 to 4 of a calendar year.');
        }
        $from = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1));
        $to = $from->addMonths(3)->subDay();
        $opening = self::movements($entityId, $reinsurerId, '1900-01-01', $from->subDay()->toDateString())['net_minor'];
        $movements = self::movements($entityId, $reinsurerId, $from->toDateString(), $to->toDateString());
        $outstanding = (int) DB::table('ri_claim_shares')->where('entity_id', $entityId)->where('reinsurer_id', $reinsurerId)->where('recorded_on', '<=', $to->toDateString())
            ->selectRaw("coalesce(sum(case when kind = 'reserve' then amount_minor else -from_reserve_minor end), 0) as outstanding")->value('outstanding');
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
        $figures = ['period_from' => $from->toDateString(), 'period_to' => $to->toDateString(), 'opening_balance_minor' => $opening, 'premium_minor' => $movements['premium_minor'],
            'commission_minor' => $movements['commission_minor'], 'claims_recoverable_minor' => $movements['claims_recoverable_minor'],
            'closing_balance_minor' => $opening + $movements['net_minor'], 'outstanding_claims_share_minor' => $outstanding, 'currency' => $currency];

        return DB::transaction(function () use ($entityId, $reinsurerId, $year, $quarter, $figures, $actorUserId, $to): string {
            $existing = DB::table('ri_statements')->where('entity_id', $entityId)->where('reinsurer_id', $reinsurerId)->where('year', $year)->where('quarter', $quarter)->lockForUpdate()->first(['id']);
            if ($existing !== null) {
                DB::table('ri_statements')->where('id', $existing->id)->update($figures + ['prepared_by' => $actorUserId, 'updated_at' => now()]);
                $id = (string) $existing->id;
            } else {
                $number = $this->numbers->reserve(new DocumentNumberScope($entityId, null, 'ri_statement', 'RIS', $to), $actorUserId);
                $id = (string) Str::uuid7();
                DB::table('ri_statements')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'number' => $number->number, 'reinsurer_id' => $reinsurerId,
                    'year' => $year, 'quarter' => $quarter, 'prepared_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now()] + $figures);
                $this->numbers->markUsed($number->id, 'ri_statement', $id);
            }
            $this->audit->record('ri_statement.prepared', AuditSubject::of('ri_statement', $id), null, $figures + ['year' => $year, 'quarter' => $quarter], null, TreatyService::MANAGE, Actor::user($actorUserId));

            return $id;
        });
    }
}
