<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §4.2 "bank_main (bank_accounts.gl_account_id overrides role)": an event may name, in payload.account_overrides, the account
 * for a role. The kernel accepts it only for roles configured as overridable (erp.posting.overridable_roles) and only for an active,
 * postable account of the event's entity; anything else fails the event with INVALID_ACCOUNT_OVERRIDE and posts nothing.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->dims = ['branch' => $this->ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(), 'product_code' => 'MOTOR', 'lob' => 'motor', 'channel' => 'direct'];
    $this->account = function (array $overrides = []): string {
        $id = (string) Str::uuid7();
        DB::table('accounts')->insert(array_merge(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'X'.substr($id, -6),
            'name' => 'Second bank', 'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true, 'is_control' => false, 'status' => 'active'], $overrides));

        return $id;
    };
});

/**
 * @param array{entity_id: string} $ctx
 * @param array<string, string> $dims
 */
function premiumReceivedWith(array $ctx, array $dims, mixed $overrides): AccountingEvent
{
    return DB::transaction(fn () => app(SubmitAccountingEvent::class)($ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'PREMIUM_RECEIVED:'.Str::uuid7(),
        CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 10_000, 'account_overrides' => $overrides], $dims))->refresh();
}

it('posts the overridable role to the named account', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $second = ($this->account)();
        $event = premiumReceivedWith($this->ctx, $this->dims, ['bank_main' => $second]);

        expect($event->status->value)->toBe('posted')
            ->and(DB::table('journal_lines')->where('role_code', 'bank_main')->value('account_id'))->toBe($second)
            ->and(DB::table('journal_lines')->where('role_code', 'premium_receivable')->value('account_id'))->toBe($this->ctx['accounts']['premium_receivable']);
    });
});

it('fails the event for overrides the kernel must not accept', function (string $case): void {
    asTenant($this->ctx['tenant_id'], function () use ($case): void {
        $overrides = match ($case) {
            'role not overridable' => ['premium_receivable' => ($this->account)()],
            'unknown account' => ['bank_main' => (string) Str::uuid7()],
            'not postable' => ['bank_main' => ($this->account)(['is_postable' => false])],
            'inactive' => ['bank_main' => ($this->account)(['status' => 'inactive'])],
            default => 'bank_main',
        };
        $event = premiumReceivedWith($this->ctx, $this->dims, $overrides);

        expect($event->status->value)->toBe('failed')
            ->and((string) $event->failure_reason)->toStartWith('INVALID_ACCOUNT_OVERRIDE')
            ->and(DB::table('journals')->count())->toBe(0);
    });
})->with(['role not overridable', 'unknown account', 'not postable', 'inactive', 'malformed']);
