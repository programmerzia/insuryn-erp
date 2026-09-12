<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Close;

use Illuminate\Support\Facades\DB;

/** Read model of one close run and its tasks in order (design §5.7). */
final class CloseRunQuery
{
    /**
     * @return array{id: string, period_id: string, status: string, started_at: string, completed_at: string|null,
     *     tasks: list<array{id: string, code: string, order_no: int, depends_on: list<string>, owner_role: string, status: string, result: mixed, done_by: string|null, done_at: string|null}>}|null
     */
    public function find(string $runId): ?array
    {
        $run = DB::table('period_close_runs')->where('id', $runId)->first(['id', 'period_id', 'status', 'started_at', 'completed_at']);
        if ($run === null) {
            return null;
        }
        $tasks = [];
        foreach (DB::table('period_close_tasks')->where('close_run_id', $runId)->orderBy('order_no')->get() as $task) {
            /** @var list<string> $dependsOn */
            $dependsOn = json_decode((string) $task->depends_on, true) ?? [];
            $tasks[] = ['id' => (string) $task->id, 'code' => (string) $task->code, 'order_no' => (int) $task->order_no, 'depends_on' => $dependsOn,
                'owner_role' => (string) $task->owner_role, 'status' => (string) $task->status,
                'result' => $task->result === null ? null : json_decode((string) $task->result, true),
                'done_by' => $task->done_by === null ? null : (string) $task->done_by, 'done_at' => $task->done_at === null ? null : (string) $task->done_at];
        }

        return ['id' => (string) $run->id, 'period_id' => (string) $run->period_id, 'status' => (string) $run->status, 'started_at' => (string) $run->started_at,
            'completed_at' => $run->completed_at === null ? null : (string) $run->completed_at, 'tasks' => $tasks];
    }
}
