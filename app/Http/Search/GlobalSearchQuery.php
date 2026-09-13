<?php

declare(strict_types=1);

namespace App\Http\Search;

use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Http\Controllers\ClosePageController;
use App\Modules\Insurance\Claims\Http\Controllers\ClaimPageController;
use App\Modules\Insurance\Collections\Http\Controllers\CollectionsPageController;
use App\Modules\Insurance\Party\Http\Controllers\PartyPageController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Command palette "find" (UX brief §4): records by number, name or cheque reference, and period actions ("lock period Sep 2026"), each limited
 * to areas the user may open (the same area permissions the pages use). App-level composition over module tables, read-only.
 */
final class GlobalSearchQuery
{
    private const PER_KIND = 5;

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public function __construct(private readonly PermissionChecker $permissions) {}

    /** @return list<array{kind: string, label: string, detail: string, href: string}> */
    public function search(string $userId, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $held = $this->permissions->permissionsOf($userId);
        $may = fn (array $area): bool => array_intersect($area, $held) !== [];
        $like = '%'.addcslashes($query, '%_\\').'%';
        $number = self::shortNumber($query);

        return [
            ...($may(ClosePageController::AREA) ? $this->periodActions($query, $held) : []),
            ...($may(PolicyPageController::AREA) ? $this->policies($like, $number) : []),
            ...($may(ClaimPageController::AREA) ? $this->claims($like, $number) : []),
            ...($may(CollectionsPageController::AREA) ? $this->receipts($like, $number) : []),
            ...($may(PartyPageController::AREA) || $may(PolicyPageController::AREA) ? $this->customers($like) : []),
            ...(in_array('accounting.view_journals', $held, true) ? $this->journals($like, $number) : []),
        ];
    }

    /**
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function policies(string $like, ?array $number): array
    {
        $rows = DB::table('policies as p')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')
            ->whereNotNull('p.number')->where(fn ($q) => self::numberOr($q->where('h.display_name', 'ilike', $like)->orWhere('p.number', 'ilike', $like), 'p.number', $number))
            ->orderByDesc('p.created_at')->limit(self::PER_KIND)->get(['p.id', 'p.number', 'p.status', 'h.display_name']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'policy', 'label' => (string) $row->number, 'detail' => $row->display_name.' · '.self::word((string) $row->status), 'href' => "/policies/{$row->id}"];
        }

        return $results;
    }

    /**
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function claims(string $like, ?array $number): array
    {
        $rows = DB::table('claims')->where(fn ($q) => self::numberOr($q->where('number', 'ilike', $like)->orWhere('description', 'ilike', $like), 'number', $number))
            ->orderByDesc('created_at')->limit(self::PER_KIND)->get(['id', 'number', 'status', 'description']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'claim', 'label' => (string) $row->number, 'detail' => $row->description.' · '.self::word((string) $row->status), 'href' => "/claims/{$row->id}"];
        }

        return $results;
    }

    /**
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function receipts(string $like, ?array $number): array
    {
        $rows = DB::table('receipts')->where(fn ($q) => self::numberOr($q->where('number', 'ilike', $like)->orWhere('cheque_no', 'ilike', $like)->orWhere('reference', 'ilike', $like), 'number', $number))
            ->orderByDesc('created_at')->limit(self::PER_KIND)->get(['id', 'number', 'cheque_no', 'cheque_bank', 'reference', 'amount_minor', 'currency']);
        $results = [];
        foreach ($rows as $row) {
            $what = $row->cheque_no !== null ? "Cheque {$row->cheque_no} · {$row->cheque_bank}" : ($row->reference ?? 'No reference');
            $results[] = ['kind' => 'receipt', 'label' => (string) $row->number, 'detail' => $what.' · '.PageSupport::money((int) $row->amount_minor, (string) $row->currency), 'href' => "/receipts/{$row->id}"];
        }

        return $results;
    }

    /** @return list<array{kind: string, label: string, detail: string, href: string}> */
    private function customers(string $like): array
    {
        $rows = DB::table('parties')->where(fn ($q) => $q->where('display_name', 'ilike', $like)->orWhere('tax_id', 'ilike', $like))
            ->orderBy('display_name')->limit(self::PER_KIND)->get(['id', 'display_name', 'kind', 'tax_id']);
        $results = [];
        foreach ($rows as $row) {
            $detail = self::word((string) $row->kind).($row->tax_id !== null ? " · TIN {$row->tax_id}" : '');
            $results[] = ['kind' => 'customer', 'label' => (string) $row->display_name, 'detail' => $detail, 'href' => "/parties/{$row->id}"];
        }

        return $results;
    }

    /**
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function journals(string $like, ?array $number): array
    {
        $rows = DB::table('journals')->where(fn ($q) => self::numberOr($q->where('number', 'ilike', $like), 'number', $number))->orderByDesc('posting_date')->limit(self::PER_KIND)->get(['id', 'number', 'description', 'status']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'journal', 'label' => (string) $row->number, 'detail' => ($row->description ?? 'Journal').' · '.self::word((string) $row->status), 'href' => "/accounting/journals/{$row->id}"];
        }

        return $results;
    }

    /**
     * "lock period sep 2026", "close aug", "reopen jul 2026": the matching period's next close action the user may take.
     *
     * @param list<string> $held
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function periodActions(string $query, array $held): array
    {
        $words = preg_split('/\s+/', mb_strtolower($query)) ?: [];
        $verb = array_values(array_intersect($words, ['lock', 'close', 'reopen', 'period']))[0] ?? null;
        $month = null;
        $year = null;
        foreach ($words as $word) {
            $month ??= self::MONTHS[substr($word, 0, 3)] ?? null;
            $year ??= preg_match('/^20\d\d$/', $word) === 1 ? (int) $word : null;
        }
        if ($verb === null || $month === null) {
            return [];
        }
        $periods = DB::table('fiscal_periods as f')->join('books as b', 'b.id', '=', 'f.book_id')->where('b.is_primary', true)
            ->whereRaw('extract(month from f.starts) = ?', [$month])->when($year !== null, fn ($q) => $q->whereRaw('extract(year from f.starts) = ?', [$year]))
            ->orderByDesc('f.starts')->limit(2)->get(['f.id', 'f.starts', 'f.status']);
        $results = [];
        foreach ($periods as $period) {
            $name = CarbonImmutable::parse((string) $period->starts)->format('M Y');
            $run = DB::table('period_close_runs')->where('period_id', $period->id)->where('status', 'running')->orderByDesc('started_at')->value('id');
            $href = is_string($run) ? "/close/runs/{$run}" : '/close';
            if ($period->status === 'locked') {
                if ($verb === 'reopen' && in_array('periods.reopen', $held, true)) {
                    $results[] = ['kind' => 'action', 'label' => "Reopen period {$name}", 'detail' => 'Locked · needs a reason', 'href' => '/close'];
                }
                continue;
            }
            $label = match (true) {
                $verb === 'lock' && in_array('periods.lock', $held, true) => "Lock period {$name}",
                in_array($verb, ['close', 'period'], true) && array_intersect(['periods.soft_lock', 'periods.lock'], $held) !== [] => "Close period {$name}",
                default => null,
            };
            if ($label !== null) {
                $results[] = ['kind' => 'action', 'label' => $label, 'detail' => self::word((string) $period->status).(is_string($run) ? ' · close in progress' : ' · close not started'), 'href' => $href];
            }
        }

        return $results;
    }

    /**
     * "POL-1042" or "pol 1042" → ['POL', '1042']: a document number typed without its year and zero padding (brief §4 example).
     *
     * @return array{0: string, 1: string}|null
     */
    private static function shortNumber(string $query): ?array
    {
        return preg_match('/^([A-Za-z]{2,4})[-\s]?(\d{1,6})$/', $query, $m) === 1 ? [strtoupper($m[1]), $m[2]] : null;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param array{0: string, 1: string}|null $number
     */
    private static function numberOr($query, string $column, ?array $number): \Illuminate\Database\Query\Builder
    {
        if ($number === null) {
            return $query;
        }

        return $query->orWhere(fn ($q) => $q->where($column, 'like', $number[0].'-%')->whereRaw("ltrim(split_part({$column}, '-', 3), '0') = ?", [ltrim($number[1], '0')]));
    }

    private static function word(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }
}
