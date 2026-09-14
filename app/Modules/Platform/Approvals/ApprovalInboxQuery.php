<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * Pending approvals a user may decide now (design §2.1 approvals): the user holds the current step's permission, did not request it and has not
 * decided another step of it. ApprovalService::decide enforces the same rules (plus SoD) when the decision is made.
 */
final class ApprovalInboxQuery
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ApprovalHandlerRegistry $handlers,
    ) {}

    /**
     * @return list<array{id: string, object_type: string, object_id: string, step: int, steps_total: int, permission: string, requested_by: string, requested_at: string,
     *     title: string, amount_minor: int|null, currency: string|null, link: string|null}>
     */
    public function decidableBy(string $userId): array
    {
        // Steps come from the approval policy, or from the approval itself when its module set them (D-31).
        $pending = DB::table('approvals as a')->leftJoin('approval_policies as p', 'p.id', '=', 'a.policy_id')->leftJoin('users as u', 'u.id', '=', 'a.requested_by')
            ->where('a.status', ApprovalStatus::Pending->value)->where('a.requested_by', '<>', $userId)
            ->whereNotExists(fn ($q) => $q->from('approval_decisions as d')->whereColumn('d.approval_id', 'a.id')->where('d.decided_by', $userId))
            ->orderBy('a.requested_at')->get(['a.id', 'a.object_type', 'a.object_id', 'a.current_step', DB::raw('coalesce(a.steps, p.steps) as steps'), 'a.requested_at', 'u.name']);

        $rows = [];
        foreach ($pending as $approval) {
            /** @var list<array{permission?: string, role?: string}> $steps */
            $steps = json_decode((string) $approval->steps, true) ?? [];
            $step = $steps[(int) $approval->current_step - 1] ?? [];
            $permission = (string) ($step['permission'] ?? '');
            $role = $step['role'] ?? null;
            // A step naming a role is decided by holders of that role (D-26); otherwise by holders of the permission.
            if ($permission === '' || ($role === null ? ! $this->permissions->has($userId, $permission) : ! ApprovalService::holdsRole($userId, $role))) {
                continue;
            }
            $rows[] = ['id' => (string) $approval->id, 'object_type' => (string) $approval->object_type, 'object_id' => (string) $approval->object_id,
                'step' => (int) $approval->current_step, 'steps_total' => max(count($steps), (int) $approval->current_step), 'permission' => $permission, 'requested_by' => (string) $approval->name, 'requested_at' => (string) $approval->requested_at]
                + $this->describe((string) $approval->object_type, (string) $approval->object_id);
        }

        return $rows;
    }

    /**
     * Gap fix GA-04: what the approver decides on — facts, what the link opens and the journal lines the final approval posts — when the handler
     * can say (PreviewsApprovalSubject); null otherwise.
     *
     * @return array{link_label: string, details: list<array{label: string, value: string, date?: bool}>,
     *     lines: list<array{account: string, name: string, debit: string|null, credit: string|null}>, posts_on_final_step: bool}|null
     */
    public function preview(string $objectType, string $objectId): ?array
    {
        try {
            $handler = $this->handlers->for($objectType);
        } catch (ApprovalException) {
            return null;
        }

        return $handler instanceof PreviewsApprovalSubject ? $handler->preview($objectId) : null;
    }

    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    private function describe(string $objectType, string $objectId): array
    {
        try {
            $handler = $this->handlers->for($objectType);
        } catch (ApprovalException) {
            return ['title' => $objectType, 'amount_minor' => null, 'currency' => null, 'link' => null];
        }

        return $handler instanceof DescribesApprovalSubject ? $handler->describe($objectId) : ['title' => $objectType, 'amount_minor' => null, 'currency' => null, 'link' => null];
    }
}
