<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use App\Modules\Accounting\Domain\Models\JournalLine;

/**
 * The dimensions one journal line carries, keyed by dimension code. Codes with their own
 * journal_lines.dim_* column (design §2.2) are stored there; anything else goes to dims_ext.
 */
final readonly class LineDimensions
{
    private const COLUMN_DIMENSIONS = ['branch', 'product', 'lob', 'channel', 'agent', 'policy', 'claim', 'cost_centre', 'employee', 'customer', 'reinsurer'];

    /** Only selects the rule (§3.2 applies_to); never stored on a line. */
    private const ROUTING_ONLY = ['product_code'];

    /** @param array<string, mixed> $values */
    private function __construct(public array $values) {}

    /**
     * Event dimensions are inherited by every line (§3.2 inherit_from_event); the rule line's own
     * dims override them and are either literals or `payload.<field>` references.
     *
     * @param array<string, mixed> $eventDimensions
     * @param array<string, string> $lineDimensions
     * @param array<string, mixed> $payload
     * @param array<array-key, mixed>|null $item D-100: inside a `for_each` group, `item.<field>` reads the current item
     */
    public static function forRuleLine(array $eventDimensions, array $lineDimensions, array $payload, ?array $item = null): self
    {
        $values = $eventDimensions;
        foreach ($lineDimensions as $code => $expression) {
            if ($item !== null && str_starts_with($expression, 'item.')) {
                // An item without the value keeps the event's dimension (a bill line without a claim).
                $values[$code] = $item[substr($expression, 5)] ?? $values[$code] ?? null;
                continue;
            }
            $values[$code] = str_starts_with($expression, 'payload.') ? ($payload[substr($expression, 8)] ?? null) : $expression;
        }

        return new self(array_diff_key($values, array_flip(self::ROUTING_ONLY)));
    }

    /**
     * Dimensions entered directly on a line (manual and opening journals).
     *
     * @param array<string, mixed> $values dimension code → value
     */
    public static function fromValues(array $values): self
    {
        return new self(array_diff_key($values, array_flip(self::ROUTING_ONLY)));
    }

    /** Exactly what a posted line stores, so a reversal mirrors its dimensions (§2.3). */
    public static function fromStoredLine(JournalLine $line): self
    {
        $values = [];
        foreach (self::COLUMN_DIMENSIONS as $code) {
            $values[$code] = $line->getAttribute('dim_'.$code);
        }
        /** @var array<string, mixed> $extension */
        $extension = $line->dims_ext ?? [];

        return new self($values + $extension);
    }

    /** @return array<string, mixed> every dim_* column (null when absent) plus dims_ext as an array or null */
    public function toColumns(): array
    {
        $columns = [];
        foreach (self::COLUMN_DIMENSIONS as $code) {
            $columns['dim_'.$code] = $this->values[$code] ?? null;
        }
        $extension = array_diff_key($this->values, array_flip(self::COLUMN_DIMENSIONS));
        $columns['dims_ext'] = $extension === [] ? null : $extension;

        return $columns;
    }
}
