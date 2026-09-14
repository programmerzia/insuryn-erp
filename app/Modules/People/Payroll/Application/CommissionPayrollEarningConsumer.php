<?php

declare(strict_types=1);

namespace App\Modules\People\Payroll\Application;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Messaging\OutboxConsumer;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design addendum §B.11 payroll route: a commission statement paid through payroll (COMMISSION_PAYOUT_TO_PAYROLL already moved its net from commission_payable
 * to salary_payable) becomes a pre-accrued `commission` payroll input of the employee, so the next payroll run shows it on the payslip and pays it, and never
 * posts it as an expense again (§B.10.5). People never reads producers or statements: everything comes from the message (PD-2).
 *
 * - Once per statement (unique by source, INVARIANT): a redelivered message changes nothing.
 * - An employee the message does not name, or who is unknown or separated, is parked with EMPLOYEE_UNKNOWN / EMPLOYEE_SEPARATED on the payroll inputs list —
 *   never dropped or guessed.
 * - ASSUMPTION A-282: the input goes to the month the statement was paid through payroll (its liability reached salary payable then), or the first later
 *   month whose regular run is not posted yet.
 * - ASSUMPTION A-283 (CQ-F4 open): taxable = the payroll setting `commission_taxable`, false by default: tax was withheld when the commission was earned.
 */
final class CommissionPayrollEarningConsumer implements OutboxConsumer
{
    public function __construct(private readonly Audit $audit) {}

    public function messageType(): string
    {
        return 'CommissionPayrollEarning';
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload, string $messageId): void
    {
        $statementId = (string) ($payload['commission_statement_id'] ?? '');
        if (! Str::isUuid($statementId) || DB::table('payroll_inputs')->where('source_type', 'commission_statement')->where('source_id', $statementId)->exists()) {
            return;
        }
        $employeeRef = is_string($payload['employee_id'] ?? null) ? $payload['employee_id'] : null;
        $employee = $employeeRef !== null && Str::isUuid($employeeRef) ? DB::table('employees')->where('id', $employeeRef)->first(['id', 'entity_id', 'status']) : null;
        $parked = match (true) {
            $employee === null => 'EMPLOYEE_UNKNOWN',
            $employee->status === 'separated' => 'EMPLOYEE_SEPARATED',
            default => null,
        };
        $today = app(BusinessClock::class)->today();
        $periodEnd = is_string($payload['period_end'] ?? null) ? CarbonImmutable::parse($payload['period_end']) : $today;
        $accruedOn = is_string($payload['paid_on'] ?? null) ? CarbonImmutable::parse($payload['paid_on']) : $today;
        [$year, $month] = $this->openMonth($employee === null ? null : (string) $employee->entity_id, $accruedOn->greaterThan($periodEnd) ? $accruedOn : $periodEnd);
        $id = (string) Str::uuid7();
        $taxable = $employee === null ? false : (bool) (DB::table('payroll_settings')->where('entity_id', $employee->entity_id)->whereNull('effective_to')->value('commission_taxable') ?? false);

        DB::table('payroll_inputs')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $employee?->entity_id, 'employee_id' => $parked === 'EMPLOYEE_UNKNOWN' ? null : $employee?->id,
            'employee_ref' => $employeeRef, 'period_year' => $year, 'period_month' => $month, 'component_code' => (string) ($payload['earning_type'] ?? 'commission'),
            'amount_minor' => (int) ($payload['amount_minor'] ?? 0), 'currency' => (string) ($payload['currency'] ?? 'BDT'), 'source_type' => 'commission_statement', 'source_id' => $statementId,
            'source_reference' => is_string($payload['reference'] ?? null) ? $payload['reference'] : null, 'pre_accrued' => true, 'taxable' => $taxable,
            'accrued_on' => $accruedOn->toDateString(), 'status' => $parked === null ? 'open' : 'parked', 'parked_reason' => $parked, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('payroll_input.received', AuditSubject::of('payroll_input', $id), null, ['source' => 'commission_statement', 'commission_statement_id' => $statementId,
            'amount_minor' => (int) ($payload['amount_minor'] ?? 0), 'period' => sprintf('%04d-%02d', $year, $month), 'parked_reason' => $parked, 'outbox_message_id' => $messageId], null, null, Actor::system());
    }

    /** @return array{0: int, 1: int} */
    private function openMonth(?string $entityId, CarbonImmutable $from): array
    {
        $month = $from->startOfMonth();
        for ($i = 0; $i < 24 && $entityId !== null; $i++) {
            $closed = DB::table('payroll_runs')->where('entity_id', $entityId)->where('period_year', $month->year)->where('period_month', $month->month)
                ->where('kind', 'regular')->whereIn('status', ['posted', 'paid'])->exists();
            if (! $closed) {
                break;
            }
            $month = $month->addMonthNoOverflow();
        }

        return [$month->year, $month->month];
    }
}
