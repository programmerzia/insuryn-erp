<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Journal numbers: JV-<FY>-<seq>, allocated under row lock on number_sequences (design §2.1).
 * Journals are posted in the same transaction as numbering, so 'reserved'→'used' collapses to one step here.
 * Document types that can be abandoned (receipts, policies) must use the two-step DocumentNumberer (TODO Phase 1A).
 */
final class JournalNumberer
{
    public function next(string $entityId, string $bookId, CarbonImmutable $on): string
    {
        $fy = $this->fiscalYear($on);
        $tenantId = TenantContext::id();
        $seq = DB::table('number_sequences')->where('entity_id', $entityId)->whereNull('branch_id')
            ->where('doc_type', 'JV:'.$bookId)->where('fiscal_year', $fy)->lockForUpdate()->first();
        if ($seq === null) {
            DB::table('number_sequences')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entity_id' => $entityId,
                'branch_id' => null, 'doc_type' => 'JV:'.$bookId, 'fiscal_year' => $fy, 'prefix' => 'JV', 'next_no' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $seq = DB::table('number_sequences')->where('entity_id', $entityId)->whereNull('branch_id')
                ->where('doc_type', 'JV:'.$bookId)->where('fiscal_year', $fy)->lockForUpdate()->first();
        }
        $no = (int) $seq->next_no;
        DB::table('number_sequences')->where('id', $seq->id)->update(['next_no' => $no + 1, 'updated_at' => now()]);
        return sprintf('%s-%d-%06d', $seq->prefix, $fy, $no);
    }

    private function fiscalYear(CarbonImmutable $on): int
    {
        $start = (int) (DB::table('tenants')->where('id', TenantContext::id())->value('fiscal_year_start_month') ?? 1);
        return $on->month >= $start ? $on->year : $on->year - 1;
    }
}
