<?php

declare(strict_types=1);

namespace App\Modules\People\Payroll\Application;

use App\Modules\Accounting\Application\Close\CloseTaskDefinition;
use App\Modules\Accounting\Application\Close\CloseTaskKind;
use App\Modules\Accounting\Application\Contracts\CloseCheckResult;
use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Accounting\Application\Contracts\CloseTaskContributor;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use Illuminate\Support\Facades\DB;

/**
 * Addendum §B.2.8 close catalogue rows 9: `payroll_posted` (a month with employees has its payroll posted) and `payroll_reconciliation` (salary payable
 * per the payslips and commission inputs equals the GL), for entities that have employees. Owner payroll, permission `payroll.approve`.
 */
final class PayrollCloseTasks implements CloseTaskContributor, CloseTaskCheck
{
    public function definitions(): array
    {
        return [
            new CloseTaskDefinition(12, 'payroll_posted', CloseTaskKind::Check, [], 'payroll', PayrollRunService::APPROVE),
            new CloseTaskDefinition(12, 'payroll_reconciliation', CloseTaskKind::Reconciliation, ['payroll_posted'], 'payroll', PayrollRunService::APPROVE, subledger: 'payroll'),
        ];
    }

    public function appliesTo(string $entityId): bool
    {
        return DB::table('employees')->where('entity_id', $entityId)->exists();
    }

    public function taskCode(): string
    {
        return 'payroll_posted';
    }

    public function check(FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        $employed = DB::table('employees')->where('entity_id', $period->entityId)->where('joined_on', '<=', $period->ends->toDateString())
            ->where(fn ($q) => $q->whereNull('separated_on')->orWhere('separated_on', '>=', $period->starts->toDateString()))->count();
        $run = DB::table('payroll_runs')->where('entity_id', $period->entityId)->where('period_year', $period->ends->year)->where('period_month', $period->ends->month)
            ->where('kind', 'regular')->where('status', '<>', 'cancelled')->first(['number', 'status']);
        $details = ['employees' => $employed, 'run' => $run?->number, 'status' => $run?->status];
        if ($employed === 0) {
            return CloseCheckResult::passed('Nobody was employed this month.', $details);
        }

        return $run !== null && in_array($run->status, ['posted', 'paid'], true)
            ? CloseCheckResult::passed("Payroll {$run->number} is {$run->status}.", $details)
            : CloseCheckResult::blocked("{$employed} employee(s) were employed this month and the month's payroll is ".($run === null ? 'not calculated' : 'still a preview').'.', $details);
    }
}
