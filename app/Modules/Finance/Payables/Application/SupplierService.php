<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Application;

use App\Modules\Finance\Payables\Domain\Models\Supplier;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Suppliers (addendum v2 §B.4, §B.2.3): a party with the vendor role — an existing one (a garage first added as a claim payee) or a new organisation —
 * with its code, withholding category, payment terms, tax numbers, default expense account and paying bank account (`ap.manage_suppliers`).
 *
 * DECISION D-101: the paying bank account (bank, branch, routing number, account name and number) is kept on the supplier, not in party_bank_accounts,
 * because a BEFTN file needs the routing number and account name that party bank accounts do not hold. The number is encrypted and masked on screens.
 * LATER (§B.2.3): a change of the paying account takes effect only after a second person approves it.
 */
final class SupplierService
{
    public const PERMISSION = 'ap.manage_suppliers';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /**
     * @param array{code: string, name?: string|null, party_id?: string|null, category: string, payment_terms_days?: int|null, tin?: string|null, bin?: string|null,
     *     vat_registered?: bool, default_account_id?: string|null, bank_name?: string|null, bank_branch?: string|null, routing_no?: string|null,
     *     account_name?: string|null, account_no?: string|null, mobile?: string|null, email?: string|null, address?: string|null} $data
     *
     * @throws BusinessRuleViolation SUPPLIER_CODE_TAKEN | SUPPLIER_CATEGORY_UNKNOWN | SUPPLIER_NAME_REQUIRED | SUPPLIER_EXISTS | INVALID_ACCOUNT
     */
    public function create(string $entityId, array $data, string $actorUserId): Supplier
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::entity($entityId));
        $this->assertCategory($data['category']);

        return DB::transaction(function () use ($entityId, $data, $actorUserId): Supplier {
            $code = strtoupper(trim($data['code']));
            if (Supplier::query()->where('code', $code)->exists()) {
                throw new BusinessRuleViolation('SUPPLIER_CODE_TAKEN', "Supplier code {$code} is already used.");
            }
            $partyId = $data['party_id'] ?? null;
            if ($partyId === null || $partyId === '') {
                $partyId = $this->createVendorParty($data, $actorUserId);
            } else {
                if (! DB::table('parties')->where('id', $partyId)->exists()) {
                    throw new BusinessRuleViolation('SUPPLIER_NAME_REQUIRED', 'Choose an existing party or give the supplier a name.');
                }
                if (Supplier::query()->where('party_id', $partyId)->exists()) {
                    throw new BusinessRuleViolation('SUPPLIER_EXISTS', 'This party is already a supplier.');
                }
                DB::table('party_roles')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'party_id' => $partyId, 'role' => 'vendor',
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->assertExpenseAccount($entityId, $data['default_account_id'] ?? null);
            $supplier = Supplier::query()->create([
                'entity_id' => $entityId, 'party_id' => $partyId, 'code' => $code, 'category' => $data['category'], 'status' => 'active',
                'payment_terms_days' => $data['payment_terms_days'] ?? (int) config('erp.payables.default_payment_terms_days', 30),
                'default_account_id' => ($data['default_account_id'] ?? '') === '' ? null : $data['default_account_id'],
                'tin' => self::blankToNull($data['tin'] ?? null), 'bin' => self::blankToNull($data['bin'] ?? null), 'vat_registered' => (bool) ($data['vat_registered'] ?? false),
                ...$this->bankColumns($data), 'created_by' => $actorUserId,
            ]);
            $this->audit->record('supplier.created', AuditSubject::of('supplier', $supplier->id), null, $this->snapshot($supplier), null, self::PERMISSION, Actor::user($actorUserId));

            return $supplier;
        });
    }

    /**
     * @param array{category?: string, status?: string, payment_terms_days?: int, tin?: string|null, bin?: string|null, vat_registered?: bool, default_account_id?: string|null,
     *     bank_name?: string|null, bank_branch?: string|null, routing_no?: string|null, account_name?: string|null, account_no?: string|null} $data
     *
     * @throws BusinessRuleViolation SUPPLIER_CATEGORY_UNKNOWN | SUPPLIER_STATUS_INVALID | INVALID_ACCOUNT
     */
    public function update(string $supplierId, array $data, string $actorUserId): Supplier
    {
        $supplier = Supplier::query()->findOrFail($supplierId);
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::entity($supplier->entity_id));
        if (isset($data['category'])) {
            $this->assertCategory($data['category']);
        }
        if (isset($data['status']) && ! in_array($data['status'], ['active', 'on_hold', 'blocked'], true)) {
            throw new BusinessRuleViolation('SUPPLIER_STATUS_INVALID', 'A supplier is active, on hold or blocked.');
        }
        $this->assertExpenseAccount($supplier->entity_id, $data['default_account_id'] ?? null);

        return DB::transaction(function () use ($supplier, $data, $actorUserId): Supplier {
            $before = $this->snapshot($supplier);
            $columns = array_intersect_key($data, array_flip(['category', 'status', 'payment_terms_days', 'tin', 'bin', 'vat_registered', 'default_account_id']));
            if (array_key_exists('default_account_id', $columns) && $columns['default_account_id'] === '') {
                $columns['default_account_id'] = null;
            }
            if (isset($data['account_no']) && $data['account_no'] !== '') {
                $columns = [...$columns, ...$this->bankColumns($data)];
            }
            $supplier->forceFill([...$columns, 'updated_by' => $actorUserId])->save();
            $this->audit->record('supplier.updated', AuditSubject::of('supplier', $supplier->id), $before, $this->snapshot($supplier), null, self::PERMISSION, Actor::user($actorUserId));

            return $supplier;
        });
    }

    /** @return array{vat_bp: int, vds_bp: int, tds_bp: int, label: string} the withholding rates of a category (ASSUMPTION A-243, placeholders) */
    public static function rates(string $category): array
    {
        /** @var array{label: string, vat_bp: int, vds_bp: int, tds_bp: int}|null $rates */
        $rates = config("erp.payables.categories.{$category}");

        return $rates ?? throw new BusinessRuleViolation('SUPPLIER_CATEGORY_UNKNOWN', "Unknown supplier category {$category}.");
    }

    /** @param array{name?: string|null, tin?: string|null, mobile?: string|null, email?: string|null, address?: string|null} $data */
    private function createVendorParty(array $data, string $actorUserId): string
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new BusinessRuleViolation('SUPPLIER_NAME_REQUIRED', 'Choose an existing party or give the supplier a name.');
        }
        $partyId = (string) Str::uuid7();
        DB::table('parties')->insert(['id' => $partyId, 'tenant_id' => TenantContext::id(), 'kind' => 'organization', 'display_name' => $name, 'tax_id' => self::blankToNull($data['tin'] ?? null),
            'status' => 'active', 'mobile' => self::blankToNull($data['mobile'] ?? null), 'email' => self::blankToNull($data['email'] ?? null), 'address' => self::blankToNull($data['address'] ?? null),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('party_roles')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'party_id' => $partyId, 'role' => 'vendor', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('party.created', AuditSubject::of('party', $partyId), null, ['display_name' => $name, 'roles' => ['vendor']], null, self::PERMISSION, Actor::user($actorUserId));

        return $partyId;
    }

    /**
     * @param array{bank_name?: string|null, bank_branch?: string|null, routing_no?: string|null, account_name?: string|null, account_no?: string|null} $data
     * @return array<string, string|null>
     */
    private function bankColumns(array $data): array
    {
        $number = self::blankToNull($data['account_no'] ?? null);

        return ['bank_name' => self::blankToNull($data['bank_name'] ?? null), 'bank_branch' => self::blankToNull($data['bank_branch'] ?? null),
            'routing_no' => self::blankToNull($data['routing_no'] ?? null), 'account_name' => self::blankToNull($data['account_name'] ?? null),
            'account_no_enc' => $number, 'account_no_masked' => $number === null ? null : Supplier::mask($number)];
    }

    private function assertCategory(string $category): void
    {
        self::rates($category);
    }

    private function assertExpenseAccount(string $entityId, ?string $accountId): void
    {
        if ($accountId === null || $accountId === '') {
            return;
        }
        if (! DB::table('accounts')->where('id', $accountId)->where('entity_id', $entityId)->where('is_postable', true)->where('status', 'active')->where('is_control', false)->exists()) {
            throw new BusinessRuleViolation('INVALID_ACCOUNT', 'Choose an active account of this entity that takes postings and is not a control account.');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Supplier $supplier): array
    {
        return ['code' => $supplier->code, 'category' => $supplier->category, 'status' => $supplier->status, 'payment_terms_days' => $supplier->payment_terms_days,
            'tin' => $supplier->tin, 'bin' => $supplier->bin, 'bank_name' => $supplier->bank_name, 'routing_no' => $supplier->routing_no, 'account_no_masked' => $supplier->account_no_masked];
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
