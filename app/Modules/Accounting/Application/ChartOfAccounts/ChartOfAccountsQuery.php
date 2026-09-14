<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ChartOfAccounts;

use App\Modules\Accounting\Domain\Enums\JournalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Accounting → Chart of accounts (UX U2): the entity's accounts in tree order (each parent followed by its children, by code), with the account roles
 * mapped to each today, whether any journal line names it, and its ledger balance in the book (base currency, positive on the account's normal side).
 */
final class ChartOfAccountsQuery
{
    /**
     * @return list<array{id: string, code: string, name: string, type: string, normal_side: string, parent_id: string|null, parent_code: string|null, depth: int,
     *     is_postable: bool, is_control: bool, control_subledger: string|null, currency: string|null, status: string, roles: list<array{code: string, description: string}>,
     *     has_lines: bool, balance_minor: int}>
     */
    public function tree(string $entityId, string $bookId, CarbonImmutable $today): array
    {
        $accounts = DB::table('accounts')->where('entity_id', $entityId)->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'normal_side', 'parent_id', 'is_postable', 'is_control', 'control_subledger', 'currency', 'status']);
        $day = $today->toDateString();
        $roles = DB::table('account_role_mappings as m')->leftJoin('account_roles as r', 'r.code', '=', 'm.role_code')
            ->where('m.entity_id', $entityId)->where('m.book_id', $bookId)->where('m.effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('m.effective_to')->orWhere('m.effective_to', '>', $day))->orderBy('m.role_code')
            ->get(['m.account_id', 'm.role_code', 'r.description'])->groupBy('account_id');
        $balances = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.entity_id', $entityId)->where('j.book_id', $bookId)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])->where('j.posting_date', '<=', $day)->groupBy('l.account_id')
            ->selectRaw("l.account_id, sum(case when l.side = 'debit' then l.base_amount_minor else -l.base_amount_minor end) as balance")->pluck('balance', 'account_id');
        $withLines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.entity_id', $entityId)->distinct()->pluck('l.account_id')
            ->mapWithKeys(fn (mixed $id): array => [(string) $id => true])->all();

        $byId = [];
        $children = [];
        foreach ($accounts as $a) {
            $byId[(string) $a->id] = $a;
        }
        foreach ($accounts as $a) {
            $parent = $a->parent_id !== null && isset($byId[(string) $a->parent_id]) ? (string) $a->parent_id : '';
            $children[$parent][] = (string) $a->id;
        }

        $rows = [];
        $visit = function (string $parent, int $depth) use (&$visit, &$rows, $children, $byId, $roles, $balances, $withLines): void {
            foreach ($children[$parent] ?? [] as $id) {
                if (isset($rows[$id])) {
                    continue;
                }
                $a = $byId[$id];
                $signed = (int) ($balances[$id] ?? 0);
                $parentId = $a->parent_id !== null && isset($byId[(string) $a->parent_id]) ? (string) $a->parent_id : null;
                $rows[$id] = ['id' => $id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'type' => (string) $a->type, 'normal_side' => (string) $a->normal_side,
                    'parent_id' => $parentId, 'parent_code' => $parentId === null ? null : (string) $byId[$parentId]->code, 'depth' => $depth,
                    'is_postable' => (bool) $a->is_postable, 'is_control' => (bool) $a->is_control,
                    'control_subledger' => $a->control_subledger === null ? null : (string) $a->control_subledger, 'currency' => $a->currency === null ? null : (string) $a->currency,
                    'status' => (string) $a->status,
                    'roles' => array_values($roles->get($id, collect())->map(fn (object $r): array => ['code' => (string) $r->role_code,
                        'description' => (string) preg_replace('/\s*\([^)]*\)\s*$/', '', (string) ($r->description ?? $r->role_code))])->all()),
                    'has_lines' => isset($withLines[$id]), 'balance_minor' => $a->normal_side === 'credit' ? -$signed : $signed];
                $visit($id, $depth + 1);
            }
        };
        $visit('', 0);
        // An account in a parent loop (only reachable through bad data) is still listed, at the top level.
        foreach (array_keys($byId) as $id) {
            if (! isset($rows[$id])) {
                $children['loop:'.$id] = [$id];
                $visit('loop:'.$id, 0);
            }
        }

        return array_values($rows);
    }
}
