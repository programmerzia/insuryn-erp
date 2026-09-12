<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Journal numbers: JV-<FY>-<seq>, allocated under the sequence row lock (design §2.1).
 * Journals are posted in the same transaction as numbering, so 'reserved'→'used' collapses to one step here.
 * Document types that can be abandoned (receipts, policies) must use the two-step DocumentNumberer (TODO Phase 1A).
 */
final class JournalNumberer
{
    private const PREFIX = 'JV';

    public function next(string $entityId, string $bookId, CarbonImmutable $on): string
    {
        $fiscalYear = $this->fiscalYear($on);
        $docType = 'JV:'.$bookId;

        $allocated = $this->allocate($entityId, $docType, $fiscalYear);
        if ($allocated === null) {
            $this->createSequence($entityId, $docType, $fiscalYear);
            $allocated = $this->allocate($entityId, $docType, $fiscalYear)
                ?? throw new LogicException("Number sequence {$docType} {$fiscalYear} is not visible in the current tenant context.");
        }

        return sprintf('%s-%d-%06d', $allocated->prefix, $fiscalYear, (int) $allocated->allocated_no);
    }

    /**
     * Atomically takes the next number: one statement, row-locked until the posting transaction ends.
     *
     * @return object{prefix: string, allocated_no: int|string}|null null when the sequence does not exist yet
     */
    private function allocate(string $entityId, string $docType, int $fiscalYear): ?object
    {
        /** @var object{prefix: string, allocated_no: int|string}|null */
        return DB::selectOne(
            'update number_sequences set next_no = next_no + 1, updated_at = now()
             where entity_id = ? and branch_id is null and doc_type = ? and fiscal_year = ?
             returning prefix, next_no - 1 as allocated_no',
            [$entityId, $docType, $fiscalYear],
        );
    }

    /** Concurrent first use converges on one row: the scope is unique with NULLS NOT DISTINCT. */
    private function createSequence(string $entityId, string $docType, int $fiscalYear): void
    {
        DB::table('number_sequences')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'branch_id' => null,
            'doc_type' => $docType, 'fiscal_year' => $fiscalYear, 'prefix' => self::PREFIX, 'next_no' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fiscalYear(CarbonImmutable $on): int
    {
        $startMonth = (int) (DB::table('tenants')->where('id', TenantContext::id())->value('fiscal_year_start_month') ?? 1);

        return $on->month >= $startMonth ? $on->year : $on->year - 1;
    }
}
