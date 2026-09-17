<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Application\Posting\PostingContextLoader;
use App\Modules\Accounting\Application\PostingRuleRepository;
use App\Modules\Accounting\Domain\Models\Book;
use App\Modules\Accounting\Domain\PostingRule;
use App\Modules\Accounting\Exceptions\PostingFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * The ledger API boundary (docs/plan/api-accounting-v1.md): everything a posting rule is known to refuse is refused here, as a 422 with the
 * field and a reason, before an intake row or an accounting event exists. The kernel (SubmitAccountingEvent, PostingEngine) is unchanged and
 * still checks everything again.
 *
 * Dimension values the kernel expects (JournalDraftBuilder, LineDimensions): `branch` is the branch id (journal_lines.dim_branch is a uuid),
 * `product`, `policy`, `customer`, `claim`, `agent`, `cost_centre`, `employee` and `reinsurer` are uuids too; `product_code`, `lob` and
 * `channel` are strings. Callers post codes and their own references instead:
 *  - `branch` and `product` accept the uuid or the code (case-insensitive) and are rewritten to the uuid;
 *  - the other uuid dimensions accept any non-empty string. A value that is not a uuid is kept as `<name>_ref` (stored in the line's dims_ext,
 *    so the caller's own id is on the journal for drill-down) and `<name>` becomes a stable uuid5 of it, so the same reference always maps to
 *    the same dimension value across events.
 */
final class ExternalEventValidator
{
    /** Dimensions journal_lines stores in a uuid column (LineDimensions::COLUMN_DIMENSIONS minus the string-typed ones). */
    private const UUID_DIMENSIONS = ['product', 'agent', 'policy', 'claim', 'cost_centre', 'employee', 'customer', 'reinsurer'];

    /** Namespace for the uuid5 of a caller reference (fixed, so the mapping is stable across tenants and deployments). */
    private const REFERENCE_NAMESPACE = '6f0c1a0e-4c0b-5c1c-9c0e-1ed6e70ef000';

    public function __construct(
        private readonly PostingRuleRepository $rules,
        private readonly PostingContextLoader $contexts,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $dimensions
     * @param bool $checkPeriod the transaction date's period must accept a posting (submit); a preview is a dry run and skips it
     *
     * @throws LedgerRequestRejected
     */
    public function validate(
        string $entityId,
        string $eventType,
        CarbonImmutable $transactionDate,
        CarbonImmutable $effectiveDate,
        string $currency,
        array $payload,
        array $dimensions,
        bool $checkPeriod = true,
    ): ValidatedExternalEvent {
        $this->assertCurrency($entityId, $currency);
        $rule = $this->resolveRule($eventType, $effectiveDate, $payload, $dimensions);
        $dimensions = $this->resolveDimensions($entityId, $rule, $dimensions);
        $this->assertPayload($rule, $payload);
        if ($checkPeriod) {
            $this->assertPeriodOpen($entityId, $rule, $transactionDate);
        }

        return new ValidatedExternalEvent($rule, $dimensions);
    }

    private function assertCurrency(string $entityId, string $currency): void
    {
        $base = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
        if ($base !== '' && strtoupper($currency) !== $base) {
            throw LedgerRequestRejected::field('currency', "This ledger keeps {$base}; FX is not supported", 'CURRENCY_MISMATCH');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $dimensions
     */
    private function resolveRule(string $eventType, CarbonImmutable $effectiveDate, array $payload, array $dimensions): PostingRule
    {
        $known = false;
        foreach ($this->rules->all() as $rule) {
            if ($rule->eventType === $eventType) {
                $known = true;
                break;
            }
        }
        if (! $known) {
            throw LedgerRequestRejected::field('event_type', "Unknown event type '{$eventType}'. GET /api/v1/event-types lists the supported ones.", 'UNKNOWN_EVENT_TYPE');
        }
        try {
            return $this->rules->resolve($eventType, $effectiveDate, $payload, $dimensions);
        } catch (PostingFailedException $e) {
            // NO_RULE (nothing effective on the date or for the product/lob/channel), AMBIGUOUS_RULE, or a rule condition reading a missing field.
            $field = $e->reasonCode === 'PAYLOAD_FIELD_MISSING' ? 'payload' : ($e->reasonCode === 'DIMENSION_MISSING' ? 'dimensions' : 'event_type');
            throw LedgerRequestRejected::field($field, $e->getMessage(), $e->reasonCode);
        }
    }

    /**
     * @param array<string, mixed> $dimensions
     * @return array<string, mixed>
     */
    private function resolveDimensions(string $entityId, PostingRule $rule, array $dimensions): array
    {
        $required = $rule->requiredDimensions;
        // Tenant requirements on top of the rule's (TenantDimensionRequirements, checked again by the engine).
        foreach (DB::table('dimension_requirements')->where('event_type', $rule->eventType)->where('required', true)->pluck('dimension_code') as $code) {
            $required[] = (string) $code;
        }
        foreach (array_unique($required) as $name) {
            $value = $dimensions[$name] ?? null;
            if ($value === null || $value === '' || (! is_string($value) && ! is_int($value))) {
                throw LedgerRequestRejected::field("dimensions.{$name}", "The {$name} dimension is required for {$rule->eventType}", 'DIMENSION_MISSING');
            }
        }

        if (isset($dimensions['branch']) && is_scalar($dimensions['branch'])) {
            $dimensions['branch'] = $this->branchId($entityId, (string) $dimensions['branch']);
        }
        if (isset($dimensions['product']) && is_scalar($dimensions['product'])) {
            $dimensions['product'] = $this->productId((string) $dimensions['product']);
        }
        foreach (self::UUID_DIMENSIONS as $name) {
            $value = $dimensions[$name] ?? null;
            if ($name === 'product' || ! is_scalar($value) || $value === '' || Str::isUuid((string) $value)) {
                continue;
            }
            $dimensions[$name.'_ref'] = (string) $value;
            $dimensions[$name] = Uuid::uuid5(self::REFERENCE_NAMESPACE, $name.':'.(string) $value)->toString();
        }

        return $dimensions;
    }

    private function branchId(string $entityId, string $value): string
    {
        $branches = DB::table('branches')->where('entity_id', $entityId)->orderBy('code')->get(['id', 'code']);
        foreach ($branches as $branch) {
            if ((string) $branch->id === $value || strcasecmp((string) $branch->code, $value) === 0) {
                return (string) $branch->id;
            }
        }
        $codes = $branches->map(fn (object $b): string => (string) $b->code)->implode(', ');
        throw LedgerRequestRejected::field('dimensions.branch', "Unknown branch '{$value}'. Branches: {$codes}", 'UNKNOWN_BRANCH');
    }

    private function productId(string $value): string
    {
        $products = DB::table('products')->orderBy('code')->get(['id', 'code']);
        foreach ($products as $product) {
            if ((string) $product->id === $value || strcasecmp((string) $product->code, $value) === 0) {
                return (string) $product->id;
            }
        }
        $codes = $products->map(fn (object $p): string => (string) $p->code)->implode(', ');
        throw LedgerRequestRejected::field('dimensions.product', "Unknown product '{$value}'. Products: {$codes}", 'UNKNOWN_PRODUCT');
    }

    /**
     * Every `payload.<field>` an amount expression of the rule reads must be an integer in minor units; a field followed by `??` is optional
     * (checked only when present). Amounts are not negative except signed deltas (CLAIM_RESERVE_ADJUSTED and the like). A `for_each` group
     * needs its payload list.
     *
     * @param array<string, mixed> $payload
     */
    private function assertPayload(PostingRule $rule, array $payload): void
    {
        foreach (self::payloadFields($rule) as $field => $optional) {
            $value = $payload[$field] ?? null;
            if ($value === null) {
                if ($optional) {
                    continue;
                }
                $label = $field === 'amount' ? 'amount' : "{$field} amount";
                throw LedgerRequestRejected::field("payload.{$field}", "The {$label} is required (minor units, integer)", 'PAYLOAD_INVALID');
            }
            if (! is_int($value) || ($value < 0 && ! str_contains($field, 'delta'))) {
                $label = $field === 'amount' ? 'amount' : "{$field} amount";
                throw LedgerRequestRejected::field("payload.{$field}", "The {$label} must be an integer in minor units".(str_contains($field, 'delta') ? '' : ' (0 or more)'), 'PAYLOAD_INVALID');
            }
        }
        foreach ($rule->lines as $line) {
            if (! isset($line['for_each'])) {
                continue;
            }
            $list = str_starts_with($line['for_each'], 'payload.') ? substr($line['for_each'], 8) : $line['for_each'];
            $items = $payload[$list] ?? null;
            if (! is_array($items) || ! array_is_list($items) || $items === []) {
                throw LedgerRequestRejected::field("payload.{$list}", "The {$list} list is required (one item per line)", 'PAYLOAD_INVALID');
            }
        }
    }

    /**
     * The payload fields the rule's amount expressions read, `field => optional` (optional when the reference is followed by `??`).
     *
     * @return array<string, bool>
     */
    public static function payloadFields(PostingRule $rule): array
    {
        $fields = [];
        foreach ($rule->lines as $line) {
            foreach (isset($line['for_each']) ? $line['lines'] : [$line] as $inner) {
                $expression = $inner['amount'];
                if (preg_match_all('/payload\.([a-z_][a-z0-9_]*)\s*\)?\s*(\?\?)?/i', $expression, $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }
                foreach ($matches as $match) {
                    $optional = isset($match[2]) && $match[2] === '??';
                    $fields[$match[1]] = ($fields[$match[1]] ?? true) && $optional;
                }
            }
        }

        return $fields;
    }

    private function assertPeriodOpen(string $entityId, PostingRule $rule, CarbonImmutable $transactionDate): void
    {
        $bookId = Book::query()->where('code', $rule->books[0] ?? '')->value('id') ?? DB::table('books')->where('is_primary', true)->value('id');
        if (! is_string($bookId)) {
            return; // nothing to check against; the engine reports the missing book
        }
        try {
            $this->contexts->period($entityId, $bookId, $transactionDate, false);
        } catch (PostingFailedException $e) {
            $month = $transactionDate->format('Y-m');
            $message = match ($e->reasonCode) {
                'PERIOD_CLOSED' => "Period {$month} is locked; post into an open period or ask finance to reopen it",
                'PERIOD_SOFT_LOCKED' => "Period {$month} is soft-locked; the API cannot post into it",
                default => "No fiscal period covers {$transactionDate->toDateString()}",
            };
            throw LedgerRequestRejected::field('transaction_date', $message, $e->reasonCode);
        }
    }
}
