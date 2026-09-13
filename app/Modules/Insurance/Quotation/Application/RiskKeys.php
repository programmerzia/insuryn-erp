<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Application;

/**
 * Phase 3 design §5 "duplicate risk check (same vehicle reg / property address active elsewhere)": the normalised identity of a risk, as keys such as
 * `motor:registration_no:DHAKAMETROGA123456`. Which risk fields identify a risk per class is configuration (erp.underwriting.duplicate_keys,
 * ASSUMPTION A-88). Normalising keeps letters and digits only, upper-cased, so "Dhaka Metro-GA 12-3456" and "DHAKA METRO GA 123456" are the same vehicle
 * and "House 12, Road 5" matches "house 12 road 5". Values shorter than three characters are ignored (too weak to call a duplicate).
 */
final class RiskKeys
{
    /**
     * @param array<string, mixed> $riskInputs
     * @return list<string>
     */
    public static function for(?string $classCode, array $riskInputs): array
    {
        if ($classCode === null) {
            return [];
        }
        /** @var array<string, list<string>> $fields */
        $fields = (array) config('erp.underwriting.duplicate_keys', []);
        $keys = [];
        foreach ($fields[$classCode] ?? [] as $field) {
            $value = $riskInputs[$field] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $normalised = self::normalise((string) $value);
            if (mb_strlen($normalised) >= 3) {
                $keys[] = "{$classCode}:{$field}:{$normalised}";
            }
        }

        return $keys;
    }

    public static function normalise(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value));
    }
}
