<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\JournalNumberer;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Design §2.1 numbering scope and §6.1 control accounts, enforced by the schema itself. */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
});

it('allows only one entity-level number sequence per document type and fiscal year', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $sequence = fn (): array => ['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'branch_id' => null, 'doc_type' => 'JV:'.$this->ctx['book_id'], 'fiscal_year' => 2026, 'prefix' => 'JV', 'next_no' => 1];

        DB::table('number_sequences')->insert($sequence());

        expect(fn () => DB::table('number_sequences')->insert($sequence()))->toThrow(UniqueConstraintViolationException::class);
    });
});

it('numbers journals consecutively within a fiscal year', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $numberer = app(JournalNumberer::class);
        $on = CarbonImmutable::parse('2026-09-15');

        $numbers = DB::transaction(fn (): array => [
            $numberer->next($this->ctx['entity_id'], $this->ctx['book_id'], $on),
            $numberer->next($this->ctx['entity_id'], $this->ctx['book_id'], $on),
        ]);

        expect($numbers)->toBe(['JV-2026-000001', 'JV-2026-000002']);
    });
});

it('maps every control account of a subledger, including both claims controls', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $claimsControls = DB::table('subledger_controls')->where('subledger', 'claims')->orderBy('control_account_role')->pluck('control_account_role')->all();

        expect($claimsControls)->toBe(['claims_outstanding', 'claims_payable']);
    });
});
