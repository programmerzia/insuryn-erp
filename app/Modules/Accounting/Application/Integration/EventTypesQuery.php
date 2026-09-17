<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Application\PostingRuleRepository;

/** Lists event types the posting engine knows from versioned JSON rules. */
final class EventTypesQuery
{
    public function __construct(private readonly PostingRuleRepository $rules) {}

    /**
     * Each type with what the boundary (ExternalEventValidator) requires: the rule's dimensions and the payload fields its amounts read
     * (`optional` when the rule defaults them).
     *
     * @return list<array{event_type: string, rule_code: string, version: int, effective_from: string, required_dimensions: list<string>, payload_fields: list<array{field: string, optional: bool}>}>
     */
    public function list(): array
    {
        $best = [];
        foreach ($this->rules->all() as $rule) {
            $key = $rule->eventType;
            if (! isset($best[$key]) || $rule->version > $best[$key]['version']) {
                $best[$key] = [
                    'event_type' => $rule->eventType,
                    'rule_code' => $rule->code,
                    'version' => $rule->version,
                    'effective_from' => $rule->effectiveFrom->toDateString(),
                    'required_dimensions' => $rule->requiredDimensions,
                    'payload_fields' => array_map(fn (string $field, bool $optional): array => ['field' => $field, 'optional' => $optional],
                        array_keys(ExternalEventValidator::payloadFields($rule)), array_values(ExternalEventValidator::payloadFields($rule))),
                ];
            }
        }
        ksort($best);

        return array_values($best);
    }
}
