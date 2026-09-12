<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\ReversalService;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant();
    $this->dims = ['branch' => $this->ctx['branch_id'], 'product' => (string) Str::uuid7(), 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(), 'product_code' => 'MOTOR', 'lob' => 'motor', 'channel' => 'direct'];
    $this->submit = function (string $type, array $payload, string $key, string $date = '2026-09-15'): AccountingEvent {
        return DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            $this->ctx['entity_id'], $type, 'test', (string) Str::uuid7(), $key,
            CarbonImmutable::parse($date), CarbonImmutable::parse($date), 'BDT', $payload, $this->dims));
    };
});

it('never posts an unbalanced journal, at the DB level', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $j = Journal::query()->create(['entity_id' => $this->ctx['entity_id'], 'book_id' => $this->ctx['book_id'],
            'period_id' => DB::table('fiscal_periods')->where('period', 3)->value('id'), 'transaction_date' => '2026-09-15', 'posting_date' => '2026-09-15',
            'effective_date' => '2026-09-15', 'status' => 'draft', 'kind' => 'manual', 'currency' => 'BDT']);
        DB::table('journal_lines')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'journal_id' => $j->id, 'line_no' => 1,
            'account_id' => $this->ctx['accounts']['bank_main'], 'side' => 'debit', 'amount_minor' => 100, 'currency' => 'BDT', 'base_amount_minor' => 100]);
        expect(fn () => DB::transaction(fn () => $j->forceFill(['status' => 'posted', 'number' => 'X-1'])->save()))
            ->toThrow(\Illuminate\Database\QueryException::class, 'UNBALANCED_JOURNAL');
    });
});

it('refuses to update or delete a posted journal or its lines', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $ev = ($this->submit)('PREMIUM_RECEIVED', ['amount' => 1000], 'k1');
        [$j] = app(PostingEngine::class)->post($ev->id);
        expect(fn () => DB::table('journals')->where('id', $j->id)->update(['description' => 'hacked']))->toThrow(\Illuminate\Database\QueryException::class, 'IMMUTABLE_JOURNAL');
        expect(fn () => DB::table('journal_lines')->where('journal_id', $j->id)->update(['amount_minor' => 1]))->toThrow(\Illuminate\Database\QueryException::class, 'IMMUTABLE_JOURNAL');
        expect(fn () => DB::table('journal_lines')->where('journal_id', $j->id)->delete())->toThrow(\Illuminate\Database\QueryException::class, 'IMMUTABLE_JOURNAL');
        expect(fn () => DB::table('journals')->where('id', $j->id)->delete())->toThrow(\Illuminate\Database\QueryException::class, 'IMMUTABLE_JOURNAL');
    });
});

it('rejects posting into a locked period and records the failure reason', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        DB::table('fiscal_periods')->where('period', 3)->update(['status' => 'locked']);
        $ev = ($this->submit)('PREMIUM_RECEIVED', ['amount' => 1000], 'k2');
        expect(app(PostingEngine::class)->post($ev->id))->toBe([]);
        $ev->refresh();
        expect($ev->status->value)->toBe('failed')->and($ev->failure_reason)->toStartWith('PERIOD_CLOSED');
        expect(Journal::query()->count())->toBe(0);
    });
});

it('is idempotent: same key twice → one event, one journal; posting twice → one journal', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $a = ($this->submit)('PREMIUM_RECEIVED', ['amount' => 1000], 'same-key');
        $b = ($this->submit)('PREMIUM_RECEIVED', ['amount' => 1000], 'same-key');
        expect($a->id)->toBe($b->id)->and(AccountingEvent::query()->count())->toBe(1);
        app(PostingEngine::class)->post($a->id);
        app(PostingEngine::class)->post($a->id);
        expect(Journal::query()->count())->toBe(1);
    });
});

it('fails with DIMENSION_MISSING when a required dimension is absent', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        unset($this->dims['policy']);
        $ev = ($this->submit)('PREMIUM_RECEIVED', ['amount' => 1000], 'k3');
        app(PostingEngine::class)->post($ev->id);
        expect($ev->fresh()?->failure_reason)->toStartWith('DIMENSION_MISSING');
    });
});

it('reverses with mirrored lines and links both journals; original is untouched except status', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $ev = ($this->submit)('POLICY_ISSUED', ['gross_premium' => 12000, 'net_premium' => 10435, 'tax' => 1565], 'k4');
        [$orig] = app(PostingEngine::class)->post($ev->id);
        $rev = app(ReversalService::class)->reverse($orig->fresh(), CarbonImmutable::create(2026, 9, 20), 'issued in error', (string) Str::uuid7());
        $orig->refresh();
        expect($orig->status->value)->toBe('reversed')->and($orig->reversed_by_journal_id)->toBe($rev->id)->and($rev->reverses_journal_id)->toBe($orig->id);
        $o = $orig->lines->map(fn ($l) => [$l->role_code, $l->side->value, $l->amount_minor])->all();
        $r = $rev->lines->map(fn ($l) => [$l->role_code, $l->side->value === 'debit' ? 'credit' : 'debit', $l->amount_minor])->all();
        expect($r)->toEqual($o);
        expect(fn () => app(ReversalService::class)->reverse($orig->fresh(), CarbonImmutable::create(2026, 9, 21), 'again', 'u'))->toThrow(\App\Modules\Accounting\Exceptions\PostingFailedException::class);
    });
});

it('keeps the trial balance balanced after a mix of postings', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        foreach ([['POLICY_ISSUED', ['gross_premium' => 12000, 'net_premium' => 10435, 'tax' => 1565]], ['PREMIUM_RECEIVED', ['amount' => 5000]], ['PREMIUM_EARNED', ['earned' => 858]]] as $i => [$t, $p]) {
            app(PostingEngine::class)->post(($this->submit)($t, $p, 'tb'.$i)->id);
        }
        $tb = app(\App\Modules\Accounting\Application\LedgerQuery::class)->trialBalance($this->ctx['entity_id'], $this->ctx['book_id'], CarbonImmutable::create(2026, 9, 30));
        expect(array_sum(array_column($tb, 'debit')))->toBe(array_sum(array_column($tb, 'credit')))->and($tb)->not->toBeEmpty();
    });
});
