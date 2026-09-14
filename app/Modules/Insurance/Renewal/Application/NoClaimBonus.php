<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Application;

use App\Modules\Insurance\Product\Domain\Risk\RiskSchema;
use Illuminate\Support\Facades\DB;

/**
 * Design §4 "re-rating with the current tariff and prior-claims data (NCB)" (slice R9). ASSUMPTION: A-128 — only for a product whose risk schema has the
 * claim-free years field (erp.renewals.ncb_field, `ncb_years` for motor): one year more (up to the field's maximum) when the expiring policy had no claim
 * with a loss date in its period, 0 after one. Every claim except a rejected one counts (a registered claim may still be reserved and paid). The plan's
 * NCB scale turns the years into the discount.
 */
final class NoClaimBonus
{
    /**
     * @param array<string, mixed> $inputs the risk in force on the expiring policy
     * @return array{inputs: array<string, mixed>, before: int|null, after: int|null, claims: int}
     */
    public static function apply(RiskSchema $schema, array $inputs, string $policyId, string $inception, string $expiry): array
    {
        $key = (string) config('erp.renewals.ncb_field', 'ncb_years');
        $field = null;
        foreach ($schema->fields as $candidate) {
            if ($candidate->key === $key) {
                $field = $candidate;
            }
        }
        if ($field === null) {
            return ['inputs' => $inputs, 'before' => null, 'after' => null, 'claims' => 0];
        }
        $claims = DB::table('claims')->where('policy_id', $policyId)->where('status', '<>', 'rejected')->whereBetween('loss_date', [$inception, $expiry])->count();
        $current = $inputs[$key] ?? null;
        $before = is_int($current) ? $current : (is_string($current) && preg_match('/^\d{1,3}$/', $current) === 1 ? (int) $current : 0);
        $after = $claims > 0 ? 0 : $before + 1;
        if ($field->max !== null) {
            $after = min($after, $field->max);
        }
        $inputs[$key] = $after;

        return ['inputs' => $inputs, 'before' => $before, 'after' => $after, 'claims' => $claims];
    }
}
