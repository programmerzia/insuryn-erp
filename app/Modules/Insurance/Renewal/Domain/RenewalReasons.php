<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Domain;

/**
 * Why a policy was not renewed (design §4 "lapsed/non-renewed reasons captured for the retention report", slice R9, A-132). People choose from the configured
 * list `erp.renewals.lapse_reasons` (`other` needs a note); the system closes a register row with its own reasons.
 */
final class RenewalReasons
{
    /** The policy expired without a renewal and nobody recorded a reason. */
    public const NO_RESPONSE = 'no_response';
    /** The policy was cancelled before it expired. */
    public const POLICY_CANCELLED = 'policy_cancelled';
    /** The policy lapsed for non-payment before it expired. */
    public const POLICY_LAPSED = 'policy_lapsed';
    public const OTHER = 'other';

    /** @return array<string, array{en: string, bn: string}> the reasons people may choose */
    public static function choices(): array
    {
        $choices = [];
        foreach ((array) config('erp.renewals.lapse_reasons', []) as $code => $labels) {
            if (is_string($code) && is_array($labels)) {
                $choices[$code] = ['en' => (string) ($labels['en'] ?? $code), 'bn' => (string) ($labels['bn'] ?? $labels['en'] ?? $code)];
            }
        }

        return $choices;
    }

    public static function label(?string $code, string $locale = 'en'): ?string
    {
        if ($code === null) {
            return null;
        }
        $system = [
            self::POLICY_CANCELLED => ['en' => 'Policy cancelled', 'bn' => 'পলিসি বাতিল'],
            self::POLICY_LAPSED => ['en' => 'Lapsed for non-payment', 'bn' => 'প্রিমিয়াম অনাদায়ে ল্যাপস'],
        ];
        $labels = self::choices()[$code] ?? $system[$code] ?? null;

        return $labels === null ? $code : ($locale === 'bn' ? $labels['bn'] : $labels['en']);
    }
}
