<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Application;

use App\Modules\Accounting\Application\Queries\AccountLineQuery;
use App\Modules\Finance\Bank\Domain\Models\BankAccount;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/** Company bank accounts (design §2.4). Each posts to its own GL account: an active, postable, non-control asset account of the entity. */
final class BankAccountService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly AccountLineQuery $accounts,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation INVALID_GL_ACCOUNT */
    public function create(string $entityId, string $glAccountId, string $bankName, string $accountNoMasked, string $currency, string $actorUserId): BankAccount
    {
        $this->permissions->authorize($actorUserId, 'bank.manage_accounts', AuthorizationScope::entity($entityId));
        $account = $this->accounts->account($glAccountId);
        if ($account === null || $account->entityId !== $entityId || $account->type !== 'asset' || $account->isControl || ! $account->acceptsPostings()
            || ($account->currency !== null && $account->currency !== $currency)) {
            throw new BusinessRuleViolation('INVALID_GL_ACCOUNT', 'A bank account must post to an active, postable, non-control asset account of the same entity and currency.');
        }

        return DB::transaction(function () use ($entityId, $glAccountId, $bankName, $accountNoMasked, $currency, $actorUserId): BankAccount {
            $bankAccount = BankAccount::query()->create(['entity_id' => $entityId, 'gl_account_id' => $glAccountId, 'bank_name' => $bankName,
                'account_no_masked' => $accountNoMasked, 'currency' => $currency, 'status' => 'active']);
            $this->audit->record('bank_account.created', AuditSubject::of('bank_account', $bankAccount->id), null,
                ['gl_account_id' => $glAccountId, 'bank_name' => $bankName, 'account_no_masked' => $accountNoMasked, 'currency' => $currency],
                null, 'bank.manage_accounts', Actor::user($actorUserId));

            return $bankAccount;
        });
    }
}
