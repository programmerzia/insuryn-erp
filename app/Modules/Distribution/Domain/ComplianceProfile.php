<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain;

use App\Modules\Distribution\Domain\Enums\ProducerType;
use DomainException;

/**
 * Distribution design note §0 ASSUMPTION "regulatory caps and the zero-commission rule are compliance rules on compensation schemes":
 * - allowed_producer_types: null = every type;
 * - non_life_commission_allowed: OPEN 1 → ASSUMPTION A-18, false until configured (commission on non-life products is disabled);
 * - caps: Σ commission for a policy (direct and every override) as basis points of the basis, by product (null = every product) and policy year.
 * Where several caps match, the lowest applies.
 */
final readonly class ComplianceProfile
{
    /**
     * @param list<string>|null $allowedProducerTypes
     * @param list<array{product_id: string|null, policy_year_from: int, policy_year_to: int, max_total_bp: int}> $caps
     */
    private function __construct(
        public ?array $allowedProducerTypes,
        public bool $nonLifeCommissionAllowed,
        public array $caps,
    ) {}

    /**
     * @param array<mixed> $profile
     *
     * @throws DomainException describing the first problem
     */
    public static function fromArray(array $profile): self
    {
        $unknown = array_diff(array_keys($profile), ['allowed_producer_types', 'non_life_commission_allowed', 'caps']);
        if ($unknown !== []) {
            throw new DomainException('Unknown compliance profile setting: '.implode(', ', $unknown).'.');
        }
        $types = $profile['allowed_producer_types'] ?? null;
        if ($types !== null) {
            if (! is_array($types) || $types === [] || array_diff($types, array_column(ProducerType::cases(), 'value')) !== []) {
                throw new DomainException('Allowed producer types must be a non-empty list of agent, agency_org, bdo, broker, partner.');
            }
            $types = array_values(array_map('strval', $types));
        }
        $nonLife = $profile['non_life_commission_allowed'] ?? false;
        if (! is_bool($nonLife)) {
            throw new DomainException('non_life_commission_allowed must be true or false.');
        }

        $caps = [];
        foreach ((array) ($profile['caps'] ?? []) as $cap) {
            if (! is_array($cap) || ! isset($cap['policy_year_from'], $cap['policy_year_to'], $cap['max_total_bp'])
                || ! is_int($cap['policy_year_from']) || ! is_int($cap['policy_year_to']) || ! is_int($cap['max_total_bp'])
                || $cap['policy_year_from'] < 1 || $cap['policy_year_to'] < $cap['policy_year_from'] || $cap['policy_year_to'] > 99
                || $cap['max_total_bp'] < 0 || $cap['max_total_bp'] > 10_000
                || (isset($cap['product_id']) && ! is_string($cap['product_id']))) {
                throw new DomainException('Each cap needs policy_year_from ≤ policy_year_to within 1–99, max_total_bp between 0 and 10000, and optionally a product_id.');
            }
            $caps[] = ['product_id' => $cap['product_id'] ?? null, 'policy_year_from' => $cap['policy_year_from'], 'policy_year_to' => $cap['policy_year_to'], 'max_total_bp' => $cap['max_total_bp']];
        }

        return new self($types, $nonLife, $caps);
    }

    public function allows(string $producerType): bool
    {
        return $this->allowedProducerTypes === null || in_array($producerType, $this->allowedProducerTypes, true);
    }

    /** The lowest cap in force for a policy year of a product; a rule for every product ($productId null) meets every cap of that year. */
    public function capFor(?string $productId, int $policyYear): ?int
    {
        $matching = array_filter($this->caps, fn (array $cap): bool => $policyYear >= $cap['policy_year_from'] && $policyYear <= $cap['policy_year_to']
            && ($cap['product_id'] === null || $productId === null || $cap['product_id'] === $productId));

        return $matching === [] ? null : min(array_column($matching, 'max_total_bp'));
    }

    /** @return array{allowed_producer_types: list<string>|null, non_life_commission_allowed: bool, caps: list<array{product_id: string|null, policy_year_from: int, policy_year_to: int, max_total_bp: int}>} */
    public function toArray(): array
    {
        return ['allowed_producer_types' => $this->allowedProducerTypes, 'non_life_commission_allowed' => $this->nonLifeCommissionAllowed, 'caps' => $this->caps];
    }
}
