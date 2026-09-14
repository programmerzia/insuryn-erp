<?php

declare(strict_types=1);

namespace App\Http\Search;

use App\Http\Pages\PageSupport;
use App\Http\Distribution\ProducersPageController;
use App\Http\People\EmployeesPageController;
use App\Modules\Accounting\Http\Controllers\ClosePageController;
use App\Modules\Finance\FixedAssets\Http\Controllers\FixedAssetsPageController;
use App\Modules\Finance\Payables\Http\Controllers\PayablesArea;
use App\Modules\Insurance\Reinsurance\Http\Controllers\ReinsurancePageController;
use App\Modules\Insurance\Claims\Http\Controllers\ClaimPageController;
use App\Modules\Insurance\Collections\Http\Controllers\CollectionsPageController;
use App\Modules\Insurance\CoverNote\Http\Controllers\CoverNotesPageController;
use App\Modules\Insurance\Party\Http\Controllers\PartyPageController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController;
use App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController;
use App\Modules\Insurance\Underwriting\Http\Controllers\ProposalPageController;
use App\Modules\Platform\Authorization\AreaReach;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Command palette "find" (UX brief §4): records by number, name or cheque reference, and period actions ("lock period Sep 2026"), each limited
 * to areas the user may open (the same area permissions the pages use). App-level composition over module tables, read-only.
 * Gap audit GA-29: also quotations, proposals and cover notes by number, producers by code or name, and policies, proposals and quotations by the
 * vehicle's registration or chassis number (typed with or without spaces and dashes).
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
        $vehicle = self::vehicleKey($query);
        // Follow-up H1: branch-bound records only within the user's reach for the area (a branch-scoped user finds their branches' records).
        $reach = fn (array $area): AreaReach => $this->permissions->reach($userId, array_values($area));

        return [
            ...($may(ClosePageController::AREA) ? $this->periodActions($query, $held) : []),
            ...($may(PolicyPageController::AREA) ? $this->policies($like, $number, $vehicle, $reach(PolicyPageController::AREA)) : []),
            ...($may(QuotationPageController::AREA) ? $this->quotations($like, $number, $vehicle, $reach(QuotationPageController::AREA)) : []),
            ...($may(ProposalPageController::AREA) ? $this->proposals($like, $number, $vehicle, $reach(ProposalPageController::AREA)) : []),
            ...($may(CoverNotesPageController::AREA) ? $this->coverNotes($like, $number, $reach(CoverNotesPageController::AREA)) : []),
            ...($may(ClaimPageController::AREA) ? $this->claims($like, $number, $reach(ClaimPageController::AREA)) : []),
            ...($may(CollectionsPageController::AREA) ? $this->receipts($like, $number, $reach(CollectionsPageController::AREA)) : []),
            ...($may(PartyPageController::AREA) || $may(PolicyPageController::AREA) ? $this->customers($like) : []),
            ...($may(ProducersPageController::AREA) ? $this->producers($like, $number) : []),
            ...($may(ReinsurancePageController::AREA) ? $this->reinsurance($like) : []),
            ...(in_array('accounting.view_journals', $held, true) ? $this->journals($like, $number) : []),
            // Consistency pass: the finance, reinsurance and people modules' main documents.
            ...($may(PayablesArea::AREA) ? $this->payables($like, $number, $reach(PayablesArea::AREA)) : []),
            ...($may(ReinsurancePageController::AREA) ? $this->treaties($like) : []),
            ...($may(FixedAssetsPageController::AREA) ? $this->assets($like, $number, $reach(FixedAssetsPageController::AREA)) : []),
            ...($may(EmployeesPageController::AREA) ? $this->employees($like, $number, $reach(EmployeesPageController::AREA)) : []),
        ];
    }

    /**
     * Supplier bills by number, the supplier's own reference or the supplier's name; payment runs by number; suppliers by code or name.
     *
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function payables(string $like, ?array $number, AreaReach $reach): array
    {
        $results = [];
        $bills = $reach->constrain(DB::table('ap_bills as b'), 'b.entity_id', 'b.branch_id')->join('suppliers as s', 's.id', '=', 'b.supplier_id')->leftJoin('parties as sp', 'sp.id', '=', 's.party_id')
            ->whereNotNull('b.number')->where(fn ($q) => self::numberOr($q->where('b.number', 'ilike', $like)->orWhere('b.supplier_reference', 'ilike', $like)->orWhere('sp.display_name', 'ilike', $like), 'b.number', $number))
            ->orderByDesc('b.bill_date')->limit(self::PER_KIND)->get(['b.id', 'b.number', 'b.status', 'b.supplier_reference', 'b.payable_minor', 'b.currency', 'sp.display_name']);
        foreach ($bills as $row) {
            $results[] = ['kind' => 'supplier_bill', 'label' => (string) $row->number, 'detail' => ($row->display_name ?? '').' · '.$row->supplier_reference.' · '.PageSupport::money((int) $row->payable_minor, (string) $row->currency).' · '.self::word((string) $row->status),
                'href' => "/payables/bills/{$row->id}"];
        }
        $runs = $reach->tenantWide || $reach->entityIds !== [] ? DB::table('payment_runs')->when(! $reach->tenantWide, fn ($q) => $q->whereIn('entity_id', $reach->entityIds))
            ->where(fn ($q) => self::numberOr($q->where('number', 'ilike', $like), 'number', $number))->orderByDesc('pay_date')->limit(self::PER_KIND)->get(['id', 'number', 'status', 'pay_date', 'total_minor', 'currency']) : collect();
        foreach ($runs as $row) {
            $results[] = ['kind' => 'payment_run', 'label' => (string) $row->number, 'detail' => 'Pay on '.CarbonImmutable::parse((string) $row->pay_date)->format('j M Y').' · '.PageSupport::money((int) $row->total_minor, (string) $row->currency).' · '.self::word((string) $row->status),
                'href' => "/payables/payment-runs/{$row->id}"];
        }
        $suppliers = DB::table('suppliers as s')->leftJoin('parties as sp', 'sp.id', '=', 's.party_id')->where(fn ($q) => $q->where('s.code', 'ilike', $like)->orWhere('sp.display_name', 'ilike', $like))
            ->orderBy('s.code')->limit(self::PER_KIND)->get(['s.id', 's.code', 's.status', 'sp.display_name']);
        foreach ($suppliers as $row) {
            $results[] = ['kind' => 'supplier', 'label' => (string) ($row->display_name ?? $row->code), 'detail' => $row->code.' · '.self::word((string) $row->status), 'href' => "/payables/suppliers/{$row->id}"];
        }

        return $results;
    }

    /** @return list<array{kind: string, label: string, detail: string, href: string}> */
    private function treaties(string $like): array
    {
        $rows = DB::table('ri_treaties')->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like))->orderByDesc('period_from')->limit(self::PER_KIND)
            ->get(['id', 'code', 'name', 'type', 'status']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'treaty', 'label' => (string) $row->code, 'detail' => $row->name.' · '.self::word((string) $row->type).' · '.self::word((string) $row->status), 'href' => "/reinsurance/treaties/{$row->id}"];
        }

        return $results;
    }

    /**
     * Fixed assets by number, serial number (tag) or description.
     *
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function assets(string $like, ?array $number, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('fixed_assets'), 'entity_id', 'branch_id')
            ->where(fn ($q) => self::numberOr($q->where('number', 'ilike', $like)->orWhere('serial_no', 'ilike', $like)->orWhere('description', 'ilike', $like), 'number', $number))
            ->orderBy('number')->limit(self::PER_KIND)->get(['id', 'number', 'description', 'serial_no', 'status']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'fixed_asset', 'label' => (string) $row->number, 'detail' => $row->description.($row->serial_no !== null ? " · serial {$row->serial_no}" : '').' · '.self::word((string) $row->status),
                'href' => "/fixed-assets/{$row->id}"];
        }

        return $results;
    }

    /**
     * Employees by code or name, within the branches of their current employment the user may see.
     *
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function employees(string $like, ?array $number, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('employees as e'), 'e.entity_id', 'm.branch_id')
            ->leftJoin('employments as m', fn ($j) => $j->on('m.employee_id', '=', 'e.id')->whereNull('m.effective_to'))->leftJoin('designations as d', 'd.id', '=', 'm.designation_id')
            ->where(fn ($q) => self::numberOr($q->where('e.code', 'ilike', $like)->orWhere('e.full_name', 'ilike', $like), 'e.code', $number))
            ->orderBy('e.code')->limit(self::PER_KIND)->get(['e.id', 'e.code', 'e.full_name', 'e.status', 'd.name as designation']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'employee', 'label' => "{$row->code} · {$row->full_name}", 'detail' => ($row->designation ?? 'Employee').' · '.self::word((string) $row->status), 'href' => "/people/employees/{$row->id}"];
        }

        return $results;
    }

    /**
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function policies(string $like, ?array $number, ?string $vehicle, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('policies as p'), 'p.entity_id', 'p.branch_id')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')
            ->whereNotNull('p.number')->where(fn ($q) => self::vehicleOr(self::numberOr($q->where('h.display_name', 'ilike', $like)->orWhere('p.number', 'ilike', $like), 'p.number', $number), 'p', $vehicle))
            ->orderByDesc('p.created_at')->limit(self::PER_KIND)->get(['p.id', 'p.number', 'p.status', 'h.display_name', DB::raw("p.risk_inputs->>'registration_no' as registration")]);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'policy', 'label' => (string) $row->number, 'detail' => $row->display_name.' · '.self::word((string) $row->status).self::registration($row), 'href' => "/policies/{$row->id}"];
        }

        return $results;
    }

    /**
     * GA-29: quotations by number, customer or vehicle.
     *
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function quotations(string $like, ?array $number, ?string $vehicle, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('quotations as q'), 'q.entity_id', 'q.branch_id')->leftJoin('parties as c', 'c.id', '=', 'q.customer_party_id')->whereNotNull('q.number')
            ->where(fn ($q) => self::vehicleOr(self::numberOr($q->where('q.number', 'ilike', $like)->orWhere('c.display_name', 'ilike', $like), 'q.number', $number), 'q', $vehicle))
            ->orderByDesc('q.created_at')->limit(self::PER_KIND)->get(['q.id', 'q.number', 'q.status', 'c.display_name', DB::raw("q.risk_inputs->>'registration_no' as registration")]);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'quotation', 'label' => (string) $row->number, 'detail' => ($row->display_name ?? 'No customer').' · '.self::word((string) $row->status).self::registration($row),
                'href' => "/quotations/{$row->id}"];
        }

        return $results;
    }

    /**
     * GA-29: proposals by number, customer or vehicle.
     *
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function proposals(string $like, ?array $number, ?string $vehicle, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('proposals as pr'), 'pr.entity_id', 'pr.branch_id')->leftJoin('parties as c', 'c.id', '=', 'pr.customer_party_id')
            ->where(fn ($q) => self::vehicleOr(self::numberOr($q->where('pr.number', 'ilike', $like)->orWhere('c.display_name', 'ilike', $like), 'pr.number', $number), 'pr', $vehicle))
            ->orderByDesc('pr.created_at')->limit(self::PER_KIND)->get(['pr.id', 'pr.number', 'pr.status', 'c.display_name', DB::raw("pr.risk_inputs->>'registration_no' as registration")]);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'proposal', 'label' => (string) $row->number, 'detail' => ($row->display_name ?? '').' · '.self::word((string) $row->status).self::registration($row), 'href' => "/proposals/{$row->id}"];
        }

        return $results;
    }

    /**
     * GA-29: cover notes by number; a cover note opens its proposal, where it was issued and is printed.
     *
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function coverNotes(string $like, ?array $number, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('cover_notes as n'), 'n.entity_id', 'n.branch_id')->join('proposals as pr', 'pr.id', '=', 'n.proposal_id')->leftJoin('parties as c', 'c.id', '=', 'pr.customer_party_id')
            ->where(fn ($q) => self::numberOr($q->where('n.number', 'ilike', $like), 'n.number', $number))
            ->orderByDesc('n.created_at')->limit(self::PER_KIND)->get(['n.id', 'n.number', 'n.status', 'n.valid_to', 'n.proposal_id', 'c.display_name']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'cover_note', 'label' => (string) $row->number,
                'detail' => ($row->display_name ?? '').' · '.self::word((string) $row->status).' · until '.CarbonImmutable::parse((string) $row->valid_to)->format('j M Y'), 'href' => "/proposals/{$row->proposal_id}"];
        }

        return $results;
    }

    /**
     * GA-29: producers by code ("AG-001", "ag 1") or name. Producers belong to the whole tenant, like customers (A-160).
     *
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function producers(string $like, ?array $number): array
    {
        $rows = DB::table('producers as a')->leftJoin('parties as ap', 'ap.id', '=', 'a.party_id')
            ->where(fn ($q) => self::numberOr($q->where('a.code', 'ilike', $like)->orWhere('ap.display_name', 'ilike', $like), 'a.code', $number))
            ->orderBy('a.code')->limit(self::PER_KIND)->get(['a.id', 'a.code', 'a.type', 'a.status', 'ap.display_name']);
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['kind' => 'producer', 'label' => (string) $row->code, 'detail' => ($row->display_name ?? '').' · '.self::word((string) $row->type).' · '.self::word((string) $row->status),
                'href' => "/distribution/producers/{$row->id}"];
        }

        return $results;
    }

    /**
     * @param array{0: string, 1: string}|null $number
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function claims(string $like, ?array $number, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('claims'), 'entity_id', 'branch_id')->where(fn ($q) => self::numberOr($q->where('number', 'ilike', $like)->orWhere('description', 'ilike', $like), 'number', $number))
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
    private function receipts(string $like, ?array $number, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('receipts'), 'entity_id', 'branch_id')->where(fn ($q) => self::numberOr($q->where('number', 'ilike', $like)->orWhere('cheque_no', 'ilike', $like)->orWhere('reference', 'ilike', $like), 'number', $number))
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
     * UX consistency pass: treaties by code or name, reinsurer statements by number or reinsurer. Both belong to the entity, not a branch.
     *
     * @return list<array{kind: string, label: string, detail: string, href: string}>
     */
    private function reinsurance(string $like): array
    {
        $results = [];
        $treaties = DB::table('ri_treaties')->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like))
            ->orderByDesc('underwriting_year')->orderBy('code')->limit(self::PER_KIND)->get(['id', 'code', 'name', 'status']);
        foreach ($treaties as $row) {
            $results[] = ['kind' => 'treaty', 'label' => (string) $row->code, 'detail' => $row->name.' · '.self::word((string) $row->status), 'href' => "/reinsurance/treaties/{$row->id}"];
        }
        $statements = DB::table('ri_statements as s')->join('reinsurers as r', 'r.id', '=', 's.reinsurer_id')->join('parties as p', 'p.id', '=', 'r.party_id')
            ->where(fn ($q) => $q->where('s.number', 'ilike', $like)->orWhere('r.code', 'ilike', $like)->orWhere('p.display_name', 'ilike', $like))
            ->orderByDesc('s.year')->orderByDesc('s.quarter')->limit(self::PER_KIND)->get(['s.id', 's.number', 's.year', 's.quarter', 'r.code']);
        foreach ($statements as $row) {
            $results[] = ['kind' => 'reinsurer_statement', 'label' => (string) $row->number, 'detail' => "{$row->code} · Q{$row->quarter} {$row->year}", 'href' => "/reinsurance/statements/{$row->id}"];
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
     * "POL-1042", "pol 1042" or "POL-HO-1042" → ['POL', '1042'] / ['POL-HO', '1042']: a document number typed without its year and zero padding
     * (brief §4 example; branch codes since fix F1).
     *
     * @return array{0: string, 1: string}|null
     */
    private static function shortNumber(string $query): ?array
    {
        return preg_match('/^([A-Za-z]{2,4}(?:[-\s][A-Za-z][A-Za-z0-9]{0,7})?)[-\s]?(\d{1,6})$/', $query, $m) === 1
            ? [strtoupper((string) preg_replace('/\s+/', '-', $m[1])), $m[2]] : null;
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

        return $query->orWhere(fn ($q) => $q->where($column, 'like', $number[0].'-%')->whereRaw("ltrim(regexp_replace({$column}, '^.*[^0-9]', ''), '0') = ?", [ltrim($number[1], '0')]));
    }

    /** A vehicle registration or chassis number as typed ("DHA GA 11-1234"), reduced to its letters and digits; null when too short to search by. */
    private static function vehicleKey(string $query): ?string
    {
        $key = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($query));

        return strlen($key) >= 4 && preg_match('/\d/', $key) === 1 ? $key : null;
    }

    /**
     * Also matches rows whose risk inputs name the vehicle: the registration and chassis numbers compared on letters and digits only (a space keeps the two
     * apart, and the key has none, so a match never straddles them).
     *
     * @param 'p'|'q'|'pr' $alias the policies, quotations or proposals table
     */
    private static function vehicleOr(\Illuminate\Database\Query\Builder $query, string $alias, ?string $vehicle): \Illuminate\Database\Query\Builder
    {
        if ($vehicle === null) {
            return $query;
        }
        $sql = match ($alias) {
            'p' => "regexp_replace(upper(coalesce(p.risk_inputs->>'registration_no', '') || ' ' || coalesce(p.risk_inputs->>'chassis_no', '')), '[^A-Z0-9 ]', '', 'g') like ?",
            'q' => "regexp_replace(upper(coalesce(q.risk_inputs->>'registration_no', '') || ' ' || coalesce(q.risk_inputs->>'chassis_no', '')), '[^A-Z0-9 ]', '', 'g') like ?",
            'pr' => "regexp_replace(upper(coalesce(pr.risk_inputs->>'registration_no', '') || ' ' || coalesce(pr.risk_inputs->>'chassis_no', '')), '[^A-Z0-9 ]', '', 'g') like ?",
        };

        return $query->orWhereRaw($sql, ['%'.$vehicle.'%']);
    }

    private static function registration(object $row): string
    {
        $registration = $row->registration ?? null;

        return is_string($registration) && $registration !== '' ? " · {$registration}" : '';
    }

    private static function word(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }
}
