<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fiscal periods of the primary book, as business modules may see them (design §1: business contexts use
 * Accounting\Application contracts, never kernel tables).
 */
final class FiscalPeriodQuery
{
    public function find(string $periodId): ?FiscalPeriodView
    {
        return $this->view(DB::table('fiscal_periods')->where('id', $periodId)->first());
    }

    public function containing(string $entityId, CarbonImmutable $date): ?FiscalPeriodView
    {
        $day = $date->toDateString();

        return $this->view(DB::table('fiscal_periods as p')->join('books as b', 'b.id', '=', 'p.book_id')->where('b.is_primary', true)
            ->where('p.entity_id', $entityId)->where('p.starts', '<=', $day)->where('p.ends', '>=', $day)->select('p.*')->first());
    }

    /**
     * Open periods of the primary book that ended before $date, oldest first.
     *
     * @return list<FiscalPeriodView>
     */
    public function openEndedBefore(CarbonImmutable $date): array
    {
        $rows = DB::table('fiscal_periods as p')->join('books as b', 'b.id', '=', 'p.book_id')->where('b.is_primary', true)
            ->where('p.status', 'open')->where('p.ends', '<', $date->toDateString())->orderBy('p.starts')->select('p.*')->get();

        return array_values(array_filter($rows->map(fn (object $row): ?FiscalPeriodView => $this->view($row))->all()));
    }

    private function view(?object $row): ?FiscalPeriodView
    {
        if ($row === null) {
            return null;
        }
        /** @var object{id: string, entity_id: string, book_id: string, year: int, period: int, starts: string, ends: string, status: string} $row */
        return new FiscalPeriodView($row->id, $row->entity_id, $row->book_id, (int) $row->year, (int) $row->period,
            CarbonImmutable::parse($row->starts), CarbonImmutable::parse($row->ends), $row->status);
    }
}
