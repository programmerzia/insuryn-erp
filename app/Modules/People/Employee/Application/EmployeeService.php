<?php

declare(strict_types=1);

namespace App\Modules\People\Employee\Application;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Employees and their effective-dated employment (design §B.9, MVP). An employee is a person party (created by the caller, People never reads Insurance)
 * plus the employee record; employment history is never updated: a promotion, transfer or pay change ends the record in force the day before the new one
 * starts (INVARIANT one record per day, enforced by the database too). TIN, NID and the salary account number are stored encrypted and shown masked.
 *
 * DECISION D-122: MVP applies employment changes directly (audited, `hr.manage_employees`); the change request with approval of §B.9.3 is LATER, and
 * the payroll approver still cannot be the preparer (§B.10.5).
 */
final class EmployeeService
{
    public const PERMISSION = 'hr.manage_employees';

    public const TYPES = ['permanent', 'probation', 'contract', 'intern'];

    public const CHANGE_KINDS = ['promotion', 'transfer', 'pay_change'];

    public function __construct(private readonly PermissionChecker $permissions, private readonly Audit $audit) {}

    /**
     * @param array{party_id: string, code: string, full_name: string, joined_on: string, branch_id: string, department_id: string, designation_id: string, grade_id: string,
     *     employment_type: string, basic_minor: int, currency?: string, date_of_birth?: string|null, gender?: string|null, tin?: string|null, nid?: string|null, mobile?: string|null,
     *     bank_name?: string|null, bank_branch?: string|null, routing_no?: string|null, account_no?: string|null, id?: string|null} $data
     *
     * @throws BusinessRuleViolation EMPLOYEE_CODE_TAKEN, EMPLOYMENT_TYPE_INVALID, EMPLOYEE_BASIC_INVALID
     */
    public function hire(string $entityId, array $data, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::branch($entityId, $data['branch_id']));
        $this->assertTerms($data['employment_type'], $data['basic_minor']);
        if (DB::table('employees')->where('code', $data['code'])->exists()) {
            throw new BusinessRuleViolation('EMPLOYEE_CODE_TAKEN', "Another employee has code {$data['code']}.");
        }
        $id = ($data['id'] ?? null) ?: (string) Str::uuid7();
        $currency = $data['currency'] ?? (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');

        DB::transaction(function () use ($id, $entityId, $data, $currency, $actorUserId): void {
            $joined = CarbonImmutable::parse($data['joined_on']);
            DB::table('employees')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'party_id' => $data['party_id'], 'code' => $data['code'],
                'full_name' => $data['full_name'], 'status' => 'active', 'joined_on' => $joined->toDateString(), 'date_of_birth' => $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null, 'mobile' => $data['mobile'] ?? null, 'created_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now(),
                ...self::secret('tin', $data['tin'] ?? null), ...self::secret('nid', $data['nid'] ?? null), ...self::bank($data)]);
            $this->insertEmployment($id, $entityId, $data, $currency, $joined, 'hire', null, $actorUserId);
            $this->audit->record('employee.hired', AuditSubject::of('employee', $id), null, ['code' => $data['code'], 'joined_on' => $joined->toDateString(),
                'branch_id' => $data['branch_id'], 'grade_id' => $data['grade_id'], 'employment_type' => $data['employment_type']], null, self::PERMISSION, Actor::user($actorUserId));
        });

        return $id;
    }

    /**
     * A promotion, transfer or pay change from a date: the record in force then ends the day before, and later records are refused (history is appended).
     *
     * @param array{branch_id?: string, department_id?: string, designation_id?: string, grade_id?: string, employment_type?: string, basic_minor?: int, note?: string|null} $changes
     *
     * @throws BusinessRuleViolation EMPLOYMENT_CHANGE_KIND_INVALID, EMPLOYEE_NOT_ACTIVE, EMPLOYMENT_CHANGE_NOT_LATEST, EMPLOYMENT_CHANGE_EMPTY
     */
    public function changeEmployment(string $employeeId, string $kind, CarbonImmutable $effectiveFrom, array $changes, string $actorUserId): string
    {
        if (! in_array($kind, self::CHANGE_KINDS, true)) {
            throw new BusinessRuleViolation('EMPLOYMENT_CHANGE_KIND_INVALID', 'Choose a promotion, a transfer or a pay change.');
        }
        $employee = DB::table('employees')->where('id', $employeeId)->first(['id', 'entity_id', 'status', 'code']) ?? throw new BusinessRuleViolation('EMPLOYEE_UNKNOWN', 'That employee does not exist.');
        $current = DB::table('employments')->where('employee_id', $employeeId)->orderByDesc('effective_from')->first();
        if ($current === null || $employee->status !== 'active') {
            throw new BusinessRuleViolation('EMPLOYEE_NOT_ACTIVE', "Employee {$employee->code} is not active.");
        }
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::branch((string) $employee->entity_id, (string) ($changes['branch_id'] ?? $current->branch_id)));
        if ($effectiveFrom->toDateString() <= (string) $current->effective_from) {
            throw new BusinessRuleViolation('EMPLOYMENT_CHANGE_NOT_LATEST', "A change for {$employee->code} must start after the latest record, which starts on {$current->effective_from}.");
        }
        $terms = ['branch_id' => (string) $current->branch_id, 'department_id' => (string) $current->department_id, 'designation_id' => (string) $current->designation_id,
            'grade_id' => (string) $current->grade_id, 'employment_type' => (string) $current->employment_type, 'basic_minor' => (int) $current->basic_minor];
        $next = array_replace($terms, array_intersect_key($changes, $terms));
        if ($next === $terms) {
            throw new BusinessRuleViolation('EMPLOYMENT_CHANGE_EMPTY', 'Nothing changes: choose the new branch, department, designation, grade or basic salary.');
        }
        $this->assertTerms($next['employment_type'], $next['basic_minor']);

        return DB::transaction(function () use ($employee, $current, $kind, $effectiveFrom, $next, $terms, $changes, $actorUserId): string {
            DB::table('employments')->where('id', $current->id)->update(['effective_to' => $effectiveFrom->toDateString(), 'updated_at' => now()]);
            $id = $this->insertEmployment((string) $employee->id, (string) $employee->entity_id, $next, (string) $current->currency, $effectiveFrom, $kind, $changes['note'] ?? null, $actorUserId);
            $this->audit->record('employee.employment_changed', AuditSubject::of('employee', (string) $employee->id), $terms, $next + ['kind' => $kind, 'effective_from' => $effectiveFrom->toDateString()],
                $changes['note'] ?? null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /** @param array{branch_id: string, department_id: string, designation_id: string, grade_id: string, employment_type: string, basic_minor: int} $terms */
    private function insertEmployment(string $employeeId, string $entityId, array $terms, string $currency, CarbonImmutable $from, string $kind, ?string $note, string $actorUserId): string
    {
        $id = (string) Str::uuid7();
        DB::table('employments')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'employee_id' => $employeeId, 'entity_id' => $entityId, 'branch_id' => $terms['branch_id'],
            'department_id' => $terms['department_id'], 'designation_id' => $terms['designation_id'], 'grade_id' => $terms['grade_id'], 'employment_type' => $terms['employment_type'],
            'basic_minor' => $terms['basic_minor'], 'currency' => $currency, 'effective_from' => $from->toDateString(), 'effective_to' => null, 'change_kind' => $kind, 'note' => $note,
            'created_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function assertTerms(string $type, int $basic): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new BusinessRuleViolation('EMPLOYMENT_TYPE_INVALID', 'Choose permanent, probation, contract or intern.');
        }
        if ($basic <= 0) {
            throw new BusinessRuleViolation('EMPLOYEE_BASIC_INVALID', 'Enter a basic salary above zero.');
        }
    }

    /** @return array<string, string|null> */
    private static function secret(string $field, ?string $value): array
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? ["{$field}_enc" => null, "{$field}_masked" => null] : ["{$field}_enc" => Crypt::encryptString($value), "{$field}_masked" => self::mask($value)];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string|null>
     */
    private static function bank(array $data): array
    {
        $account = trim((string) ($data['account_no'] ?? ''));

        return ['bank_name' => ($data['bank_name'] ?? null) ?: null, 'bank_branch' => ($data['bank_branch'] ?? null) ?: null, 'routing_no' => ($data['routing_no'] ?? null) ?: null,
            'account_no_enc' => $account === '' ? null : Crypt::encryptString($account), 'account_no_masked' => $account === '' ? null : self::mask($account)];
    }

    public static function mask(string $value): string
    {
        $plain = (string) preg_replace('/\s+/', '', $value);

        return str_repeat('*', max(0, strlen($plain) - 4)).substr($plain, -4);
    }
}
