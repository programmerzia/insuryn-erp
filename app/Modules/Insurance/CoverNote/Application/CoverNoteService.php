<?php

declare(strict_types=1);

namespace App\Modules\Insurance\CoverNote\Application;

use App\Modules\Insurance\CoverNote\Domain\Enums\CoverNoteStatus;
use App\Modules\Insurance\CoverNote\Domain\Models\CoverNote;
use App\Modules\Insurance\Product\Domain\Enums\PremiumRecognition;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Insurance\Underwriting\Domain\Enums\ProposalStatus;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
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
use Illuminate\Support\Str;

/**
 * Phase 3 design §2 step 3 "Cover note (optional, non-life practice): temporary evidence of cover pending premium/docs; has its own number; issuing it does not
 * post accounting" (slice R6).
 *
 * - `issue`: only for an approved proposal; numbered `CVN-<branch>-<fy>-<seq>`; valid from today or later for at most erp.cover_notes.max_days days of its class,
 *   both ends included (OPEN 2, A-93); one active cover note per proposal.
 * - Accounting (DECISION D-33): none. A product version with `recognise_at = cover_note` (OPEN 3) would recognise premium here, which needs a posting path that
 *   does not exist and would conflict with the policy's frozen issue event; such products are refused (`RECOGNITION_AT_COVER_NOTE_NOT_SUPPORTED`) — a gap.
 * - `cancel` (reason), `expireDue` (nightly job), and for R7 `supersede(coverNote, policy)` / `supersedeForProposal(proposal, policy)` inside the policy issue
 *   transaction.
 */
final class CoverNoteService
{
    public const ISSUE = 'cover_note.issue';
    public const CANCEL = 'cover_note.cancel';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly DocumentNumberer $numbers,
        private readonly Audit $audit,
    ) {}

    /** ASSUMPTION: A-93 — the longest cover note for a class, in days including both ends (default 30, OPEN 2). */
    public static function maxDays(string $classCode): int
    {
        /** @var array<string, int> $days */
        $days = (array) config('erp.cover_notes.max_days', []);

        return max(1, (int) ($days[$classCode] ?? $days['default'] ?? 30));
    }

    /**
     * @throws BusinessRuleViolation PROPOSAL_NOT_APPROVED, RECOGNITION_AT_COVER_NOTE_NOT_SUPPORTED, COVER_NOTE_BACKDATED, COVER_NOTE_DATES_INVALID, COVER_NOTE_TOO_LONG, COVER_NOTE_ALREADY_ACTIVE
     */
    public function issue(string $proposalId, CarbonImmutable $validFrom, CarbonImmutable $validTo, string $actorUserId): CoverNote
    {
        $proposal = Proposal::query()->findOrFail($proposalId);
        $this->permissions->authorize($actorUserId, self::ISSUE, AuthorizationScope::branch($proposal->entity_id, $proposal->branch_id));
        $today = CarbonImmutable::today();
        $this->assertIssuable($proposal, $validFrom, $validTo, $today);
        $number = $this->numbers->reserve(new DocumentNumberScope($proposal->entity_id, $proposal->branch_id, 'cover_note', 'CVN', $today), $actorUserId);

        return DB::transaction(function () use ($proposalId, $validFrom, $validTo, $actorUserId, $today, $number): CoverNote {
            $proposal = Proposal::query()->whereKey($proposalId)->lockForUpdate()->firstOrFail();
            $this->assertIssuable($proposal, $validFrom, $validTo, $today);
            $note = new CoverNote(['id' => (string) Str::uuid7()]);
            $note->forceFill(['entity_id' => $proposal->entity_id, 'branch_id' => $proposal->branch_id, 'proposal_id' => $proposal->id, 'number' => $number->number,
                'class_code' => $proposal->class_code, 'valid_from' => $validFrom->toDateString(), 'valid_to' => $validTo->toDateString(), 'status' => CoverNoteStatus::Active->value,
                'issued_by' => $actorUserId, 'issued_at' => CarbonImmutable::now()])->save();
            $this->numbers->markUsed($number->id, 'cover_note', $note->id);
            $this->audit->record('cover_note.issued', AuditSubject::of('cover_note', $note->id), null, ['number' => $note->number, 'proposal' => $proposal->number,
                'valid_from' => $validFrom->toDateString(), 'valid_to' => $validTo->toDateString()], null, self::ISSUE, Actor::user($actorUserId));

            return $note;
        });
    }

    /** @throws BusinessRuleViolation REASON_REQUIRED, COVER_NOTE_NOT_ACTIVE */
    public function cancel(string $coverNoteId, string $reason, string $actorUserId): CoverNote
    {
        $note = CoverNote::query()->findOrFail($coverNoteId);
        $this->permissions->authorize($actorUserId, self::CANCEL, AuthorizationScope::branch($note->entity_id, $note->branch_id));
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'Cancelling a cover note needs a reason.');
        }

        return DB::transaction(function () use ($coverNoteId, $reason, $actorUserId): CoverNote {
            $note = $this->lock($coverNoteId, [CoverNoteStatus::Active]);
            $note->forceFill(['status' => CoverNoteStatus::Cancelled->value, 'cancelled_by' => $actorUserId, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => trim($reason)])->save();
            $this->audit->record('cover_note.cancelled', AuditSubject::of('cover_note', $note->id), ['status' => 'active'], ['status' => 'cancelled'], trim($reason), self::CANCEL, Actor::user($actorUserId));

            return $note;
        });
    }

    /** Active cover notes whose last day is before $today expire (nightly job). Returns how many. */
    public function expireDue(CarbonImmutable $today): int
    {
        $expired = 0;
        foreach (CoverNote::query()->where('status', CoverNoteStatus::Active->value)->where('valid_to', '<', $today->toDateString())->pluck('id') as $id) {
            $expired += DB::transaction(function () use ($id, $today): int {
                $note = CoverNote::query()->whereKey($id)->lockForUpdate()->first();
                if ($note === null || $note->status !== CoverNoteStatus::Active || ! $note->valid_to->lessThan($today)) {
                    return 0;
                }
                $note->forceFill(['status' => CoverNoteStatus::Expired->value, 'expired_at' => CarbonImmutable::now()])->save();
                $this->audit->record('cover_note.expired', AuditSubject::of('cover_note', $note->id), ['status' => 'active'], ['status' => 'expired', 'valid_to' => $note->valid_to->toDateString()],
                    null, null, Actor::system());

                return 1;
            });
        }

        return $expired;
    }

    /**
     * R7 hook: the policy replacing this cover note was issued. Must run inside the policy issue transaction; an active or expired note becomes superseded.
     *
     * @throws BusinessRuleViolation COVER_NOTE_NOT_ACTIVE
     */
    public function supersede(string $coverNoteId, string $policyId, string $actorUserId): CoverNote
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('CoverNoteService::supersede must run inside the policy issue transaction.');
        }
        $note = $this->lock($coverNoteId, [CoverNoteStatus::Active, CoverNoteStatus::Expired]);
        $before = $note->status->value;
        $note->forceFill(['status' => CoverNoteStatus::Superseded->value, 'superseded_by_policy_id' => $policyId, 'superseded_at' => CarbonImmutable::now()])->save();
        $this->audit->record('cover_note.superseded', AuditSubject::of('cover_note', $note->id), ['status' => $before], ['status' => 'superseded', 'policy_id' => $policyId],
            null, 'policy.issue', Actor::user($actorUserId));

        return $note;
    }

    /** R7 hook: supersedes every active or expired cover note of the proposal by its policy. Returns how many. */
    public function supersedeForProposal(string $proposalId, string $policyId, string $actorUserId): int
    {
        $ids = CoverNote::query()->where('proposal_id', $proposalId)->whereIn('status', [CoverNoteStatus::Active->value, CoverNoteStatus::Expired->value])->pluck('id');
        foreach ($ids as $id) {
            $this->supersede((string) $id, $policyId, $actorUserId);
        }

        return $ids->count();
    }

    private function assertIssuable(Proposal $proposal, CarbonImmutable $validFrom, CarbonImmutable $validTo, CarbonImmutable $today): void
    {
        if ($proposal->status !== ProposalStatus::Approved) {
            throw new BusinessRuleViolation('PROPOSAL_NOT_APPROVED', "Proposal {$proposal->number} is {$proposal->status->value}; a cover note needs an approved proposal.");
        }
        $version = ProductVersion::query()->whereKey($proposal->product_version_id)->firstOrFail();
        if ($version->recognise_at === PremiumRecognition::CoverNote) {
            throw new BusinessRuleViolation('RECOGNITION_AT_COVER_NOTE_NOT_SUPPORTED',
                'This product recognises premium when a cover note is issued, which is not supported yet: issue the policy instead.');
        }
        if ($validFrom->lessThan($today)) {
            throw new BusinessRuleViolation('COVER_NOTE_BACKDATED', 'A cover note starts today or later.');
        }
        if ($validTo->lessThan($validFrom)) {
            throw new BusinessRuleViolation('COVER_NOTE_DATES_INVALID', 'A cover note ends on or after the day it starts.');
        }
        $max = self::maxDays($proposal->class_code);
        if ($validTo->greaterThan($validFrom->addDays($max - 1))) {
            throw new BusinessRuleViolation('COVER_NOTE_TOO_LONG', "A {$proposal->class_code} cover note lasts at most {$max} days, so it ends by ".$validFrom->addDays($max - 1)->format('j M Y').'.');
        }
        if (CoverNote::query()->where('proposal_id', $proposal->id)->where('status', CoverNoteStatus::Active->value)->exists()) {
            throw new BusinessRuleViolation('COVER_NOTE_ALREADY_ACTIVE', "Proposal {$proposal->number} already has an active cover note.");
        }
    }

    /** @param list<CoverNoteStatus> $allowed */
    private function lock(string $coverNoteId, array $allowed): CoverNote
    {
        $note = CoverNote::query()->whereKey($coverNoteId)->lockForUpdate()->firstOrFail();
        if (! in_array($note->status, $allowed, true)) {
            throw new BusinessRuleViolation('COVER_NOTE_NOT_ACTIVE', "Cover note {$note->number} is {$note->status->value}.");
        }

        return $note;
    }
}
