<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Expressions;

use App\Modules\Accounting\Exceptions\PostingFailedException;

/**
 * Read-only view of event data for posting-rule expressions (design §3.2).
 *
 * Symfony ExpressionLanguage resolves `payload.amount` as property access on an object, so event
 * arrays are exposed through this scope. Reading an absent field is a business failure of the event
 * (never a transient error to retry); `payload.field ?? 0` stays available for optional fields.
 */
final readonly class ExpressionScope
{
    /** @param array<array-key, mixed> $fields */
    private function __construct(
        private string $path,
        private array $fields,
        private string $missingFieldReason,
    ) {}

    /**
     * Variables for evaluating a rule expression against one accounting event.
     *
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed> $dimensions
     * @param array<array-key, mixed>|null $item D-100: the current item of a `for_each` line group, readable as `item.*`
     * @return array{payload: self, dims: self, item?: self}
     */
    public static function forEvent(array $payload, array $dimensions, ?array $item = null): array
    {
        return [
            'payload' => new self('payload', $payload, 'PAYLOAD_FIELD_MISSING'),
            'dims' => new self('dims', $dimensions, 'DIMENSION_MISSING'),
            ...($item === null ? [] : ['item' => new self('item', $item, 'PAYLOAD_FIELD_MISSING')]),
        ];
    }

    public function __get(string $field): mixed
    {
        if (! array_key_exists($field, $this->fields)) {
            throw new PostingFailedException($this->missingFieldReason, "Expression reads '{$this->path}.{$field}', which the event does not provide");
        }

        $value = $this->fields[$field];

        return is_array($value) && ! array_is_list($value)
            ? new self("{$this->path}.{$field}", $value, $this->missingFieldReason)
            : $value;
    }

    public function __isset(string $field): bool
    {
        return isset($this->fields[$field]);
    }
}
