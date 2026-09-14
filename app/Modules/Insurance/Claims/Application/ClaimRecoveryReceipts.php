<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\Insurance\Claims\Domain\Models\Claim;
use App\Modules\Insurance\Claims\Domain\Models\ClaimRecovery;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-21 (DECISION D-88): a claim recovery is money coming in, so it is receipted like any inflow by whoever takes money in — `receipt.create` or
 * `receipt.allocate` on the claim's branch (the collections duties: branch staff, the accountant, the finance manager), never by the claims officer or
 * manager who reserved and settled the claim — with a recovery receipt number (`RCV`, branch-coded), the payer and the bank account it was paid into,
 * both required. The accounting is design §4.8 as before: CLAIM_RECOVERED,
 * DR bank_main (that bank account) / CR claims_recovery_income, on a claim that has been paid (§5.5 "recovery* any time after paid"; follow-up H3: also a paid claim reopened and reserved again), so the claims subledger,
 * reports and reconciliation are unchanged. Separate from ClaimService, which keeps its lifecycle (register → close, reopen) and its direct `recover`
 * used by the reference model tests; the screens and the API record recoveries here. ASSUMPTION A-220.
 */
final class ClaimRecoveryReceipts
{
    /** ASSUMPTION A-220: the collections duties receipt recoveries. */
    public const PERMISSIONS = ['receipt.create', 'receipt.allocate'];

    private const TYPES = ['salvage', 'subrogation', 'third_party'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly DocumentNumberer $numbers,
        private readonly ClaimAccountingEvents $accounting,
        private readonly BankAccountQuery $bankAccounts,
        private readonly Audit $audit,
        private readonly ClaimService $claims,
    ) {}

    /** @throws BusinessRuleViolation INVALID_AMOUNT | INVALID_RECOVERY_TYPE | INVALID_BANK_ACCOUNT | UNKNOWN_PAYER | CLAIM_NOT_PAID */
    public function receive(string $claimId, string $type, int $amountMinor, string $bankAccountId, string $payerPartyId, ?string $reference, string $actorUserId, CarbonImmutable $receivedOn): ClaimRecovery
    {
        $claim = Claim::query()->findOrFail($claimId);
        $this->permissions->authorizeAny($actorUserId, self::PERMISSIONS, AuthorizationScope::branch($claim->entity_id, $claim->branch_id));
        if ($amountMinor <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'A recovery must be a positive amount.');
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new BusinessRuleViolation('INVALID_RECOVERY_TYPE', 'A recovery is salvage, subrogation or third_party.');
        }
        $this->bankAccounts->glAccountFor($bankAccountId, $claim->entity_id, $claim->currency);
        if (! DB::table('parties')->where('id', $payerPartyId)->exists()) {
            throw new BusinessRuleViolation('UNKNOWN_PAYER', "Payer {$payerPartyId} is not a known party.");
        }
        if (! $this->claims->recoverable($claim)) {
            throw new BusinessRuleViolation('CLAIM_NOT_PAID', "Claim {$claim->number} has not been paid; recoveries come after payment.");
        }
        $number = $this->numbers->reserve(new DocumentNumberScope($claim->entity_id, $claim->branch_id, 'claim_recovery', 'RCV', $receivedOn), $actorUserId);

        return DB::transaction(function () use ($claimId, $type, $amountMinor, $bankAccountId, $payerPartyId, $reference, $actorUserId, $receivedOn, $number): ClaimRecovery {
            $claim = Claim::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            if (! $this->claims->recoverable($claim)) {
                throw new BusinessRuleViolation('CLAIM_NOT_PAID', "Claim {$claim->number} has not been paid; recoveries come after payment.");
            }
            $recovery = ClaimRecovery::query()->create(['claim_id' => $claim->id, 'type' => $type, 'amount_minor' => $amountMinor, 'currency' => $claim->currency,
                'received_on' => $receivedOn->toDateString(), 'bank_account_id' => $bankAccountId, 'reference' => $reference === null || trim($reference) === '' ? null : trim($reference),
                'recorded_by' => $actorUserId, 'number' => $number->number, 'payer_party_id' => $payerPartyId]);
            $this->numbers->markUsed($number->id, 'claim_recovery', $recovery->id);
            $this->accounting->recovered($claim, $recovery);
            $this->audit->record('claim.recovery_receipted', AuditSubject::of('claim', $claim->id), null,
                ['recovery_id' => $recovery->id, 'number' => $recovery->number, 'type' => $type, 'amount_minor' => $amountMinor, 'bank_account_id' => $bankAccountId, 'payer_party_id' => $payerPartyId],
                null, 'receipt.create', Actor::user($actorUserId));

            return $recovery;
        });
    }
}
