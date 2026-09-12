<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use App\Modules\Accounting\Exceptions\PostingFailedException;

/**
 * Payload constraints the posting rules cannot express by themselves. D-04: CLAIM_RESERVED records an
 * initial reserve and must be positive; decreases are CLAIM_RESERVE_ADJUSTED with a negative delta.
 */
final class EventPayloadValidator
{
    /** event type => payload fields that must be positive integers */
    private const POSITIVE_FIELDS = [
        'CLAIM_RESERVED' => ['amount'],
    ];

    /**
     * @param array<string, mixed> $payload
     *
     * @throws PostingFailedException AMOUNT_NOT_POSITIVE
     */
    public static function assertValid(string $eventType, array $payload): void
    {
        foreach (self::POSITIVE_FIELDS[$eventType] ?? [] as $field) {
            $value = $payload[$field] ?? null;
            if (! is_int($value) || $value <= 0) {
                throw new PostingFailedException('AMOUNT_NOT_POSITIVE', "{$eventType} requires payload.{$field} > 0, got ".var_export($value, true));
            }
        }
    }
}
