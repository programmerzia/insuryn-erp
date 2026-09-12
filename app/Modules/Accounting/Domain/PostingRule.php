<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use Carbon\CarbonImmutable;

/**
 * Immutable in-memory representation of a rule from resources/posting-rules/*.json (design §3.2).
 * DECISION (MVP): rules are files, versioned in git. LATER: stored in DB with an editor UI.
 *
 * @phpstan-type Line array{role: string, side: 'debit'|'credit', amount: string, dims?: array<string,string>, memo?: string}
 */
final readonly class PostingRule
{
    /**
     * @param list<string> $books
     * @param list<Line> $lines
     * @param list<string> $requiredDimensions
     * @param list<string> $validations
     * @param array{product_codes?: list<string>, lob?: list<string>, channels?: list<string>} $appliesTo
     */
    public function __construct(
        public string $code,
        public int $version,
        public string $eventType,
        public array $appliesTo,
        public ?string $condition,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo,
        public array $books,
        public array $lines,
        public array $requiredDimensions,
        public array $validations,
        public string $idempotencyKeyTemplate,
        public string $roundingResidualRole = 'rounding_difference',
    ) {}

    /** @param array<string,mixed> $j */
    public static function fromArray(array $j): self
    {
        return new self(
            code: (string) $j['code'],
            version: (int) $j['version'],
            eventType: (string) $j['event_type'],
            appliesTo: $j['applies_to'] ?? [],
            condition: $j['condition'] ?? null,
            effectiveFrom: CarbonImmutable::parse((string) $j['effective_from']),
            effectiveTo: isset($j['effective_to']) ? CarbonImmutable::parse((string) $j['effective_to']) : null,
            books: $j['books'] ?? ['LOCAL'],
            lines: $j['lines'],
            requiredDimensions: $j['dimensions']['required'] ?? [],
            validations: $j['validation'] ?? [],
            idempotencyKeyTemplate: $j['idempotency']['key'] ?? ($j['event_type'].':{source_id}'),
            roundingResidualRole: $j['rounding']['residual_role'] ?? 'rounding_difference',
        );
    }

    public function isEffectiveOn(CarbonImmutable $date): bool
    {
        return $date->greaterThanOrEqualTo($this->effectiveFrom)
            && ($this->effectiveTo === null || $date->lessThan($this->effectiveTo));
    }

    /** Higher = more specific. Wildcard-only rules score 0. */
    public function specificity(): int
    {
        $s = 0;
        foreach (['product_codes', 'lob', 'channels'] as $k) {
            $v = $this->appliesTo[$k] ?? ['*'];
            if ($v !== ['*']) {
                $s++;
            }
        }
        return $s;
    }
}
