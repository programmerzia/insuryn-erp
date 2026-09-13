<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Licences;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Distribution design note §3 "IDRA agent register export". ASSUMPTION A-16: IDRA's register file format is not specified (electronic returns
 * are LATER): CSV with a header row, one row per licence, columns and their order from erp.distribution.idra_register_columns. `status` is the
 * licence's standing on the as-of date: valid, expired, not_yet_valid, suspended or revoked.
 */
final class IdraRegisterExport
{
    /** @return list<array<string, string>> */
    public function rows(CarbonImmutable $asOf): array
    {
        $day = $asOf->toDateString();
        $rows = DB::table('producer_licences as l')->join('producers as p', 'p.id', '=', 'l.producer_id')->leftJoin('parties as pa', 'pa.id', '=', 'p.party_id')
            ->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')->orderBy('p.code')->orderBy('l.issued_on')
            ->get(['l.licence_no', 'l.authority', 'p.code', 'pa.display_name', 'p.type', 'l.class', 'l.issued_on', 'l.expires_on', 'l.status', 'b.code as branch_code']);

        return array_values($rows->map(fn (\stdClass $r): array => [
            'licence_no' => (string) $r->licence_no, 'authority' => (string) $r->authority, 'producer_code' => (string) $r->code, 'producer_name' => (string) $r->display_name,
            'producer_type' => (string) $r->type, 'class' => (string) $r->class, 'issued_on' => (string) $r->issued_on, 'expires_on' => (string) $r->expires_on,
            'status' => match (true) {
                $r->status !== 'active' => (string) $r->status,
                (string) $r->expires_on < $day => 'expired',
                (string) $r->issued_on > $day => 'not_yet_valid',
                default => 'valid',
            },
            'branch_code' => (string) $r->branch_code,
        ])->all());
    }

    public function csv(CarbonImmutable $asOf): string
    {
        /** @var list<string> $columns */
        $columns = config('erp.distribution.idra_register_columns');
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a temporary stream for the register export.');
        }
        fputcsv($stream, $columns, escape: '');
        foreach ($this->rows($asOf) as $row) {
            fputcsv($stream, array_map(fn (string $column): string => $row[$column] ?? '', $columns), escape: '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }
}
