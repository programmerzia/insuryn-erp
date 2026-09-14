<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Application;

use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Party\Domain\Models\Party;
use App\Modules\Insurance\Party\Domain\Models\PartyBankAccount;
use App\Modules\Insurance\Party\Domain\Models\PartyRole;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/** Design §2.4 / spec §3 party model: create and edit parties, their roles and bank accounts (`party.manage`). */
final class PartyService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** Roles a payee created in the course of paying someone may take (flow fix X8). */
    public const PAYEE_ROLES = [PartyRoleType::Vendor, PartyRoleType::Beneficiary];

    /** @param list<PartyRoleType> $roles */
    public function create(PartyKind $kind, string $displayName, ?string $taxId, array $roles, string $actorUserId): Party
    {
        $this->permissions->authorize($actorUserId, 'party.manage');

        return $this->insert($kind, $displayName, $taxId, $roles, $actorUserId, 'party.manage');
    }

    /**
     * Flow fix X8: a payee — a garage, a surveyor, a beneficiary — created while approving a payment to them. The duty being done authorizes it in
     * its own scope (claim.approve on the claim's branch) instead of party.manage, and is the permission on the audit record. Only payee roles.
     *
     * @throws BusinessRuleViolation INVALID_PAYEE_ROLE
     */
    public function createPayee(PartyKind $kind, string $displayName, ?string $taxId, PartyRoleType $role, string $actorUserId, string $duty, AuthorizationScope $scope): Party
    {
        $this->permissions->authorize($actorUserId, $duty, $scope);
        if (! in_array($role, self::PAYEE_ROLES, true)) {
            throw new BusinessRuleViolation('INVALID_PAYEE_ROLE', "A payee is a vendor or a beneficiary, not a {$role->value}.");
        }

        return $this->insert($kind, $displayName, $taxId, [$role], $actorUserId, $duty);
    }

    /** @param list<PartyRoleType> $roles */
    private function insert(PartyKind $kind, string $displayName, ?string $taxId, array $roles, string $actorUserId, string $permission): Party
    {
        return DB::transaction(function () use ($kind, $displayName, $taxId, $roles, $actorUserId, $permission): Party {
            $party = Party::query()->create(['kind' => $kind->value, 'display_name' => $displayName, 'tax_id' => $taxId, 'status' => 'active']);
            $this->syncRoles($party, $roles);
            $this->audit->record('party.created', AuditSubject::of('party', $party->id), null, $this->snapshot($party), null, $permission, Actor::user($actorUserId));

            return $party;
        });
    }

    /**
     * @param array{display_name?: string, tax_id?: string|null, status?: string} $attributes
     * @param list<PartyRoleType>|null $roles null keeps the current roles
     */
    public function update(string $partyId, array $attributes, ?array $roles, string $actorUserId): Party
    {
        $this->permissions->authorize($actorUserId, 'party.manage');

        return DB::transaction(function () use ($partyId, $attributes, $roles, $actorUserId): Party {
            $party = Party::query()->whereKey($partyId)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($party);
            $party->fill($attributes)->save();
            if ($roles !== null) {
                $this->syncRoles($party, $roles);
            }
            $this->audit->record('party.updated', AuditSubject::of('party', $party->id), $before, $this->snapshot($party), null, 'party.manage', Actor::user($actorUserId));

            return $party;
        });
    }

    /** Adds a role if the party does not hold it yet (e.g. becoming an agent). */
    public function ensureRole(Party $party, PartyRoleType $role): void
    {
        PartyRole::query()->firstOrCreate(['party_id' => $party->id, 'role' => $role->value], ['status' => 'active']);
    }

    public function addBankAccount(string $partyId, string $bankName, string $accountNumber, bool $isDefault, string $actorUserId): PartyBankAccount
    {
        $this->permissions->authorize($actorUserId, 'party.manage');

        return DB::transaction(function () use ($partyId, $bankName, $accountNumber, $isDefault, $actorUserId): PartyBankAccount {
            $party = Party::query()->whereKey($partyId)->lockForUpdate()->firstOrFail();
            $isDefault = $isDefault || ! $party->bankAccounts()->exists();
            if ($isDefault) {
                PartyBankAccount::query()->where('party_id', $party->id)->update(['is_default' => false]);
            }
            $account = PartyBankAccount::query()->create([
                'party_id' => $party->id, 'bank_name' => $bankName, 'account_no_enc' => $accountNumber,
                'account_no_masked' => PartyBankAccount::mask($accountNumber), 'is_default' => $isDefault,
            ]);
            $this->audit->record('party.bank_account_added', AuditSubject::of('party', $party->id), null,
                ['bank_account_id' => $account->id, 'bank_name' => $bankName, 'account_no_masked' => $account->account_no_masked, 'is_default' => $isDefault],
                null, 'party.manage', Actor::user($actorUserId));

            return $account;
        });
    }

    /** @param list<PartyRoleType> $roles */
    private function syncRoles(Party $party, array $roles): void
    {
        $codes = array_values(array_unique(array_map(fn (PartyRoleType $role): string => $role->value, $roles)));
        PartyRole::query()->where('party_id', $party->id)->whereNotIn('role', $codes)->delete();
        foreach ($codes as $code) {
            PartyRole::query()->firstOrCreate(['party_id' => $party->id, 'role' => $code], ['status' => 'active']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Party $party): array
    {
        return ['display_name' => $party->display_name, 'kind' => $party->kind->value, 'tax_id' => $party->tax_id, 'status' => $party->status,
            'roles' => PartyRole::query()->where('party_id', $party->id)->orderBy('role')->pluck('role')->map(fn (PartyRoleType $r): string => $r->value)->all()];
    }
}
