<?php

declare(strict_types=1);

namespace App\Http\People;

use App\Http\Pages\FormDefaults;
use App\Http\Pages\ObjectHistory;
use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Party\Domain\PartyContact;
use App\Modules\People\Employee\Application\EmployeeService;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * People → Employees (addendum §B.9.7, MVP): the employees queue and the employee page (Overview · Employment history · Payslips · Audit), composed at the
 * app layer: the person party from Insurance\Party, the employee and employment from People, the producer link from Distribution's table.
 */
final class EmployeesPageController
{
    public const AREA = ['hr.manage_employees', 'payroll.prepare', 'payroll.approve', 'payroll.pay', 'payroll.manage_rules'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request, FormDefaults $defaults): Response
    {
        $actor = PageSupport::actor($request);
        // A branch-scoped HR user lists only the employees working in their branches.
        $reach = $this->permissions->authorizeArea($actor, self::AREA);
        $today = app(BusinessClock::class)->today()->toDateString();
        $entity = PageSupport::entity();
        $currency = $entity['currency'];
        // Home queues link here with ?missing=bank (no salary account: payroll cannot be approved) or ?missing=tin.
        $missing = in_array($request->query('missing'), ['bank', 'tin'], true) ? (string) $request->query('missing') : '';
        $page = $reach->constrain($this->current($today), 'e.entity_id', 'm.branch_id')
            ->when($missing !== '', fn ($q) => $q->where('e.status', 'active')->whereNull($missing === 'bank' ? 'e.account_no_masked' : 'e.tin_masked'))
            ->paginate(PageSupport::listPageSize())->withQueryString();
        $items = $page->getCollection();
        $producers = DB::table('producers')->whereIn('employee_id', $items->pluck('id'))->pluck('code', 'employee_id');

        return Inertia::render('people/employees/Index', [
            'employees' => PageSupport::page($page, $items->map(fn (object $e): array => ['id' => (string) $e->id, 'code' => (string) $e->code, 'name' => (string) $e->full_name, 'designation' => $e->designation,
                'department' => $e->department, 'branch' => $e->branch_code, 'grade' => $e->grade_code, 'type' => $e->employment_type, 'joined_on' => (string) $e->joined_on,
                'basic' => $e->basic_minor === null ? null : PageSupport::money((int) $e->basic_minor, $currency), 'status' => (string) $e->status,
                'bank' => $e->account_no_masked === null ? null : trim($e->bank_name.' '.$e->account_no_masked), 'tin' => $e->tin_masked, 'producer_code' => $producers[$e->id] ?? null])->values()->all()),
            'filters' => ['missing' => $missing],
            'options' => $this->options(),
            'defaultBranchId' => $defaults->branch($actor, $entity['id']),
            'can' => ['manage' => $this->permissions->has($actor, 'hr.manage_employees')],
        ]);
    }

    public function store(Request $request, PartyService $parties, EmployeeService $employees): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $entity = PageSupport::entity();
        /** @var array{code: string, full_name: string, joined_on: string, branch_id: string, department_id: string, designation_id: string, grade_id: string, employment_type: string, basic: string,
         *     date_of_birth?: string|null, gender?: string|null, tin?: string|null, nid?: string|null, mobile?: string|null, bank_name?: string|null, bank_branch?: string|null, routing_no?: string|null, account_no?: string|null} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:32', Rule::unique('employees', 'code')], 'full_name' => ['required', 'string', 'max:160'],
            'joined_on' => ['required', 'date_format:Y-m-d'], 'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')], 'department_id' => ['required', 'uuid', Rule::exists('departments', 'id')],
            'designation_id' => ['required', 'uuid', Rule::exists('designations', 'id')], 'grade_id' => ['required', 'uuid', Rule::exists('grades', 'id')],
            'employment_type' => ['required', Rule::in(EmployeeService::TYPES)], 'basic' => ['required', 'string'], 'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'other'])], 'tin' => ['nullable', 'string', 'max:20'], 'nid' => ['nullable', 'string', 'max:20'], 'mobile' => ['nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:80'], 'bank_branch' => ['nullable', 'string', 'max:80'], 'routing_no' => ['nullable', 'string', 'max:16'], 'account_no' => ['nullable', 'string', 'max:32']],
            ['code.unique' => 'Another employee has this code.']);
        $basic = PageSupport::minor('basic', $data['basic'], $entity['currency']);
        $id = DB::transaction(function () use ($data, $parties, $employees, $actor, $entity, $basic): string {
            $party = $parties->create(PartyKind::Individual, $data['full_name'], ($data['tin'] ?? null) ?: null, [PartyRoleType::Employee], $actor,
                new PartyContact(mobile: ($data['mobile'] ?? null) ?: null, identityNo: ($data['nid'] ?? null) ?: null, dateOfBirth: ($data['date_of_birth'] ?? null) ? CarbonImmutable::parse($data['date_of_birth']) : null));

            return $employees->hire($entity['id'], ['party_id' => $party->id, 'basic_minor' => $basic] + $data, $actor);
        });

        return redirect("/people/employees/{$id}")->with('status', "Employee {$data['code']} hired.");
    }

    public function show(Request $request, string $employee, ObjectHistory $history): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $model = DB::table('employees')->where('id', $employee)->first() ?? abort(404);
        $currency = PageSupport::entity()['currency'];
        $money = fn (int $minor): string => PageSupport::money($minor, $currency);
        $names = fn (string $table) => DB::table($table)->pluck($table === 'branches' || $table === 'grades' ? 'code' : 'name', 'id');
        [$branches, $departments, $designations, $grades] = [$names('branches'), $names('departments'), $names('designations'), $names('grades')];
        $history_ = DB::table('employments')->where('employee_id', $employee)->orderByDesc('effective_from')->get();
        $current = $history_->first();
        $producer = DB::table('producers')->where('employee_id', $employee)->first(['id', 'code', 'type']);

        return Inertia::render('people/employees/Show', [
            'employee' => ['id' => (string) $model->id, 'code' => (string) $model->code, 'name' => (string) $model->full_name, 'status' => (string) $model->status, 'joined_on' => (string) $model->joined_on,
                'mobile' => $model->mobile, 'date_of_birth' => $model->date_of_birth, 'gender' => $model->gender, 'tin' => $model->tin_masked, 'nid' => $model->nid_masked,
                'bank' => $model->account_no_masked === null ? null : trim("{$model->bank_name}, {$model->bank_branch} · {$model->account_no_masked}"), 'routing_no' => $model->routing_no],
            'current' => $current === null ? null : ['branch_id' => (string) $current->branch_id, 'department_id' => (string) $current->department_id, 'designation_id' => (string) $current->designation_id,
                'grade_id' => (string) $current->grade_id, 'employment_type' => (string) $current->employment_type, 'basic' => $money((int) $current->basic_minor)],
            'facts' => $current === null ? [] : [['label' => 'Designation', 'value' => (string) ($designations[$current->designation_id] ?? '')], ['label' => 'Department', 'value' => (string) ($departments[$current->department_id] ?? '')],
                ['label' => 'Branch', 'value' => (string) ($branches[$current->branch_id] ?? '')], ['label' => 'Grade', 'value' => (string) ($grades[$current->grade_id] ?? '')],
                ['label' => 'Basic salary', 'value' => $money((int) $current->basic_minor)]],
            'history' => $history_->map(fn (object $h): array => ['from' => (string) $h->effective_from, 'to' => $h->effective_to === null ? null : (string) $h->effective_to, 'kind' => (string) $h->change_kind,
                'branch' => (string) ($branches[$h->branch_id] ?? ''), 'department' => (string) ($departments[$h->department_id] ?? ''), 'designation' => (string) ($designations[$h->designation_id] ?? ''),
                'grade' => (string) ($grades[$h->grade_id] ?? ''), 'type' => (string) $h->employment_type, 'basic' => $money((int) $h->basic_minor), 'note' => $h->note])->values()->all(),
            'payslips' => DB::table('payslips as p')->join('payroll_runs as r', 'r.id', '=', 'p.run_id')->where('p.employee_id', $employee)->orderByDesc('r.period_year')->orderByDesc('r.period_month')
                ->get(['p.id', 'p.number', 'r.id as run_id', 'r.period_year', 'r.period_month', 'r.status', 'p.gross_minor', 'p.tax_minor', 'p.net_minor', 'p.commission_minor'])
                ->map(fn (object $p): array => ['id' => (string) $p->id, 'number' => $p->number, 'run_id' => (string) $p->run_id, 'period' => sprintf('%04d-%02d-01', $p->period_year, $p->period_month),
                    'status' => (string) $p->status, 'gross' => $money((int) $p->gross_minor), 'tax' => $money((int) $p->tax_minor), 'commission' => $money((int) $p->commission_minor), 'net' => $money((int) $p->net_minor)])->values()->all(),
            'producer' => $producer === null ? null : ['id' => (string) $producer->id, 'code' => (string) $producer->code, 'type' => (string) $producer->type],
            'options' => $this->options(),
            'audit' => Inertia::defer(fn (): array => $history->audit([['employee', $model->id]]), 'history'),
            'can' => ['manage' => $this->permissions->has($actor, 'hr.manage_employees')],
        ]);
    }

    public function change(Request $request, string $employee, EmployeeService $employees): RedirectResponse
    {
        /** @var array{kind: string, effective_from: string, branch_id?: string|null, department_id?: string|null, designation_id?: string|null, grade_id?: string|null, employment_type?: string|null, basic?: string|null, note?: string|null} $data */
        $data = $request->validate(['kind' => ['required', Rule::in(EmployeeService::CHANGE_KINDS)], 'effective_from' => ['required', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'uuid', Rule::exists('branches', 'id')], 'department_id' => ['nullable', 'uuid', Rule::exists('departments', 'id')],
            'designation_id' => ['nullable', 'uuid', Rule::exists('designations', 'id')], 'grade_id' => ['nullable', 'uuid', Rule::exists('grades', 'id')],
            'employment_type' => ['nullable', Rule::in(EmployeeService::TYPES)], 'basic' => ['nullable', 'string'], 'note' => ['nullable', 'string', 'max:500']]);
        $changes = array_filter(['branch_id' => $data['branch_id'] ?? null, 'department_id' => $data['department_id'] ?? null, 'designation_id' => $data['designation_id'] ?? null,
            'grade_id' => $data['grade_id'] ?? null, 'employment_type' => $data['employment_type'] ?? null], fn (?string $v): bool => $v !== null && $v !== '');
        if (($data['basic'] ?? '') !== '') {
            $changes['basic_minor'] = PageSupport::minor('basic', $data['basic'], PageSupport::entity()['currency']);
        }
        $employees->changeEmployment($employee, $data['kind'], CarbonImmutable::parse($data['effective_from']), $changes + ['note' => $data['note'] ?? null], PageSupport::actor($request));

        return back()->with('status', 'Employment change recorded from '.CarbonImmutable::parse($data['effective_from'])->format('j M Y').'.');
    }

    /** @return \Illuminate\Database\Query\Builder employees with the employment in force on $day */
    private function current(string $day): \Illuminate\Database\Query\Builder
    {
        return DB::table('employees as e')
            ->leftJoin('employments as m', fn ($j) => $j->on('m.employee_id', '=', 'e.id')->where('m.effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('m.effective_to')->orWhere('m.effective_to', '>', $day)))
            ->leftJoin('designations as d', 'd.id', '=', 'm.designation_id')->leftJoin('departments as dep', 'dep.id', '=', 'm.department_id')
            ->leftJoin('branches as b', 'b.id', '=', 'm.branch_id')->leftJoin('grades as g', 'g.id', '=', 'm.grade_id')
            ->orderBy('e.code')
            ->select(['e.*', 'm.employment_type', 'm.basic_minor', 'd.name as designation', 'dep.name as department', 'b.code as branch_code', 'g.code as grade_code']);
    }

    /** @return array<string, list<array{id: string, label: string}>> */
    private function options(): array
    {
        $list = fn (string $table, string $label) => DB::table($table)->where($table === 'branches' ? 'status' : 'status', 'active')->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (object $r): array => ['id' => (string) $r->id, 'label' => $label === 'both' ? "{$r->code} · {$r->name}" : (string) $r->name])->values()->all();

        return ['branches' => array_values($list('branches', 'both')), 'departments' => array_values($list('departments', 'name')), 'designations' => array_values($list('designations', 'name')), 'grades' => array_values($list('grades', 'both'))];
    }
}
