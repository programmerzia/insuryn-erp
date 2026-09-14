<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Application\Returns;

use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use App\Modules\Distribution\Application\Licences\IdraRegisterExport;
use App\Modules\Insurance\Regulatory\Application\RegulatoryPeriod;
use App\Modules\Insurance\Reports\Application\OutstandingClaimsQuery;
use App\Modules\Insurance\Reports\Application\PremiumRegisterQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Market gap G5: the figures of each regulatory return for a period, from the registers and the ledger — premium income from the premium register (policy
 * transactions), claims from the claims subledger, commission from the ledger by class, management expenses from the ledger spread over classes, the agency
 * register from Distribution, and reinsurance ceded when a reinsurance module is installed. The layout (sections, columns, their order and headings) is
 * `erp.regulatory.forms`; this class only fills rows by column key. Money is in minor units, rates in basis points, counts as integers.
 */
final class ReturnFormBuilder
{
    /** Column keys that hold money (minor units) and rates (basis points); other integers are counts, strings are text. */
    public const MONEY = ['gross_premium', 'vat', 'stamp_duty', 'cancellations', 'net_premium', 'intimated_amount', 'paid_amount', 'outstanding_amount', 'commission',
        'management', 'total', 'limit', 'excess', 'ceded_premium', 'recoveries'];

    public const RATES = ['ratio', 'limit_rate'];

    public function __construct(
        private readonly PremiumRegisterQuery $premiums,
        private readonly OutstandingClaimsQuery $outstanding,
        private readonly FinancialStatementsQuery $statements,
        private readonly IdraRegisterExport $licences,
    ) {}

    /** @return list<string> the configured form codes, in order */
    public static function codes(): array
    {
        /** @var array<string, mixed> $forms */
        $forms = config('erp.regulatory.forms', []);

        return array_keys($forms);
    }

    public static function title(string $code): string
    {
        return (string) config("erp.regulatory.forms.{$code}.title", $code);
    }

    /**
     * The whole form: title, period, sections with their configured columns and rows, totals and notes.
     *
     * @return array{code: string, title: string, sheet: string, period_key: string, period_label: string, period_start: string, period_end: string, entity_name: string, currency: string,
     *     sections: list<array{key: string, title: string, columns: list<array{key: string, label: string, kind: string}>, rows: list<array<string, int|string|null>>, totals: array<string, int|string|null>|null}>,
     *     notes: list<string>}
     */
    public function build(string $code, string $entityId, RegulatoryPeriod $period): array
    {
        /** @var array{title: string, sheet: string, sections: list<array{key: string, title: string, columns: array<string, string>}>}|null $layout */
        $layout = config("erp.regulatory.forms.{$code}");
        abort_if($layout === null, 404);
        $entity = DB::table('legal_entities')->where('id', $entityId)->first(['name', 'base_currency']);
        [$data, $notes] = match ($code) {
            'premium_income' => $this->premiumIncome($entityId, $period),
            'claims' => $this->claims($entityId, $period),
            'expenses' => $this->expenses($entityId, $period),
            'agent_register' => $this->agentRegister($period),
            'reinsurance_ceded' => $this->reinsuranceCeded($entityId, $period),
            default => [[], []],
        };

        $sections = [];
        foreach ($layout['sections'] as $section) {
            $columns = [];
            foreach ($section['columns'] as $key => $label) {
                $columns[] = ['key' => (string) $key, 'label' => $label, 'kind' => in_array($key, self::MONEY, true) ? 'money' : (in_array($key, self::RATES, true) ? 'rate' : 'value')];
            }
            /** @var list<array<string, int|string|null>> $rows */
            $rows = $data[$section['key']]['rows'] ?? [];
            $sections[] = ['key' => $section['key'], 'title' => $section['title'], 'columns' => $columns, 'rows' => $rows, 'totals' => $data[$section['key']]['totals'] ?? null];
        }

        return ['code' => $code, 'title' => $layout['title'], 'sheet' => $layout['sheet'], 'period_key' => $period->key, 'period_label' => $period->label(),
            'period_start' => $period->start->toDateString(), 'period_end' => $period->end->toDateString(), 'entity_name' => (string) ($entity->name ?? ''),
            'currency' => (string) ($entity->base_currency ?? 'BDT'), 'sections' => $sections, 'notes' => $notes];
    }

    /**
     * @return array{0: array<string, array{rows: list<array<string, int|string|null>>, totals: array<string, int|string|null>|null}>, 1: list<string>}
     */
    private function premiumIncome(string $entityId, RegulatoryPeriod $period): array
    {
        $register = $this->premiums->register($entityId, $period->start, $period->end);
        $labels = self::classLabels();
        $groups = ['by_class' => [], 'by_branch' => []];
        foreach ($register['rows'] as $row) {
            foreach (['by_class' => $labels[$row['class']] ?? self::words($row['class']), 'by_branch' => $row['branch_code']] as $section => $group) {
                $current = $groups[$section][$group] ?? ['group' => $group, 'policies' => [], 'gross_premium' => 0, 'vat' => 0, 'stamp_duty' => 0, 'cancellations' => 0, 'net_premium' => 0];
                $current['vat'] += $row['tax_minor'];
                if ($row['type'] === 'cancellation') {
                    $current['cancellations'] += -$row['net_minor'];
                } else {
                    $current['gross_premium'] += $row['net_minor'];
                    $current['stamp_duty'] += $row['stamp_duty_minor'];
                    if ($row['type'] === 'new') {
                        $current['policies'][$row['policy_id']] = true;
                    }
                }
                $current['net_premium'] = $current['gross_premium'] - $current['cancellations'];
                $groups[$section][$group] = $current;
            }
        }

        return [['by_class' => self::withTotals($groups['by_class']), 'by_branch' => self::withTotals($groups['by_branch'])], [
            'Gross premium is premium written in the period (new business and endorsements) without VAT and stamp duty; cancellations are the premium returned on policies cancelled in the period.',
            'Class of business is the product\'s line of business. Form numbering and wording to be confirmed with IDRA (ASSUMPTION A-261).',
        ]];
    }

    /**
     * @return array{0: array<string, array{rows: list<array<string, int|string|null>>, totals: array<string, int|string|null>|null}>, 1: list<string>}
     */
    private function claims(string $entityId, RegulatoryPeriod $period): array
    {
        [$from, $to] = [$period->start->toDateString(), $period->end->toDateString()];
        $labels = self::classLabels();
        $groups = [];
        $add = function (string $class, string $key, int $amount, int $count = 1) use (&$groups, $labels): void {
            $label = $labels[$class] ?? self::words($class);
            $groups[$label] ??= ['group' => $label, 'intimated_count' => 0, 'intimated_amount' => 0, 'paid_count' => 0, 'paid_amount' => 0, 'outstanding_count' => 0, 'outstanding_amount' => 0];
            $groups[$label]["{$key}_count"] += $count;
            $groups[$label]["{$key}_amount"] += $amount;
        };
        $intimated = DB::table('claims as c')->join('policies as p', 'p.id', '=', 'c.policy_id')->join('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('c.entity_id', $entityId)->whereBetween('c.reported_on', [$from, $to])
            ->selectRaw("pr.lob, c.id, coalesce((select r.reserve_minor from claim_reserves r where r.claim_id = c.id and r.kind = 'reserve' order by r.version limit 1), 0) as estimate")->get();
        foreach ($intimated as $claim) {
            $add((string) $claim->lob, 'intimated', (int) $claim->estimate);
        }
        $paid = DB::table('claim_payments as cp')->join('claims as c', 'c.id', '=', 'cp.claim_id')->join('policies as p', 'p.id', '=', 'c.policy_id')->join('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('c.entity_id', $entityId)->where('cp.status', 'paid')->whereBetween('cp.paid_on', [$from, $to])
            ->groupBy('pr.lob')->selectRaw('pr.lob, count(distinct c.id) as claims, sum(cp.amount_minor) as amount')->get();
        foreach ($paid as $row) {
            $add((string) $row->lob, 'paid', (int) $row->amount, (int) $row->claims);
        }
        $open = $this->outstanding->outstanding($entityId, $period->end)['rows'];
        $classes = DB::table('claims as c')->join('policies as p', 'p.id', '=', 'c.policy_id')->join('products as pr', 'pr.id', '=', 'p.product_id')
            ->whereIn('c.id', array_column($open, 'claim_id'))->pluck('pr.lob', 'c.id');
        foreach ($open as $row) {
            $add((string) ($classes[$row['claim_id']] ?? 'unknown'), 'outstanding', $row['outstanding_reserve_minor'] + $row['approved_unpaid_minor']);
        }

        return [['by_class' => self::withTotals($groups)], [
            'Claims intimated are claims reported in the period at their first reserve estimate; outstanding claims are open reserves plus approved claims not yet paid at the period end.',
            'IBNR (claims incurred but not reported) is in the technical provisions run, not in this form.',
        ]];
    }

    /**
     * @return array{0: array<string, array{rows: list<array<string, int|string|null>>, totals: array<string, int|string|null>|null}>, 1: list<string>}
     */
    private function expenses(string $entityId, RegulatoryPeriod $period): array
    {
        $labels = self::classLabels();
        $premium = [];
        foreach ($this->premiums->register($entityId, $period->start, $period->end)['rows'] as $row) {
            $premium[$row['class']] = ($premium[$row['class']] ?? 0) + ($row['type'] === 'cancellation' ? 0 : $row['net_minor']);
        }
        $commission = $this->statements->roleMovementByDimension($entityId, 'commission_expense', $period->start, $period->end, 'lob')['by_dimension'];
        foreach (array_keys($commission) as $class) {
            $premium[$class] ??= 0;
        }
        unset($premium['']);

        // ASSUMPTION A-263: management expenses are the expense accounts not mapped to claims, IBNR, commission or rounding, spread over classes by gross premium.
        $excluded = DB::table('account_role_mappings')->whereIn('role_code', (array) config('erp.regulatory.non_management_expense_roles', []))->pluck('account_id')->all();
        $management = 0;
        foreach ($this->statements->profitAndLoss($entityId, $period->start, $period->end)['expense'] as $account) {
            if (! in_array($account['account_id'], $excluded, true)) {
                $management += $account['amount_minor'];
            }
        }
        $totalPremium = array_sum($premium);
        $groups = [];
        $allocated = 0;
        $classes = array_keys($premium);
        sort($classes);
        foreach ($classes as $index => $class) {
            $share = $totalPremium > 0 ? ($index === count($classes) - 1 ? $management - $allocated : intdiv($management * $premium[$class], $totalPremium)) : 0;
            $allocated += $share;
            $comm = $commission[$class] ?? 0;
            $total = $comm + $share;
            $limitRate = (int) config("erp.regulatory.expense_limit_bp.{$class}", config('erp.regulatory.expense_limit_bp.default', 0));
            $limit = intdiv($premium[$class] * $limitRate, 10_000);
            $label = $labels[$class] ?? self::words($class);
            $groups[$label] = ['group' => $label, 'gross_premium' => $premium[$class], 'commission' => $comm, 'management' => $share, 'total' => $total,
                'ratio' => $premium[$class] > 0 ? intdiv($total * 10_000, $premium[$class]) : 0, 'limit_rate' => $limitRate, 'limit' => $limit, 'excess' => max(0, $total - $limit)];
        }
        $result = self::withTotals($groups);
        if ($result['totals'] !== null) {
            $gross = (int) $result['totals']['gross_premium'];
            $result['totals']['ratio'] = $gross > 0 ? intdiv((int) $result['totals']['total'] * 10_000, $gross) : 0;
            $result['totals']['limit_rate'] = null;
        }

        return [['by_class' => $result], [
            'Commission is the commission expense posted in the period by class. Management expenses are the other operating expenses in the ledger, spread over classes in proportion to gross premium (ASSUMPTION A-263).',
            'The expense limit rates are placeholders to verify against the current IDRA expense limits.',
        ]];
    }

    /**
     * @return array{0: array<string, array{rows: list<array<string, int|string|null>>, totals: array<string, int|string|null>|null}>, 1: list<string>}
     */
    private function agentRegister(RegulatoryPeriod $period): array
    {
        $licences = $this->licences->rows($period->end);
        $summary = [];
        foreach ($licences as $licence) {
            $type = self::words($licence['producer_type']);
            $summary[$type] ??= ['group' => $type, 'valid' => 0, 'expired' => 0, 'suspended' => 0, 'revoked' => 0, 'not_yet_valid' => 0, 'total' => 0, 'issued_in_period' => 0];
            if (isset($summary[$type][$licence['status']]) && $licence['status'] !== 'group') {
                $summary[$type][$licence['status']]++;
            }
            $summary[$type]['total']++;
            if ($licence['issued_on'] >= $period->start->toDateString() && $licence['issued_on'] <= $period->end->toDateString()) {
                $summary[$type]['issued_in_period']++;
            }
        }
        $rows = array_map(fn (array $l): array => ['licence_no' => $l['licence_no'], 'producer_code' => $l['producer_code'], 'producer_name' => $l['producer_name'],
            'producer_type' => self::words($l['producer_type']), 'class' => self::words($l['class']), 'branch_code' => $l['branch_code'], 'issued_on' => $l['issued_on'],
            'expires_on' => $l['expires_on'], 'status' => self::words($l['status'])], $licences);

        return [['summary' => self::withTotals($summary), 'licences' => ['rows' => $rows, 'totals' => null]], [
            'Licence standing is as at the period end. The full register is also downloadable from Producers (IDRA agency register).',
        ]];
    }

    /**
     * Another module may add reinsurance (`ri_cessions`); until then the form is empty with a note. With the table present, amounts are summed from its
     * `*_minor` columns by class where the column names are recognisable.
     *
     * @return array{0: array<string, array{rows: list<array<string, int|string|null>>, totals: array<string, int|string|null>|null}>, 1: list<string>}
     */
    private function reinsuranceCeded(string $entityId, RegulatoryPeriod $period): array
    {
        if (! Schema::hasTable('ri_cessions')) {
            return [['by_class' => ['rows' => [], 'totals' => null]], ['Reinsurance is not set up in this system yet, so there are no cessions to report for the period.']];
        }
        $columns = Schema::getColumnListing('ri_cessions');
        $pick = fn (array $candidates): ?string => array_values(array_intersect($candidates, $columns))[0] ?? null;
        $class = $pick(['class', 'class_code', 'lob']);
        $date = $pick(['ceded_on', 'accounting_date', 'effective_date', 'cession_date', 'created_at']);
        $premium = $pick(['ceded_premium_minor', 'premium_minor', 'amount_minor']);
        $commission = $pick(['commission_minor', 'ri_commission_minor', 'reinsurance_commission_minor']);
        $recoveries = $pick(['claims_recovered_minor', 'recovery_minor', 'recoveries_minor']);
        $query = DB::table('ri_cessions');
        if (in_array('entity_id', $columns, true)) {
            $query->where('entity_id', $entityId);
        }
        if ($date !== null) {
            $query->whereBetween($date, [$period->start->toDateString(), $period->end->endOfDay()->toDateTimeString()]);
        }
        $labels = self::classLabels();
        $groups = [];
        foreach ($query->get() as $cession) {
            /** @var array<string, mixed> $values */
            $values = (array) $cession;
            $group = $class === null ? 'All classes' : ($labels[(string) $values[$class]] ?? self::words((string) $values[$class]));
            $groups[$group] ??= ['group' => $group, 'cessions' => 0, 'ceded_premium' => 0, 'commission' => 0, 'recoveries' => 0];
            $groups[$group]['cessions']++;
            foreach (['ceded_premium' => $premium, 'commission' => $commission, 'recoveries' => $recoveries] as $key => $column) {
                $groups[$group][$key] += $column === null ? 0 : (int) $values[$column];
            }
        }

        return [['by_class' => self::withTotals($groups)], ['Summed from the reinsurance cessions recorded in the period.']];
    }

    /**
     * Rows sorted by group, list values (such as policy sets) counted, and a totals row of every numeric column.
     *
     * @param array<string, array<string, mixed>> $groups
     * @return array{rows: list<array<string, int|string|null>>, totals: array<string, int|string|null>|null}
     */
    private static function withTotals(array $groups): array
    {
        ksort($groups);
        $rows = [];
        $totals = ['group' => 'Total'];
        foreach ($groups as $group) {
            $row = [];
            foreach ($group as $key => $value) {
                $row[$key] = is_array($value) ? count($value) : (is_int($value) || is_string($value) ? $value : null);
                if (is_int($row[$key]) && $key !== 'group') {
                    $totals[$key] = (int) ($totals[$key] ?? 0) + $row[$key];
                }
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'totals' => $rows === [] ? null : $totals];
    }

    /** @return array<string, string> class code → name */
    private static function classLabels(): array
    {
        return DB::table('product_classes')->pluck('name_en', 'code')->mapWithKeys(fn (mixed $name, mixed $code): array => [(string) $code => (string) $name])->all();
    }

    private static function words(string $code): string
    {
        return ucfirst(str_replace('_', ' ', $code));
    }
}
