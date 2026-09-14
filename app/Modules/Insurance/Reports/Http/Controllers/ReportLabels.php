<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Http\Controllers;

use Illuminate\Support\Facades\DB;

/** Gap audit GA-34: the words a report shows for stored codes — "Motor" for motor, "New business" for new, "Reserved" for reserved. */
final class ReportLabels
{
    private const TRANSACTIONS = ['new' => 'New business', 'endorsement' => 'Endorsement', 'cancellation' => 'Cancellation', 'renewal' => 'Renewal'];

    /** @return array<string, string> product class code → English name (the global catalogue) */
    public static function classes(): array
    {
        return DB::table('product_classes')->pluck('name_en', 'code')->mapWithKeys(fn (mixed $name, mixed $code): array => [(string) $code => (string) $name])->all();
    }

    /** @param array<string, string> $classes */
    public static function productClass(string $code, array $classes): string
    {
        return $classes[$code] ?? self::words($code);
    }

    public static function transaction(string $type): string
    {
        return self::TRANSACTIONS[$type] ?? self::words($type);
    }

    /** "renewal_offered" → "Renewal offered". */
    public static function words(string $code): string
    {
        return ucfirst(str_replace('_', ' ', $code));
    }
}
