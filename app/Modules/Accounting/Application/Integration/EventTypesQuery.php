<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Application\PostingRuleRepository;

/** Lists event types the posting engine knows from versioned JSON rules. */
final class EventTypesQuery
{
    public function __construct(private readonly PostingRuleRepository $rules) {}

    /** @return list<array{event_type: string, rule_code: string, version: int, effective_from: string}> */
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
                ];
            }
        }
        ksort($best);

        return array_values($best);
    }
}
