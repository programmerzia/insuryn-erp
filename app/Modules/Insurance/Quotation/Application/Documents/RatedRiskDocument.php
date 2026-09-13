<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Application\Documents;

use App\Modules\Platform\Documents\Generation\DocumentValues;
use Illuminate\Support\Facades\DB;

/**
 * What a document printed from a rated risk shows (Phase 3 design §3; the quotation and the cover note): the product, the class, the parties, the risk
 * details in the product version's own risk-schema labels, and the premium from the frozen rating result — net premium, each duty, gross — with the
 * rating explanation lines. Read-only, in English or Bangla.
 */
final class RatedRiskDocument
{
    /**
     * @param \stdClass $source a quotation row (product_version_id, customer_party_id, producer_id, entity_id, risk_inputs, rating_result, currency)
     * @return array<string, mixed> company, currency, parties, product, details (risk rows), money, total and rating
     */
    public function variables(\stdClass $source, string $locale): array
    {
        $l = fn (string $en, string $bn): string => DocumentValues::label($locale, $en, $bn);
        $currency = (string) $source->currency;
        $money = fn (int $minor): string => DocumentValues::money($minor, $currency);
        $version = DB::table('product_versions as v')->join('products as p', 'p.id', '=', 'v.product_id')->where('v.id', $source->product_version_id)
            ->first(['p.code', 'p.name', 'v.class_code', 'v.risk_schema']);
        $class = $version?->class_code === null ? null : DB::table('product_classes')->where('code', $version->class_code)->first(['name_en', 'name_bn']);
        $rating = self::decode($source->rating_result);

        $parties = [];
        if ($source->customer_party_id !== null) {
            $parties[] = ['role' => $l('Customer', 'গ্রাহক'), 'name' => (string) DB::table('parties')->where('id', $source->customer_party_id)->value('display_name')];
        }
        if ($source->producer_id !== null) {
            $producer = DB::table('producers as a')->join('parties as pa', 'pa.id', '=', 'a.party_id')->where('a.id', $source->producer_id)->first(['a.code', 'pa.display_name']);
            if ($producer instanceof \stdClass) {
                $parties[] = ['role' => $l('Agent', 'এজেন্ট'), 'name' => "{$producer->display_name} ({$producer->code})"];
            }
        }

        $moneyRows = [['label' => $l('Net premium', 'নিট প্রিমিয়াম'), 'amount' => $money((int) ($rating['net_premium_minor'] ?? 0))]];
        foreach (is_array($rating['duties'] ?? null) ? $rating['duties'] : [] as $duty) {
            if (is_array($duty)) {
                $moneyRows[] = ['label' => (string) ($locale === 'bn' ? ($duty['label_bn'] ?? '') : ($duty['label_en'] ?? '')), 'amount' => $money((int) ($duty['amount_minor'] ?? 0))];
            }
        }

        return [
            'company' => ['name' => (string) DB::table('legal_entities')->where('id', $source->entity_id)->value('name')],
            'currency' => $currency,
            'parties' => $parties,
            'product' => ['code' => (string) ($version->code ?? ''), 'name' => (string) ($version->name ?? ''),
                'class' => $class instanceof \stdClass ? $l((string) $class->name_en, (string) $class->name_bn) : ''],
            'details' => self::riskRows(self::decode($version?->risk_schema), self::decode($source->risk_inputs), $locale, $currency),
            'money' => $moneyRows,
            'total' => ['label' => $l('Gross premium', 'মোট প্রিমিয়াম'), 'amount' => $money((int) ($rating['gross_premium_minor'] ?? 0))],
            'rating' => array_values(array_map(fn (mixed $line): array => ['label' => is_array($line) ? (string) ($locale === 'bn' ? ($line['label_bn'] ?? '') : ($line['label_en'] ?? '')) : '',
                'amount' => $money(is_array($line) ? (int) ($line['amount_minor'] ?? 0) : 0)], is_array($rating['explanation'] ?? null) ? $rating['explanation'] : [])),
        ];
    }

    /**
     * Risk details in schema order, labelled in the locale: a select shows its option's label, money is formatted, a yes/no reads as words.
     *
     * @param array<mixed> $schema
     * @param array<mixed> $inputs
     * @return list<array{label: string, value: string}>
     */
    public static function riskRows(array $schema, array $inputs, string $locale, string $currency): array
    {
        $rows = [];
        foreach ($schema as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null) || ! array_key_exists($field['key'], $inputs) || $inputs[$field['key']] === null || $inputs[$field['key']] === '') {
                continue;
            }
            $value = $inputs[$field['key']];
            $text = match ((string) ($field['type'] ?? 'text')) {
                'money' => DocumentValues::money((int) $value, $currency),
                'boolean' => $value === true || $value === 'true' || $value === 1 ? DocumentValues::label($locale, 'Yes', 'হ্যাঁ') : DocumentValues::label($locale, 'No', 'না'),
                'date' => DocumentValues::date((string) $value),
                'select' => self::optionLabel($field, (string) $value, $locale),
                default => (string) $value,
            };
            $rows[] = ['label' => (string) ($locale === 'bn' ? ($field['label_bn'] ?? $field['key']) : ($field['label_en'] ?? $field['key'])), 'value' => $text];
        }

        return $rows;
    }

    /** @param array<mixed> $field */
    private static function optionLabel(array $field, string $value, string $locale): string
    {
        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && (string) ($option['value'] ?? '') === $value) {
                return (string) ($locale === 'bn' ? ($option['label_bn'] ?? $value) : ($option['label_en'] ?? $value));
            }
        }

        return $value;
    }

    /** @return array<mixed> */
    private static function decode(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;

        return is_array($decoded) ? $decoded : [];
    }
}
