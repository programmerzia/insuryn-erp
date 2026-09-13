<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Compensation;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;

/** A compensation rule to add to a scheme (Distribution design note §1 compensation_rules). Rates in basis points of the basis. */
final readonly class CompensationRuleRequest
{
    public function __construct(
        public string $basis,
        public int $policyYearFrom,
        public int $policyYearTo,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo = null,
        public ?string $productId = null,
        public ?string $producerType = null,
        public ?string $levelCode = null,
        public int $rateBp = 0,
        public int $overrideRateBp = 0,
        public ?int $capBp = null,
        public ?int $minPersistencyBp = null,
        public bool $renewalRequiresValidLicence = true,
        public bool $paysAfterTermination = false,
    ) {}

    /** @param array<string, mixed> $fields */
    public static function fromArray(array $fields): self
    {
        $int = fn (string $key, ?int $default = null): ?int => array_key_exists($key, $fields) && $fields[$key] !== null ? (int) $fields[$key] : $default;
        $string = fn (string $key): ?string => isset($fields[$key]) && $fields[$key] !== '' ? (string) $fields[$key] : null;
        if (! isset($fields['basis'], $fields['policy_year_from'], $fields['policy_year_to'], $fields['effective_from'])) {
            throw new BusinessRuleViolation('RULE_INVALID', 'A rule needs a basis, policy years and an effective date.');
        }

        return new self((string) $fields['basis'], (int) $fields['policy_year_from'], (int) $fields['policy_year_to'], CarbonImmutable::parse((string) $fields['effective_from']),
            $string('effective_to') === null ? null : CarbonImmutable::parse((string) $fields['effective_to']), $string('product_id'), $string('producer_type'), $string('level_code'),
            (int) $int('rate_bp', 0), (int) $int('override_rate_bp', 0), $int('cap_bp'), $int('min_persistency_bp'),
            (bool) ($fields['renewal_requires_valid_licence'] ?? true), (bool) ($fields['pays_after_termination'] ?? false));
    }
}
