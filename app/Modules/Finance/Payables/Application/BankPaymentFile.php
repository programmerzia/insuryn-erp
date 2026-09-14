<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Application;

use App\Modules\Finance\Payables\Domain\Enums\PaymentRunStatus;
use App\Modules\Finance\Payables\Domain\Models\PaymentRun;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The bank payment file of a released run (addendum v2 §B.4, slice 2.4): one row per payee in a BEFTN-style CSV — payee name, bank, branch, routing
 * number, account number, amount, reference and value date (ASSUMPTION A-245: a generic format until the bank names its own, CQ-I1). Built only from
 * the released run's items and their bank account snapshots; every download is recorded append-only (version, SHA-256, total) and its total always
 * equals the run total.
 */
final class BankPaymentFile
{
    public const FORMAT = 'beftn_csv';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{name: string, contents: string, version: int}
     *
     * @throws BusinessRuleViolation PAYMENT_RUN_NOT_RELEASED
     */
    public function generate(string $runId, string $actorUserId): array
    {
        $run = PaymentRun::query()->findOrFail($runId);
        $this->permissions->authorizeAny($actorUserId, [PaymentRunService::RELEASE, PaymentRunService::PREPARE], AuthorizationScope::entity($run->entity_id));
        if ($run->status !== PaymentRunStatus::Released) {
            throw new BusinessRuleViolation('PAYMENT_RUN_NOT_RELEASED', "Payment run {$run->number} is {$run->status->value}; the bank file comes from a released run.");
        }
        $csv = self::csv($run);

        return DB::transaction(function () use ($run, $csv, $actorUserId): array {
            DB::table('payment_runs')->where('id', $run->id)->lockForUpdate()->value('id'); // versions are numbered one at a time per run
            $version = (int) DB::table('bank_payment_files')->where('run_id', $run->id)->where('format_code', self::FORMAT)->max('version') + 1;
            $sha = hash('sha256', $csv);
            DB::table('bank_payment_files')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'run_id' => $run->id, 'format_code' => self::FORMAT,
                'version' => $version, 'stored_document_id' => null, 'sha256' => $sha, 'total_minor' => $run->total_minor, 'item_count' => $run->item_count,
                'generated_by' => $actorUserId, 'generated_at' => CarbonImmutable::now()]);
            $this->audit->record('payment_run.bank_file_generated', AuditSubject::of('payment_run', $run->id), null, ['format' => self::FORMAT, 'version' => $version, 'sha256' => $sha,
                'total_minor' => $run->total_minor], null, PaymentRunService::RELEASE, Actor::user($actorUserId));

            return ['name' => "{$run->number}-beftn-v{$version}.csv", 'contents' => $csv, 'version' => $version];
        });
    }

    public static function csv(PaymentRun $run): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a temporary stream for the bank file.');
        }
        fputcsv($stream, ['Sl', 'Payee Name', 'Bank Name', 'Branch', 'Routing No', 'Account No', 'Amount', 'Reference', 'Value Date'], escape: '');
        $rows = DB::table('payment_run_items as i')->join('parties as p', 'p.id', '=', 'i.payee_party_id')
            ->leftJoin('ap_bills as b', 'b.id', '=', 'i.payable_id')->where('i.run_id', $run->id)->where('i.status', 'released')->orderBy('p.display_name')->orderBy('b.number')
            ->get(['i.bank_account_snapshot', 'i.amount_minor', 'p.display_name', 'b.number as bill_number', 'b.supplier_reference']);
        $total = 0;
        foreach ($rows->values() as $index => $item) {
            /** @var array{account_name?: string|null, bank_name?: string|null, bank_branch?: string|null, routing_no?: string|null, account_no_enc?: string|null} $bank */
            $bank = (array) json_decode((string) $item->bank_account_snapshot, true);
            $amount = (int) $item->amount_minor;
            $total += $amount;
            fputcsv($stream, [(string) ($index + 1), (string) ($bank['account_name'] ?? $item->display_name), (string) ($bank['bank_name'] ?? ''), (string) ($bank['bank_branch'] ?? ''),
                (string) ($bank['routing_no'] ?? ''), ($bank['account_no_enc'] ?? null) === null ? '' : (string) decrypt((string) $bank['account_no_enc']), self::major($amount),
                mb_substr("{$run->number} {$item->bill_number} {$item->supplier_reference}", 0, 80), $run->pay_date->toDateString()], escape: '');
        }
        if ($total !== $run->total_minor) {
            throw new \LogicException("Bank file total {$total} differs from payment run {$run->number} total {$run->total_minor}.");
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** 8500000 → "85000.00" (BDT has two decimals; no thousands separator in bank files). */
    private static function major(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
