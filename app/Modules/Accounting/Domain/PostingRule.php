<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use Carbon\CarbonImmutable;

/**
 * Immutable in-memory representation of a rule from resources/posting-rules/*.json (design §3.2).
 * DECISION (MVP): rules are files, versioned in git. LATER: stored in DB with an editor UI.
 *
 * @phpstan-type Line array{role: string, side: 'debit'|'credit', amount: string, dims?: array<string,string>, memo?: string, account?: string}
 * @phpstan-type LineGroup array{for_each: string, lines: list<Line>}
 *
 * Addendum v2 §B.2.1 (PD-3, DECISION D-100): a line may be a group `{"for_each": "payload.<list>", "lines": [...]}` repeated for every item of
 * the payload list; inside it `item.*` is available to amounts, dims and `account` (PD-4: `item.<field>` naming the account for an overridable role).
 */
final readonly class PostingRule
{
    /**
     * @param list<string> $books
     * @param list<Line|LineGroup> $lines
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

    /** @return list<string> every role the rule's lines use, inside line groups too */
    public function roles(): array
    {
        $roles = [];
        foreach ($this->lines as $line) {
            foreach (isset($line['for_each']) ? $line['lines'] : [$line] as $inner) {
                $roles[] = $inner['role'];
            }
        }

        return array_values(array_unique($roles));
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
