<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Http\Controllers;

use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Party\Domain\Models\Party;
use App\Modules\Insurance\Party\Domain\Models\PartyBankAccount;
use App\Modules\Insurance\Party\Domain\Models\PartyRole;
use App\Modules\Insurance\Party\Http\Requests\StoreBankAccountRequest;
use App\Modules\Insurance\Party\Http\Requests\StorePartyRequest;
use App\Modules\Insurance\Party\Http\Requests\UpdatePartyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PartyController
{
    public function __construct(private readonly PartyService $parties) {}

    public function index(Request $request): JsonResponse
    {
        $page = Party::query()->with(['roles', 'bankAccounts'])->orderBy('display_name')->paginate(50);

        return response()->json(['data' => array_map(fn (Party $party): array => self::present($party), $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function store(StorePartyRequest $request): JsonResponse
    {
        /** @var array{kind: string, display_name: string, tax_id?: string|null, roles: list<string>} $data */
        $data = $request->validated();
        $party = $this->parties->create(PartyKind::from($data['kind']), $data['display_name'], $data['tax_id'] ?? null,
            array_map(fn (string $role): PartyRoleType => PartyRoleType::from($role), $data['roles']), self::actor($request));

        return response()->json(['data' => self::present($party->fresh(['roles', 'bankAccounts']) ?? $party)], 201);
    }

    public function show(string $party): JsonResponse
    {
        return response()->json(['data' => self::present(Party::query()->with(['roles', 'bankAccounts'])->findOrFail($party))]);
    }

    public function update(UpdatePartyRequest $request, string $party): JsonResponse
    {
        /** @var array{display_name?: string, tax_id?: string|null, status?: string, roles?: list<string>} $data */
        $data = $request->validated();
        $roles = isset($data['roles']) ? array_map(fn (string $role): PartyRoleType => PartyRoleType::from($role), $data['roles']) : null;
        unset($data['roles']);
        $updated = $this->parties->update($party, $data, $roles, self::actor($request));

        return response()->json(['data' => self::present($updated->fresh(['roles', 'bankAccounts']) ?? $updated)]);
    }

    public function storeBankAccount(StoreBankAccountRequest $request, string $party): JsonResponse
    {
        /** @var array{bank_name: string, account_no: string, is_default?: bool} $data */
        $data = $request->validated();
        $account = $this->parties->addBankAccount($party, $data['bank_name'], $data['account_no'], (bool) ($data['is_default'] ?? false), self::actor($request));

        return response()->json(['data' => self::presentBankAccount($account)], 201);
    }

    /** @return array<string, mixed> */
    public static function present(Party $party): array
    {
        return [
            'id' => $party->id, 'kind' => $party->kind->value, 'display_name' => $party->display_name, 'tax_id' => $party->tax_id, 'status' => $party->status,
            'roles' => $party->roles->map(fn (PartyRole $role): string => $role->role->value)->values()->all(),
            'bank_accounts' => $party->bankAccounts->map(fn (PartyBankAccount $account): array => self::presentBankAccount($account))->values()->all(),
        ];
    }

    /** @return array{id: string, bank_name: string, account_no_masked: string, is_default: bool} */
    private static function presentBankAccount(PartyBankAccount $account): array
    {
        return ['id' => $account->id, 'bank_name' => $account->bank_name, 'account_no_masked' => $account->account_no_masked, 'is_default' => $account->is_default];
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
