<?php

declare(strict_types=1);

namespace App\Http\People;

use App\Http\Pages\ObjectHistory;
use App\Http\Pages\PageSupport;
use App\Modules\People\Payroll\Application\PayrollRunService;
use App\Modules\People\Payroll\Application\PayslipDocument;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * People → Payroll runs (addendum §B.10.11, MVP): the runs queue, the run workbench (calculate → preview per employee with the trace → approve and post with the
 * journal preview → pay with the salary bank file → payslips), the payslips list and the payslip PDF.
 */
final class PayrollRunsPageController
{
    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, EmployeesPageController::AREA);
        $entity = PageSupport::entity();
        $money = fn (mixed $minor): string => PageSupport::money((int) $minor, $entity['currency']);
        $today = app(BusinessClock::class)->today();
        $runs = DB::table('payroll_runs')->where('entity_id', $entity['id'])->orderByDesc('period_year')->orderByDesc('period_month')->paginate(PageSupport::listPageSize())->withQueryString();

        return Inertia::render('people/payroll/Index', [
            'runs' => PageSupport::page($runs, $runs->getCollection()
                ->map(fn (object $r): array => ['id' => (string) $r->id, 'number' => $r->number, 'period' => sprintf('%04d-%02d-01', $r->period_year, $r->period_month), 'status' => (string) $r->status,
                    'employees' => (int) $r->employee_count, 'gross' => $money($r->gross_minor), 'tax' => $money($r->tax_minor), 'pf' => $money((int) $r->pf_employee_minor + (int) $r->pf_employer_minor),
                    'commission' => $money($r->commission_minor), 'net' => $money($r->net_minor), 'posted_on' => $r->posted_on, 'paid_on' => $r->paid_on])->values()->all()),
            // The months a run can be calculated for, and those that already have one (the picker starts at the first month without a run).
            'taken' => DB::table('payroll_runs')->where('entity_id', $entity['id'])->get(['period_year', 'period_month'])->map(fn (object $r): string => sprintf('%04d-%02d-01', $r->period_year, $r->period_month))->values()->all(),
            'months' => array_map(fn (int $i): string => $today->startOfMonth()->subMonthsNoOverflow($i - 1)->toDateString(), range(0, 6)),
            'can' => ['prepare' => $this->permissions->has($actor, PayrollRunService::PREPARE)],
        ]);
    }

    public function calculate(Request $request, PayrollRunService $runs): RedirectResponse
    {
        /** @var array{period: string} $data */
        $data = $request->validate(['period' => ['required', 'date_format:Y-m-d']]);
        $month = CarbonImmutable::parse($data['period']);
        $id = $runs->calculate(PageSupport::entity()['id'], $month->year, $month->month, PageSupport::actor($request));

        return redirect("/people/payroll/{$id}")->with('status', 'Payroll for '.$month->format('F Y').' calculated. Check the preview, then someone else approves it.');
    }

    public function show(Request $request, string $run, ObjectHistory $history, SodGuard $sod): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, EmployeesPageController::AREA);
        $model = DB::table('payroll_runs')->where('id', $run)->first() ?? abort(404);
        $money = fn (mixed $minor): string => PageSupport::money((int) $minor, (string) $model->currency);
        $slips = DB::table('payslips as p')->join('employees as e', 'e.id', '=', 'p.employee_id')->where('p.run_id', $run)->orderBy('e.code')
            ->get(['p.*', 'e.code', 'e.full_name']);
        $lines = DB::table('payslip_lines')->whereIn('payslip_id', $slips->pluck('id'))->orderBy('line_no')->get()->groupBy('payslip_id');
        $subject = AuditSubject::of('payroll_run', $run);
        $journalIds = DB::table('journals')->where('source_type', 'payroll_run')->where('source_id', $run)->pluck('id')
            ->map(fn ($id): string => (string) $id)->values()->all();
        $journalIds = array_values($journalIds);

        return Inertia::render('people/payroll/Show', [
            'run' => ['id' => (string) $model->id, 'number' => $model->number, 'period' => sprintf('%04d-%02d-01', $model->period_year, $model->period_month), 'status' => (string) $model->status,
                'employees' => (int) $model->employee_count, 'gross' => $money($model->gross_minor), 'bonus' => $money($model->bonus_minor), 'commission' => $money($model->commission_minor),
                'tax' => $money($model->tax_minor), 'pf_employee' => $money($model->pf_employee_minor), 'pf_employer' => $money($model->pf_employer_minor), 'net' => $money($model->net_minor),
                'calculated_at' => $model->calculated_at, 'posted_on' => $model->posted_on, 'paid_on' => $model->paid_on,
                'prepared_by' => DB::table('users')->where('id', $model->prepared_by)->value('name'), 'approved_by' => $model->approved_by === null ? null : DB::table('users')->where('id', $model->approved_by)->value('name'),
                'paid_by' => $model->paid_by === null ? null : DB::table('users')->where('id', $model->paid_by)->value('name')],
            'payslips' => $slips->map(function (object $p) use ($money, $lines): array {
                $mine = $lines->get($p->id, collect());
                $sum = fn (array $codes): string => $money($mine->whereIn('component_code', $codes)->sum('amount_minor'));
                /** @var array<string, mixed> $snapshot */
                $snapshot = json_decode((string) $p->employment_snapshot, true, 512, JSON_THROW_ON_ERROR);

                return ['id' => (string) $p->id, 'number' => $p->number, 'employee_id' => (string) $p->employee_id, 'code' => (string) $p->code, 'name' => (string) $p->full_name,
                    'designation' => (string) ($snapshot['designation'] ?? ''), 'department' => (string) ($snapshot['department'] ?? ''), 'branch' => (string) ($snapshot['branch'] ?? ''),
                    'basic' => $money($p->basic_minor), 'allowances' => $sum(['house_rent', 'medical', 'conveyance']), 'bonus' => $money($p->bonus_minor), 'commission' => $money($p->commission_minor),
                    'gross' => $money($p->gross_minor), 'pf' => $money($p->pf_employee_minor), 'tax' => $money($p->tax_minor), 'net' => $money($p->net_minor), 'employer_pf' => $money($p->pf_employer_minor),
                    'bank' => $p->bank_account_snapshot === null ? null : (string) (json_decode((string) $p->bank_account_snapshot, true)['account_no_masked'] ?? ''),
                    'lines' => $mine->map(fn (object $l): array => ['kind' => (string) $l->kind, 'label' => (string) $l->label, 'amount' => $money($l->amount_minor), 'pre_accrued' => (bool) $l->pre_accrued])->values()->all(),
                    'trace' => array_map(fn (array $t): array => ['step' => (string) $t['step'], 'value' => is_int($t['value']) ? $money($t['value']) : (string) $t['value']],
                        json_decode((string) $p->trace, true, 512, JSON_THROW_ON_ERROR))];
            })->values()->all(),
            'inputs' => DB::table('payroll_inputs as i')->leftJoin('employees as e', 'e.id', '=', 'i.employee_id')->where('i.period_year', $model->period_year)->where('i.period_month', $model->period_month)
                ->orderBy('i.created_at')->get(['i.id', 'i.component_code', 'i.amount_minor', 'i.status', 'i.parked_reason', 'i.source_reference', 'i.pre_accrued', 'i.taxable', 'i.accrued_on', 'e.code', 'e.full_name'])
                ->map(fn (object $i): array => ['id' => (string) $i->id, 'component' => (string) $i->component_code, 'employee' => $i->code === null ? 'Unknown employee' : "{$i->code} · {$i->full_name}",
                    'amount' => $money($i->amount_minor), 'status' => (string) $i->status, 'parked_reason' => $i->parked_reason, 'reference' => $i->source_reference, 'pre_accrued' => (bool) $i->pre_accrued,
                    'taxable' => (bool) $i->taxable, 'accrued_on' => $i->accrued_on])->values()->all(),
            'bankFiles' => DB::table('salary_bank_files')->where('run_id', $run)->orderByDesc('version')->get(['id', 'version', 'file_name', 'total_minor', 'item_count', 'generated_at'])
                ->map(fn (object $f): array => ['id' => (string) $f->id, 'version' => (int) $f->version, 'name' => (string) $f->file_name, 'total' => $money($f->total_minor), 'items' => (int) $f->item_count,
                    'generated_at' => (string) $f->generated_at])->values()->all(),
            'bankAccounts' => DB::table('bank_accounts')->where('entity_id', $model->entity_id)->where('status', 'active')->get(['id', 'bank_name', 'account_no_masked'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'accounting' => Inertia::defer(fn (): array => $history->accounting($journalIds), 'history'),
            'audit' => Inertia::defer(fn (): array => $history->audit([['payroll_run', $run]]), 'history'),
            'can' => [
                'prepare' => $model->status === 'preview' && $this->permissions->has($actor, PayrollRunService::PREPARE),
                'approve' => $model->status === 'preview' && $this->permissions->has($actor, PayrollRunService::APPROVE) && ! $sod->wouldBlock($actor, PayrollRunService::APPROVE, $subject),
                'pay' => $model->status === 'posted' && $this->permissions->has($actor, PayrollRunService::PAY) && ! $sod->wouldBlock($actor, PayrollRunService::PAY, $subject),
                'approve_blocked_by_sod' => $model->status === 'preview' && $this->permissions->has($actor, PayrollRunService::APPROVE) && $sod->wouldBlock($actor, PayrollRunService::APPROVE, $subject),
                'pay_blocked_by_sod' => $model->status === 'posted' && $this->permissions->has($actor, PayrollRunService::PAY) && $sod->wouldBlock($actor, PayrollRunService::PAY, $subject),
            ],
        ]);
    }

    public function approve(Request $request, string $run, PayrollRunService $runs): RedirectResponse
    {
        $runs->approve($run, PageSupport::actor($request));
        $number = DB::table('payroll_runs')->where('id', $run)->value('number');

        return back()->with('status', "Payroll {$number} approved and posted. Someone else releases the salary transfer.");
    }

    public function pay(Request $request, string $run, PayrollRunService $runs): RedirectResponse
    {
        /** @var array{bank_account_id: string, paid_on?: string|null} $data */
        $data = $request->validate(['bank_account_id' => ['required', 'uuid'], 'paid_on' => ['nullable', 'date_format:Y-m-d']]);
        $runs->pay($run, $data['bank_account_id'], CarbonImmutable::parse(($data['paid_on'] ?? null) ?: app(BusinessClock::class)->today()->toDateString()), PageSupport::actor($request));

        return back()->with('status', 'Salaries released: the bank file is ready to download and send to the bank.');
    }

    public function bankFile(Request $request, string $run, string $file): HttpResponse
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), [PayrollRunService::APPROVE, PayrollRunService::PAY]);
        $row = DB::table('salary_bank_files')->where('run_id', $run)->where('id', $file)->first(['file_name', 'content_enc']) ?? abort(404);

        return response(Crypt::decryptString((string) $row->content_enc), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$row->file_name.'"',
            'X-Content-Type-Options' => 'nosniff']);
    }

    public function payslips(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, EmployeesPageController::AREA);
        $entity = PageSupport::entity();
        $money = fn (mixed $minor): string => PageSupport::money((int) $minor, $entity['currency']);
        $runId = is_string($request->query('run')) && \Illuminate\Support\Str::isUuid($request->query('run')) ? $request->query('run') : null;
        $slips = DB::table('payslips as p')->join('payroll_runs as r', 'r.id', '=', 'p.run_id')->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->where('r.entity_id', $entity['id'])->when($runId !== null, fn ($q) => $q->where('p.run_id', $runId))
            ->orderByDesc('r.period_year')->orderByDesc('r.period_month')->orderBy('e.code')
            ->select(['p.id', 'p.number', 'p.run_id', 'r.period_year', 'r.period_month', 'r.status', 'e.id as employee_id', 'e.code', 'e.full_name', 'p.gross_minor', 'p.tax_minor', 'p.pf_employee_minor', 'p.net_minor'])
            ->paginate(PageSupport::listPageSize())->withQueryString();

        return Inertia::render('people/payslips/Index', [
            'runId' => $runId,
            'runs' => DB::table('payroll_runs')->where('entity_id', $entity['id'])->orderByDesc('period_year')->orderByDesc('period_month')->get(['id', 'number', 'period_year', 'period_month', 'status'])
                ->map(fn (object $r): array => ['id' => (string) $r->id, 'label' => CarbonImmutable::parse(sprintf('%04d-%02d-01', (int) $r->period_year, (int) $r->period_month))->format('F Y').' · '.($r->number ?? 'preview')])->values()->all(),
            'payslips' => PageSupport::page($slips, $slips->getCollection()
                ->map(fn (object $p): array => ['id' => (string) $p->id, 'number' => $p->number, 'run_id' => (string) $p->run_id, 'period' => sprintf('%04d-%02d-01', $p->period_year, $p->period_month),
                    'status' => (string) $p->status, 'employee_id' => (string) $p->employee_id, 'code' => (string) $p->code, 'name' => (string) $p->full_name, 'gross' => $money($p->gross_minor),
                    'tax' => $money($p->tax_minor), 'pf' => $money($p->pf_employee_minor), 'net' => $money($p->net_minor)])->values()->all()),
        ]);
    }

    public function payslipPdf(Request $request, string $payslip, PayslipDocument $documents): HttpResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, EmployeesPageController::AREA);
        $locale = $request->query('locale') === 'bn' ? 'bn' : 'en';
        $document = $documents->render($payslip, $actor, $locale);

        return response($document['pdf'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$document['name'].'"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
