<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Insurance\Party\Domain\PartyContact;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyTransactionType;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap fixes W7 (GA-25 remainder): endorsements that change no premium — the insured's name as printed on the policy, the address, the mortgagee (the bank a
 * financed vehicle or property is mortgaged to) and the contact details. Each is an endorsement transaction like a premium change, so it takes the next
 * number `<policy>/E<n>` and prints from the policy's Documents tab, but with no premium change and no accounting event (no journal).
 *
 * The change is made on the policy (`insured_details`), not on the customer: another policy of the same customer keeps its own wording until it is endorsed
 * too (ASSUMPTION A-235). Keys never endorsed still come from the customer record.
 */
final class PolicyDetailsEndorsement
{
    /** kind => the fields it changes */
    public const KINDS = ['name' => ['insured_name'], 'address' => ['address'], 'mortgagee' => ['mortgagee'], 'contact' => ['mobile', 'email']];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /**
     * The details the policy carries now: what endorsements set, else the customer's.
     *
     * @return array{insured_name: string, address: string|null, mortgagee: string|null, mobile: string|null, email: string|null}
     */
    public static function current(Policy $policy): array
    {
        $party = DB::table('parties')->where('id', $policy->policyholder_party_id)->first(['display_name', 'address', 'mobile', 'email']);
        $set = is_array($policy->insured_details) ? $policy->insured_details : [];
        $pick = fn (string $key, mixed $fallback): ?string => array_key_exists($key, $set) ? ($set[$key] === null ? null : (string) $set[$key]) : ($fallback === null ? null : (string) $fallback);

        return ['insured_name' => (string) $pick('insured_name', $party->display_name ?? ''), 'address' => $pick('address', $party->address ?? null),
            'mortgagee' => $pick('mortgagee', null), 'mobile' => $pick('mobile', $party->mobile ?? null), 'email' => $pick('email', $party->email ?? null)];
    }

    /**
     * @param array<string, mixed> $input the kind's fields (KINDS)
     *
     * @throws BusinessRuleViolation REASON_REQUIRED | ENDORSEMENT_KIND_UNKNOWN | ENDORSEMENT_OUTSIDE_COVER | ENDORSEMENT_NO_CHANGE | ENDORSEMENT_DETAIL_REQUIRED | ENDORSEMENT_DETAIL_TOO_LONG
     *     | PARTY_MOBILE_INVALID | PARTY_EMAIL_INVALID | INVALID_POLICY_TRANSITION
     */
    public function endorse(string $policyId, string $kind, CarbonImmutable $effectiveDate, array $input, string $reason, string $actorUserId): PolicyTransaction
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->permissions->authorize($actorUserId, 'policy.endorse', AuthorizationScope::branch($policy->entity_id, $policy->branch_id));
        $fields = self::KINDS[$kind] ?? throw new BusinessRuleViolation('ENDORSEMENT_KIND_UNKNOWN', 'Choose what the endorsement changes: name, address, mortgagee or contact details.');
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'An endorsement needs a reason.');
        }
        $after = self::normalised($kind, $input);

        return DB::transaction(function () use ($policyId, $kind, $fields, $effectiveDate, $after, $reason, $actorUserId): PolicyTransaction {
            $policy = Policy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();
            if (! in_array($policy->status, [PolicyStatus::Issued, PolicyStatus::Active], true)) {
                throw new BusinessRuleViolation('INVALID_POLICY_TRANSITION', "Policy {$policy->number} is {$policy->status->value}; only an issued or active policy is endorsed.");
            }
            if ($effectiveDate->lessThan($policy->inception) || $effectiveDate->greaterThan($policy->expiry)) {
                throw new BusinessRuleViolation('ENDORSEMENT_OUTSIDE_COVER', "An endorsement must take effect between {$policy->inception->format('j M Y')} and {$policy->expiry->format('j M Y')}.");
            }
            $current = self::current($policy);
            $before = array_intersect_key($current, array_flip($fields));
            if ($before == $after) {
                throw new BusinessRuleViolation('ENDORSEMENT_NO_CHANGE', 'The details are the same as the policy has now. Change a detail to endorse.');
            }
            $set = is_array($policy->insured_details) ? $policy->insured_details : [];
            $policy->forceFill(['version' => $policy->version + 1, 'insured_details' => array_replace($set, $after)])->save();
            $transaction = PolicyTransaction::query()->create([
                'policy_id' => $policy->id, 'type' => PolicyTransactionType::Endorsement->value, 'effective_date' => $effectiveDate->toDateString(), 'accounting_date' => $effectiveDate->toDateString(),
                'premium_delta_minor' => 0, 'net_delta_minor' => 0, 'tax_delta_minor' => 0, 'stamp_duty_delta_minor' => 0, 'policy_version' => $policy->version,
                'reason' => trim($reason), 'created_by' => $actorUserId, 'endorsement_kind' => $kind, 'details_change' => ['before' => $before, 'after' => $after],
            ]);
            $this->audit->record('policy.endorsed', AuditSubject::of('policy', $policy->id), $before,
                ['version' => $policy->version, 'endorsement_kind' => $kind, 'number' => $policy->number.'/E'.PolicyLifecycle::endorsementNo($policy)] + $after, trim($reason), 'policy.endorse', Actor::user($actorUserId));

            return $transaction;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private static function normalised(string $kind, array $input): array
    {
        $text = fn (string $key): ?string => is_scalar($input[$key] ?? null) && trim((string) $input[$key]) !== '' ? trim((string) $input[$key]) : null;
        $limit = function (?string $value, int $max, string $what): ?string {
            if ($value !== null && mb_strlen($value) > $max) {
                throw new BusinessRuleViolation('ENDORSEMENT_DETAIL_TOO_LONG', "Keep the {$what} to {$max} characters.");
            }

            return $value;
        };

        return match ($kind) {
            'name' => ['insured_name' => $limit($text('insured_name') ?? throw new BusinessRuleViolation('ENDORSEMENT_DETAIL_REQUIRED', 'Enter the name as it should read on the policy.'), 255, 'name')],
            'address' => ['address' => $limit($text('address') ?? throw new BusinessRuleViolation('ENDORSEMENT_DETAIL_REQUIRED', 'Enter the new address.'), 500, 'address')],
            // An empty mortgagee removes it: the loan was repaid.
            'mortgagee' => ['mortgagee' => $limit($text('mortgagee'), 255, 'mortgagee')],
            default => (function () use ($text): array {
                $email = $text('email');
                if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new BusinessRuleViolation('PARTY_EMAIL_INVALID', 'Enter a whole email address, with an @ and the part after it.');
                }

                return ['mobile' => PartyContact::mobile($text('mobile')), 'email' => $email === null ? null : mb_strtolower($email)];
            })(),
        };
    }
}
