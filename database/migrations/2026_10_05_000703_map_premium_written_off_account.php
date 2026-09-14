<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gap fixes W7 (GA-24): the account role `premium_written_off` that PREMIUM_WRITTEN_OFF debits. Every tenant book that maps premium receivable gets an expense
 * account "Premium Written Off" (code 5450, or the next free code after it; an existing expense account of that name is reused) mapped to the role from
 * 2026-01-01, as the shipped chart template and the demo chart now have (A-234). A book that maps the role already is left as it is; rerunning changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('account_roles')->insertOrIgnore(['code' => 'premium_written_off', 'description' => 'Unpaid premium written off as too small to collect']);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            $books = DB::table('account_role_mappings')->where('role_code', 'premium_receivable')->select(['entity_id', 'book_id'])->distinct()->get();
            foreach ($books as $book) {
                if (DB::table('account_role_mappings')->where('entity_id', $book->entity_id)->where('book_id', $book->book_id)->where('role_code', 'premium_written_off')->exists()) {
                    continue;
                }
                $accountId = DB::table('accounts')->where('entity_id', $book->entity_id)->where('name', 'Premium Written Off')->where('type', 'expense')->value('id');
                if ($accountId === null) {
                    $code = 5450;
                    while (DB::table('accounts')->where('entity_id', $book->entity_id)->where('code', (string) $code)->exists()) {
                        $code++;
                    }
                    $accountId = (string) Str::uuid7();
                    DB::table('accounts')->insert(['id' => $accountId, 'tenant_id' => $tenantId, 'entity_id' => $book->entity_id, 'code' => (string) $code, 'name' => 'Premium Written Off',
                        'type' => 'expense', 'normal_side' => 'debit', 'is_postable' => true, 'is_control' => false, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('account_role_mappings')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entity_id' => $book->entity_id, 'book_id' => $book->book_id,
                    'role_code' => 'premium_written_off', 'account_id' => (string) $accountId, 'effective_from' => '2026-01-01', 'effective_to' => null]);
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(function (): void {
            DB::table('account_role_mappings')->where('role_code', 'premium_written_off')->delete();
        });
        DB::table('account_roles')->where('code', 'premium_written_off')->delete();
    }
};
