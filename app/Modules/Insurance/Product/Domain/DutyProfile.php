<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/**
 * Phase 3 design §1 product_versions.duty_profile: which duties (vat, stamp, levy — values in the `duties` table) apply to a product version.
 * Shape `{"exclude": ["levy"]}`. ASSUMPTION A-66: every duty in force for the product's class applies unless the version excludes it, so a
 * product never silently goes without VAT or stamp duty.
 */
final readonly class DutyProfile
{
    public const DUTY_CODES = ['vat', 'stamp', 'levy'];

    /** @param list<string> $exclude */
    private function __construct(public array $exclude) {}

    /**
     * @param array<mixed>|null $profile
     *
     * @throws BusinessRuleViolation DUTY_PROFILE_INVALID
     */
    public static function fromArray(?array $profile): self
    {
        if ($profile === null || $profile === []) {
            return new self([]);
        }
        $unknown = array_diff(array_keys($profile), ['exclude']);
        $exclude = $profile['exclude'] ?? [];
        if ($unknown !== [] || ! is_array($exclude) || ! array_is_list($exclude) || array_diff($exclude, self::DUTY_CODES) !== []) {
            throw new BusinessRuleViolation('DUTY_PROFILE_INVALID', 'A duty profile is {"exclude": [...]} listing vat, stamp or levy.');
        }

        return new self(array_values(array_unique(array_map('strval', $exclude))));
    }

    public function applies(string $dutyCode): bool
    {
        return ! in_array($dutyCode, $this->exclude, true);
    }

    /** @return array{exclude: list<string>} */
    public function toArray(): array
    {
        return ['exclude' => $this->exclude];
    }
}
