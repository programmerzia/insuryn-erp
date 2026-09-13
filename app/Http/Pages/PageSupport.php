<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Modules\Platform\Money\MinorUnits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Small helpers shared by the Inertia operations screens: the acting user, the single entity of the MVP UI, and money typed in major units. */
final class PageSupport
{
    public static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /** @return array{id: string, code: string, name: string, currency: string} design §9.2 MVP: single-entity UI */
    public static function entity(): array
    {
        $entity = DB::table('legal_entities')->orderBy('code')->first(['id', 'code', 'name', 'base_currency']);
        abort_if($entity === null, 404, 'No legal entity is set up for this organisation.');

        return ['id' => (string) $entity->id, 'code' => (string) $entity->code, 'name' => (string) $entity->name, 'currency' => (string) $entity->base_currency];
    }

    /** "1,234.56" or "-1,234.56" in the entity currency → minor units; a validation error on $field otherwise. Never passes through a float. */
    public static function minor(string $field, mixed $value, string $currency, bool $allowNegative = false): int
    {
        $text = trim((string) $value);
        $minor = $allowNegative ? MinorUnits::fromSignedMajor($text, $currency) : MinorUnits::fromMajor($text, $currency);
        if ($minor === null) {
            throw ValidationException::withMessages([$field => "Enter an amount in {$currency}, like 1,234.56."]);
        }

        return $minor;
    }

    public static function money(int $minor, string $currency): string
    {
        return MinorUnits::format($minor, $currency);
    }

    /**
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, mixed> $paginator
     * @param array<int, array<string, mixed>> $items
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, total: int}
     */
    public static function page(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator, array $items): array
    {
        return ['data' => array_values($items), 'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()];
    }
}
