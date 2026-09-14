<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Reinsurance\Application\BordereauQuery;
use Carbon\CarbonImmutable;

/**
 * Reinsurance MVP: the premium and claims bordereaux in the shape of ReportsPageController's report pages, so they open from the reports index and download as
 * CSV or XLSX through the same export path (GET /reports/{key}/export). Listed in ReportsPageController::CATALOGUE.
 */
final class ReinsuranceReportTables
{
    private const KINDS = ['sbc' => 'SBC compulsory', 'quota_share' => 'Quota share', 'surplus' => 'Surplus', 'facultative' => 'Facultative'];

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    public static function table(string $key, string $entityId, CarbonImmutable $from, CarbonImmutable $to, callable $money): array
    {
        $query = app(BordereauQuery::class);
        if ($key === 'ri-claims-bordereau') {
            $rows = $query->claims($entityId, $from->toDateString(), $to->toDateString());

            return self::shape('Claims bordereau', [['reinsurer', 'Reinsurer'], ['recorded_on', 'Date'], ['claim_number', 'Claim'], ['policy_number', 'Policy'], ['loss_date', 'Loss'],
                ['status', 'Claim status'], ['kind', 'Movement'], ['share', 'Share %', 'right'], ['gross', 'Gross', 'right'], ['ceded', 'Reinsurer share', 'right']],
                array_map(fn (array $r): array => ['cells' => ['reinsurer' => "{$r['reinsurer_code']} · {$r['reinsurer']}", 'recorded_on' => $r['recorded_on'], 'claim_number' => $r['claim_number'],
                    'policy_number' => $r['policy_number'], 'loss_date' => $r['loss_date'], 'status' => ucfirst(str_replace('_', ' ', $r['status'])),
                    'kind' => $r['kind'] === 'reserve' ? 'Reserve change' : 'Payment recoverable', 'share' => PageSupport::percent($r['share_bp']), 'gross' => $money($r['gross_minor']),
                    'ceded' => $money($r['amount_minor'])], 'link' => "/claims/{$r['claim_id']}"], $rows),
                ['gross' => '', 'ceded' => $money(array_sum(array_column($rows, 'amount_minor')))]);
        }
        $rows = $query->premium($entityId, $from->toDateString(), $to->toDateString());
        $byReinsurer = [];
        foreach ($rows as $r) {
            $group = "{$r['reinsurer_code']} · {$r['reinsurer']}";
            $byReinsurer[$group] ??= ['premium' => 0, 'commission' => 0];
            $byReinsurer[$group]['premium'] += $r['premium_minor'];
            $byReinsurer[$group]['commission'] += $r['commission_minor'];
        }

        return self::shape('Premium bordereau', [['reinsurer', 'Reinsurer'], ['kind', 'Basis'], ['movement', 'Movement'], ['accounting_date', 'Date'], ['policy_number', 'Policy'],
            ['insured', 'Insured'], ['class', 'Class'], ['cover', 'Cover'], ['sum_insured', 'Sum insured', 'right'], ['share', 'Share %', 'right'], ['ceded_si', 'Ceded sum insured', 'right'],
            ['premium', 'Ceded premium', 'right'], ['commission', 'Commission', 'right'], ['net', 'Net due', 'right']],
            array_map(fn (array $r): array => ['cells' => ['reinsurer' => "{$r['reinsurer_code']} · {$r['reinsurer']}", 'kind' => (self::KINDS[$r['kind']] ?? $r['kind']).($r['treaty'] === null ? '' : " ({$r['treaty']})"),
                'movement' => ucfirst($r['movement']), 'accounting_date' => $r['accounting_date'], 'policy_number' => $r['policy_number'], 'insured' => $r['insured'],
                'class' => ucfirst(str_replace('_', ' ', (string) $r['class_code'])), 'cover' => "{$r['inception']} to {$r['expiry']}", 'sum_insured' => $money($r['sum_insured_minor']),
                'share' => PageSupport::percent($r['share_bp']), 'ceded_si' => $money($r['ceded_sum_insured_minor']), 'premium' => $money($r['premium_minor']),
                'commission' => $money($r['commission_minor']), 'net' => $money($r['net_minor'])], 'link' => "/policies/{$r['policy_id']}?tab=reinsurance"], $rows),
            ['premium' => $money(array_sum(array_column($rows, 'premium_minor'))), 'commission' => $money(array_sum(array_column($rows, 'commission_minor'))),
                'net' => $money(array_sum(array_column($rows, 'net_minor')))],
            [['title' => 'Totals by reinsurer', 'columns' => [['key' => 'group', 'label' => 'Reinsurer'], ['key' => 'premium', 'label' => 'Ceded premium'], ['key' => 'commission', 'label' => 'Commission'], ['key' => 'net', 'label' => 'Net due']],
                'rows' => array_map(fn (string $group, array $g): array => ['cells' => ['group' => $group, 'premium' => $money($g['premium']), 'commission' => $money($g['commission']),
                    'net' => $money($g['premium'] - $g['commission'])], 'link' => null], array_keys($byReinsurer), array_values($byReinsurer))]]);
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string}> $columns
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $totals
     * @param list<array<string, mixed>> $summaries
     * @return array<string, mixed>
     */
    private static function shape(string $title, array $columns, array $rows, array $totals, array $summaries = []): array
    {
        return ['title' => $title, 'filter' => 'range', 'columns' => array_map(fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'align' => $c[2] ?? 'left'], $columns),
            'rows' => $rows, 'totals' => $totals, 'summaries' => $summaries];
    }
}
