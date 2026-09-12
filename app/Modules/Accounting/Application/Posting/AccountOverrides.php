<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Exceptions\PostingFailedException;

/**
 * Design §4.2 "bank_main (bank_accounts.gl_account_id overrides role)": an event may name the account for a role in
 * payload.account_overrides {role: account id}. Accepted only for roles in config erp.posting.overridable_roles and for an
 * active, postable account of the event's entity, so a business module can pick which bank account, never redirect a
 * control account.
 */
final class AccountOverrides
{
    /**
     * @param array<string, string> $accountsByRole
     * @param array<string, mixed> $payload
     * @return array<string, string>
     *
     * @throws PostingFailedException INVALID_ACCOUNT_OVERRIDE
     */
    public function apply(string $entityId, array $accountsByRole, array $payload): array
    {
        $overrides = $payload['account_overrides'] ?? null;
        if ($overrides === null) {
            return $accountsByRole;
        }
        if (! is_array($overrides)) {
            throw new PostingFailedException('INVALID_ACCOUNT_OVERRIDE', 'payload.account_overrides must map roles to account ids');
        }
        /** @var list<string> $overridable */
        $overridable = config('erp.posting.overridable_roles', []);
        foreach ($overrides as $role => $accountId) {
            if (! is_string($role) || ! in_array($role, $overridable, true)) {
                throw new PostingFailedException('INVALID_ACCOUNT_OVERRIDE', 'Account role '.var_export($role, true).' cannot be overridden');
            }
            if (! is_string($accountId) || ! $this->isPostableAccountOf($entityId, $accountId)) {
                throw new PostingFailedException('INVALID_ACCOUNT_OVERRIDE', "Override for role '{$role}' is not an active postable account of the entity");
            }
            $accountsByRole[$role] = $accountId;
        }

        return $accountsByRole;
    }

    private function isPostableAccountOf(string $entityId, string $accountId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $accountId) === 1
            && Account::query()->whereKey($accountId)->where('entity_id', $entityId)->where('is_postable', true)->where('status', 'active')->exists();
    }
}
