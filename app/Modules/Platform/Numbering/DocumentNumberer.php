<?php

declare(strict_types=1);

namespace App\Modules\Platform\Numbering;

use App\Modules\Platform\Numbering\Exceptions\NumberingException;
use App\Modules\Platform\Tenancy\FiscalCalendar;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Design §2.1 DECISION: reserve → used in the business transaction, or voided (manually with a reason, or
 * by the sweeper when a reservation expires). INVARIANT: every allocated number is reserved, used or
 * voided — the database refuses deletes and renumbering (trigger protect_document_numbers).
 */
final class DocumentNumberer
{
    public function __construct(private readonly FiscalCalendar $calendar) {}

    /**
     * Takes the next number and records it as reserved, committed on its own so the number survives a
     * rolled-back business transaction (the sweeper then voids it). Call before the business transaction.
     */
    public function reserve(DocumentNumberScope $scope, ?string $reservedBy): ReservedNumber
    {
        return DB::transaction(function () use ($scope, $reservedBy): ReservedNumber {
            $fiscalYear = $this->calendar->fiscalYear($scope->businessDate);
            $allocated = $this->allocate($scope, $fiscalYear);
            if ($allocated === null) {
                $this->createSequence($scope, $fiscalYear);
                $allocated = $this->allocate($scope, $fiscalYear)
                    ?? throw new LogicException("Number sequence {$scope->docType} {$fiscalYear} is not visible in the current tenant context.");
            }

            $reserved = new ReservedNumber(
                id: (string) Str::uuid7(),
                sequenceId: $allocated->id,
                number: sprintf('%s-%d-%06d', $allocated->prefix, $fiscalYear, (int) $allocated->allocated_no),
            );
            DB::table('document_numbers')->insert([
                'id' => $reserved->id, 'tenant_id' => TenantContext::id(), 'sequence_id' => $reserved->sequenceId,
                'sequence_no' => (int) $allocated->allocated_no, 'number' => $reserved->number, 'status' => 'reserved',
                'reserved_by' => $reservedBy, 'reserved_at' => CarbonImmutable::now(),
            ]);

            return $reserved;
        });
    }

    /**
     * Links the number to the business object. Must run inside the business transaction, so the number is
     * used if and only if the object commits.
     *
     * @throws NumberingException NUMBER_NOT_RESERVED when expired, voided or already used
     */
    public function markUsed(string $documentNumberId, string $objectType, string $objectId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DocumentNumberer::markUsed must run inside the business transaction.');
        }
        $updated = DB::table('document_numbers')->where('id', $documentNumberId)->where('status', 'reserved')
            ->update(['status' => 'used', 'object_type' => $objectType, 'object_id' => $objectId, 'used_at' => CarbonImmutable::now()]);
        if ($updated !== 1) {
            throw new NumberingException('NUMBER_NOT_RESERVED', "Document number {$documentNumberId} is not reserved (expired, voided or already used).");
        }
    }

    /**
     * Manual void of a reserved number (design §2.1: needs numbering.void + reason; the permission is
     * checked by the caller's authorization layer). Used numbers belong to issued documents: cancel the
     * document instead.
     *
     * @throws NumberingException REASON_REQUIRED, NUMBER_NOT_RESERVED
     */
    public function void(string $documentNumberId, string $reason, string $voidedBy): void
    {
        if (trim($reason) === '') {
            throw new NumberingException('REASON_REQUIRED', 'Voiding a document number requires a reason.');
        }
        $updated = DB::table('document_numbers')->where('id', $documentNumberId)->where('status', 'reserved')
            ->update(['status' => 'voided', 'void_reason' => trim($reason), 'voided_by' => $voidedBy]);
        if ($updated !== 1) {
            throw new NumberingException('NUMBER_NOT_RESERVED', "Document number {$documentNumberId} is not reserved and cannot be voided.");
        }
    }

    /** Voids reservations older than the TTL in the current tenant (design §2.1 sweeper). Returns how many. */
    public function voidExpiredReservations(int $ttlMinutes): int
    {
        return DB::table('document_numbers')->where('status', 'reserved')
            ->where('reserved_at', '<', CarbonImmutable::now()->subMinutes($ttlMinutes))
            ->update(['status' => 'voided', 'void_reason' => 'reservation_expired']);
    }

    /**
     * "Voided numbers" report for one sequence, in number order.
     *
     * @return list<string>
     */
    public function voidedNumbers(string $sequenceId): array
    {
        /** @var list<string> */
        return DB::table('document_numbers')->where('sequence_id', $sequenceId)->where('status', 'voided')
            ->orderBy('sequence_no')->pluck('number')->all();
    }

    /**
     * Allocated sequence numbers with no document_numbers row; empty when the invariant holds.
     *
     * @return list<int>
     */
    public function unexplainedGaps(string $sequenceId): array
    {
        $rows = DB::select(
            'select s.no from number_sequences q
             cross join lateral generate_series(1, q.next_no - 1) as s(no)
             where q.id = ? and not exists (select 1 from document_numbers d where d.sequence_id = q.id and d.sequence_no = s.no)
             order by s.no',
            [$sequenceId],
        );

        /** @var list<object{no: int|string}> $rows */
        return array_map(fn (object $row): int => (int) $row->no, $rows);
    }

    /** @return object{id: string, prefix: string, allocated_no: int|string}|null null when the sequence does not exist yet */
    private function allocate(DocumentNumberScope $scope, int $fiscalYear): ?object
    {
        /** @var object{id: string, prefix: string, allocated_no: int|string}|null */
        return DB::selectOne(
            'update number_sequences set next_no = next_no + 1, updated_at = now()
             where entity_id = ? and branch_id is not distinct from ?::uuid and doc_type = ? and fiscal_year = ?
             returning id, prefix, next_no - 1 as allocated_no',
            [$scope->entityId, $scope->branchId, $scope->docType, $fiscalYear],
        );
    }

    private function createSequence(DocumentNumberScope $scope, int $fiscalYear): void
    {
        DB::table('number_sequences')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'entity_id' => $scope->entityId,
            'branch_id' => $scope->branchId, 'doc_type' => $scope->docType, 'fiscal_year' => $fiscalYear,
            'prefix' => $scope->prefix, 'next_no' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
