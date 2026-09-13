<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Compensation;

use Illuminate\Support\Facades\DB;

/** Read-only scheme lookups for other contexts (a product version names the scheme its policies are paid under). */
final class CompensationSchemeDirectory
{
    public function exists(string $schemeId): bool
    {
        return DB::table('compensation_schemes')->where('id', $schemeId)->exists();
    }

    /** @return array{id: string, code: string, name: string, mode: string, effective_from: string, effective_to: string|null, compliance_profile: array<mixed>, withholding_jurisdiction: string|null, withholding_tax_type: string|null, levels: list<array{code: string, rank: int, label: string}>, rules: list<array<string, mixed>>}|null */
    public function describe(string $schemeId): ?array
    {
        $scheme = DB::table('compensation_schemes')->where('id', $schemeId)->first();
        if ($scheme === null) {
            return null;
        }

        return ['id' => (string) $scheme->id, 'code' => (string) $scheme->code, 'name' => (string) $scheme->name, 'mode' => (string) $scheme->mode,
            'effective_from' => (string) $scheme->effective_from, 'effective_to' => $scheme->effective_to === null ? null : (string) $scheme->effective_to,
            'compliance_profile' => (array) json_decode((string) $scheme->compliance_profile, true),
            'withholding_jurisdiction' => $scheme->withholding_jurisdiction === null ? null : (string) $scheme->withholding_jurisdiction,
            'withholding_tax_type' => $scheme->withholding_tax_type === null ? null : (string) $scheme->withholding_tax_type,
            'levels' => array_values(DB::table('hierarchy_levels')->where('scheme_id', $schemeId)->orderBy('rank')->get(['level_code', 'rank', 'label'])
                ->map(fn (\stdClass $l): array => ['code' => (string) $l->level_code, 'rank' => (int) $l->rank, 'label' => (string) $l->label])->all()),
            'rules' => array_values(DB::table('compensation_rules')->where('scheme_id', $schemeId)->orderBy('policy_year_from')->orderBy('created_at')->get()
                ->map(fn (\stdClass $r): array => array_diff_key((array) $r, ['tenant_id' => true]))->all()),
        ];
    }
}
