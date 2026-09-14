<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Application;

use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reinsurers and treaties (ri.manage_treaties). A treaty covers one product class for one underwriting year: quota share (cession %) or surplus (retention
 * and lines), the commission on ceded premium, the SBC compulsory share, and its participants, whose shares add up to 100%. One active treaty per
 * entity, class and year. Changing a treaty applies to cessions computed afterwards; cessions already written stay (A-256).
 */
final class TreatyService
{
    public const MANAGE = 'ri.manage_treaties';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PartyService $parties,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation RI_REINSURER_CODE_TAKEN | RI_STATE_REINSURER_EXISTS */
    public function registerReinsurer(string $name, string $code, ?string $rating, ?string $ratingAgency, string $country, bool $isStateReinsurer, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        $code = strtoupper(trim($code));
        if (DB::table('reinsurers')->where('code', $code)->exists()) {
            throw new BusinessRuleViolation('RI_REINSURER_CODE_TAKEN', 'Another reinsurer already uses this code. Choose a different code.');
        }
        if ($isStateReinsurer && DB::table('reinsurers')->where('is_state_reinsurer', true)->exists()) {
            throw new BusinessRuleViolation('RI_STATE_REINSURER_EXISTS', 'The state reinsurer (Sadharan Bima Corporation) is already set up.');
        }

        return DB::transaction(function () use ($name, $code, $rating, $ratingAgency, $country, $isStateReinsurer, $actorUserId): string {
            $party = $this->parties->createReinsurer(trim($name), $actorUserId, self::MANAGE);
            $id = (string) Str::uuid7();
            DB::table('reinsurers')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'party_id' => $party->id, 'code' => $code, 'rating' => $rating, 'rating_agency' => $ratingAgency,
                'country' => strtoupper($country), 'is_state_reinsurer' => $isStateReinsurer, 'status' => 'active', 'created_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('reinsurer.registered', AuditSubject::of('reinsurer', $id), null, ['code' => $code, 'name' => $name, 'rating' => $rating, 'country' => $country,
                'is_state_reinsurer' => $isStateReinsurer], null, self::MANAGE, Actor::user($actorUserId));

            return $id;
        });
    }

    /**
     * Creates ($treatyId null) or changes a treaty with its participants.
     *
     * @param array{entity_id: string, code: string, name: string, class_code: string, underwriting_year: int, period_from: string, period_to: string, type: string,
     *     cession_bp: int|null, retention_minor: int|null, lines: int|null, commission_bp: int, sbc_share_bp: int, currency: string, status: string} $terms
     * @param array<string, int> $participants reinsurer id → share in basis points
     *
     * @throws BusinessRuleViolation RI_TREATY_TYPE_INVALID | RI_TREATY_PARTICIPANTS_INVALID | RI_TREATY_PERIOD_INVALID | RI_TREATY_DUPLICATE
     */
    public function save(?string $treatyId, array $terms, array $participants, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        if ($terms['type'] === 'quota_share' && ($terms['cession_bp'] === null || $terms['cession_bp'] <= 0)) {
            throw new BusinessRuleViolation('RI_TREATY_TYPE_INVALID', 'A quota share treaty needs a cession percentage above zero.');
        }
        if ($terms['type'] === 'surplus' && (($terms['retention_minor'] ?? 0) <= 0 || ($terms['lines'] ?? 0) < 1)) {
            throw new BusinessRuleViolation('RI_TREATY_TYPE_INVALID', 'A surplus treaty needs a retention above zero and at least one line.');
        }
        if (! in_array($terms['type'], ['quota_share', 'surplus'], true)) {
            throw new BusinessRuleViolation('RI_TREATY_TYPE_INVALID', 'Choose quota share or surplus.');
        }
        if ($terms['period_to'] < $terms['period_from']) {
            throw new BusinessRuleViolation('RI_TREATY_PERIOD_INVALID', 'The treaty period must end on or after the day it starts.');
        }
        if ($participants === [] || array_sum($participants) !== 10_000 || min($participants) <= 0
            || DB::table('reinsurers')->whereIn('id', array_keys($participants))->where('is_state_reinsurer', false)->count() !== count($participants)) {
            throw new BusinessRuleViolation('RI_TREATY_PARTICIPANTS_INVALID', 'Treaty reinsurers\' shares must add up to 100%, each above zero. The state reinsurer takes its compulsory share separately.');
        }
        $row = ['entity_id' => $terms['entity_id'], 'code' => strtoupper(trim($terms['code'])), 'name' => trim($terms['name']), 'class_code' => $terms['class_code'],
            'underwriting_year' => $terms['underwriting_year'], 'period_from' => $terms['period_from'], 'period_to' => $terms['period_to'], 'type' => $terms['type'],
            'cession_bp' => $terms['type'] === 'quota_share' ? $terms['cession_bp'] : null, 'retention_minor' => $terms['type'] === 'surplus' ? $terms['retention_minor'] : null,
            'lines' => $terms['type'] === 'surplus' ? $terms['lines'] : null, 'commission_bp' => $terms['commission_bp'], 'sbc_share_bp' => $terms['sbc_share_bp'],
            'currency' => $terms['currency'], 'status' => $terms['status'] === 'inactive' ? 'inactive' : 'active', 'updated_at' => now()];
        $duplicate = $row['status'] === 'active' && DB::table('ri_treaties')->where('entity_id', $row['entity_id'])->where('class_code', $row['class_code'])
            ->where('underwriting_year', $row['underwriting_year'])->where('status', 'active')->when($treatyId !== null, fn ($q) => $q->where('id', '<>', $treatyId))->exists();
        if ($duplicate || DB::table('ri_treaties')->where('code', $row['code'])->when($treatyId !== null, fn ($q) => $q->where('id', '<>', $treatyId))->exists()) {
            throw new BusinessRuleViolation('RI_TREATY_DUPLICATE', 'Another active treaty already covers this class and underwriting year, or uses this code.');
        }

        return DB::transaction(function () use ($treatyId, $row, $participants, $actorUserId): string {
            $before = $treatyId === null ? null : (array) DB::table('ri_treaties')->where('id', $treatyId)->lockForUpdate()->first();
            if ($treatyId === null) {
                $treatyId = (string) Str::uuid7();
                DB::table('ri_treaties')->insert(['id' => $treatyId, 'tenant_id' => TenantContext::id(), 'created_by' => $actorUserId, 'created_at' => now()] + $row);
            } else {
                abort_if($before === [], 404);
                DB::table('ri_treaties')->where('id', $treatyId)->update($row);
                DB::table('ri_treaty_participants')->where('treaty_id', $treatyId)->delete();
            }
            foreach ($participants as $reinsurerId => $shareBp) {
                DB::table('ri_treaty_participants')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'treaty_id' => $treatyId, 'reinsurer_id' => $reinsurerId,
                    'share_bp' => $shareBp, 'created_at' => now()]);
            }
            $this->audit->record($before === null ? 'ri_treaty.created' : 'ri_treaty.changed', AuditSubject::of('ri_treaty', $treatyId), $before,
                $row + ['participants' => $participants], null, self::MANAGE, Actor::user($actorUserId));

            return $treatyId;
        });
    }
}
