<?php

declare(strict_types=1);

use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use App\Modules\Platform\Numbering\Exceptions\NumberingException;
use App\Modules\Platform\Numbering\ReservationSweeperJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §2.1 document numbering. INVARIANT: every allocated number is reserved, used or voided —
 * no unexplained gap. Receipts and policies can be abandoned, so numbers are reserved first and
 * marked used in the same transaction as the business object.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant(); // fiscal year starts in July
    $this->receipts = new DocumentNumberScope($this->ctx['entity_id'], $this->ctx['branch_id'], 'receipt', 'RCT', CarbonImmutable::parse('2026-09-15'));
});

function numberer(): DocumentNumberer
{
    return app(DocumentNumberer::class);
}

it('allocates consecutive numbers per entity, branch, document type and fiscal year', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $headOffice = new DocumentNumberScope($this->ctx['entity_id'], null, 'receipt', 'RCT', CarbonImmutable::parse('2026-09-15'));
        $nextFiscalYear = new DocumentNumberScope($this->ctx['entity_id'], $this->ctx['branch_id'], 'receipt', 'RCT', CarbonImmutable::parse('2027-07-01'));

        expect(numberer()->reserve($this->receipts, null)->number)->toBe('RCT-2026-000001')
            ->and(numberer()->reserve($this->receipts, null)->number)->toBe('RCT-2026-000002')
            ->and(numberer()->reserve($headOffice, null)->number)->toBe('RCT-2026-000001')
            ->and(numberer()->reserve($nextFiscalYear, null)->number)->toBe('RCT-2027-000001');
    });
});

it('marks a reserved number used in the business transaction, exactly once', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $reserved = numberer()->reserve($this->receipts, (string) Str::uuid7());
        $receiptId = (string) Str::uuid7();

        DB::transaction(fn () => numberer()->markUsed($reserved->id, 'receipt', $receiptId));

        $row = DB::table('document_numbers')->where('id', $reserved->id)->first();
        expect($row?->status)->toBe('used')->and($row?->object_id)->toBe($receiptId)->and($row?->used_at)->not->toBeNull();

        $failure = thrownBy(fn () => DB::transaction(fn () => numberer()->markUsed($reserved->id, 'receipt', (string) Str::uuid7())), NumberingException::class);
        expect($failure->reasonCode)->toBe('NUMBER_NOT_RESERVED');
    });
});

it('refuses to mark a number used outside the business transaction', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $reserved = numberer()->reserve($this->receipts, null);

        expect(fn () => numberer()->markUsed($reserved->id, 'receipt', (string) Str::uuid7()))->toThrow(LogicException::class);
    });
});

it('voids a reserved number only with a reason', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $reserved = numberer()->reserve($this->receipts, null);

        expect(thrownBy(fn () => numberer()->void($reserved->id, '  ', (string) Str::uuid7()), NumberingException::class)->reasonCode)->toBe('REASON_REQUIRED');

        numberer()->void($reserved->id, 'receipt book page damaged', (string) Str::uuid7());

        $row = DB::table('document_numbers')->where('id', $reserved->id)->first();
        expect($row?->status)->toBe('voided')->and($row?->void_reason)->toBe('receipt book page damaged')
            ->and(numberer()->voidedNumbers($reserved->sequenceId))->toBe(['RCT-2026-000001']);
    });
});

it('does not void a number that is already used', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $reserved = numberer()->reserve($this->receipts, null);
        DB::transaction(fn () => numberer()->markUsed($reserved->id, 'receipt', (string) Str::uuid7()));

        expect(thrownBy(fn () => numberer()->void($reserved->id, 'mistake', (string) Str::uuid7()), NumberingException::class)->reasonCode)->toBe('NUMBER_NOT_RESERVED');
    });
});

it('sweeps reservations abandoned by a rolled-back business transaction once they expire, for every tenant', function (): void {
    $other = seedDemoTenant('sweep-other');
    $otherScope = new DocumentNumberScope($other['entity_id'], null, 'policy', 'POL', CarbonImmutable::parse('2026-09-15'));

    CarbonImmutable::setTestNow('2026-09-15 10:00:00');
    [$abandoned, $otherAbandoned] = [
        asTenant($this->ctx['tenant_id'], fn () => numberer()->reserve($this->receipts, null)),
        asTenant($other['tenant_id'], fn () => numberer()->reserve($otherScope, null)),
    ];
    asTenant($this->ctx['tenant_id'], function (): void {
        try {
            DB::transaction(function (): void {
                throw new RuntimeException('business transaction failed');
            });
        } catch (RuntimeException) {
        }
    });

    CarbonImmutable::setTestNow('2026-09-15 10:10:00');
    $fresh = asTenant($this->ctx['tenant_id'], fn () => numberer()->reserve($this->receipts, null));

    CarbonImmutable::setTestNow('2026-09-15 10:16:00'); // abandoned reservations are past the 15-minute TTL; $fresh is not
    app()->call([new ReservationSweeperJob(), 'handle']);

    $statusOf = fn (string $tenantId, string $id): ?string => asTenant($tenantId, fn () => DB::table('document_numbers')->where('id', $id)->value('status'));
    expect($statusOf($this->ctx['tenant_id'], $abandoned->id))->toBe('voided')
        ->and($statusOf($other['tenant_id'], $otherAbandoned->id))->toBe('voided')
        ->and($statusOf($this->ctx['tenant_id'], $fresh->id))->toBe('reserved')
        ->and(asTenant($this->ctx['tenant_id'], fn () => DB::table('document_numbers')->where('id', $abandoned->id)->value('void_reason')))->toBe('reservation_expired');
    CarbonImmutable::setTestNow();
});

it('leaves no unexplained gap: every allocated number exists and number rows cannot be deleted or renumbered', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $first = numberer()->reserve($this->receipts, null);
        $second = numberer()->reserve($this->receipts, null);
        numberer()->reserve($this->receipts, null);
        DB::transaction(fn () => numberer()->markUsed($first->id, 'receipt', (string) Str::uuid7()));
        numberer()->void($second->id, 'spoiled', (string) Str::uuid7());

        expect(numberer()->unexplainedGaps($first->sequenceId))->toBe([]);

        expect(fn () => DB::table('document_numbers')->where('id', $second->id)->delete())->toThrow(QueryException::class, 'IMMUTABLE_DOCUMENT_NUMBER')
            ->and(fn () => DB::table('document_numbers')->where('id', $second->id)->update(['number' => 'RCT-2026-999999']))->toThrow(QueryException::class, 'IMMUTABLE_DOCUMENT_NUMBER')
            ->and(fn () => DB::table('document_numbers')->where('id', $first->id)->update(['status' => 'reserved']))->toThrow(QueryException::class, 'IMMUTABLE_DOCUMENT_NUMBER');
    });
});

it('numbers policies per entity and branch with the branch code: POL-<BRANCH>-<FY>-<seq> (fix F1)', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        DB::table('branches')->insert(['id' => $ctg = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'CTG', 'name' => 'Chattogram', 'status' => 'active']);
        $policy = fn (string $branchId): DocumentNumberScope => new DocumentNumberScope($this->ctx['entity_id'], $branchId, 'policy', 'POL', CarbonImmutable::parse('2026-09-15'));

        expect(numberer()->reserve($policy($this->ctx['branch_id']), null)->number)->toBe('POL-HO-2026-000001')
            ->and(numberer()->reserve($policy($this->ctx['branch_id']), null)->number)->toBe('POL-HO-2026-000002')
            ->and(numberer()->reserve($policy($ctg), null)->number)->toBe('POL-CTG-2026-000001')
            // Other documents keep their format.
            ->and(numberer()->reserve($this->receipts, null)->number)->toBe('RCT-2026-000001');
    });
});

it('takes the number format from the numbering settings, and never renumbers what was issued (fix F1)', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $policy = new DocumentNumberScope($this->ctx['entity_id'], $this->ctx['branch_id'], 'policy', 'POL', CarbonImmutable::parse('2026-09-15'));
        $first = numberer()->reserve($policy, null);

        config(['erp.numbering.formats.policy' => '{prefix}/{fy}/{branch}/{seq}']);
        expect(numberer()->reserve($policy, null)->number)->toBe('POL/2026/HO/000002')
            ->and(DB::table('document_numbers')->where('id', $first->id)->value('number'))->toBe('POL-HO-2026-000001');

        // An entity-level sequence has no branch: the branch part is left out.
        config(['erp.numbering.formats.policy' => '{prefix}-{branch}-{fy}-{seq}']);
        $entityLevel = new DocumentNumberScope($this->ctx['entity_id'], null, 'policy', 'POL', CarbonImmutable::parse('2026-09-15'));
        expect(numberer()->reserve($entityLevel, null)->number)->toBe('POL-2026-000001');
    });
});
