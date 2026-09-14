<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Application;

use App\Modules\Insurance\Renewal\Application\Documents\RenewalNoticeDocumentData;
use App\Modules\Insurance\Renewal\Domain\ExpiryRegisterStatus;
use App\Modules\Platform\Documents\Generation\DocumentGenerator;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Money\MinorUnits;
use App\Modules\Platform\Notifications\Notifier;
use App\Modules\Platform\Notifications\OutgoingNotification;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Design §4 "Notices: templates renewal_notice via email/SMS gateway (provider abstraction; log-only adapter MVP), with reminders at configured offsets" (slice R9).
 *
 * ASSUMPTION: A-131 — a policy whose renewal quotation is offered and still open gets its renewal notice once it is within erp.renewals.quote_days_before days of
 * expiry (the notice generates the `renewal_notice` PDF on the policy, by the system), then a reminder at each of erp.renewals.reminders days before expiry
 * (sharing that PDF). One message per policy and offset (`renewal_notices` unique; the notification's idempotency key), so reruns send nothing twice; a
 * policy that enters late gets only the latest offset due, not every missed one. It goes to the policyholder through every enabled channel (Notifier). No
 * automatic notice without a renewal quotation (policies without a rating plan, a quotation that could not be offered, declined or expired).
 */
final class RenewalNotices
{
    public function __construct(
        private readonly DocumentGenerator $documents,
        private readonly Notifier $notifier,
    ) {}

    /** @return list<int> notice and reminder offsets in days before expiry, largest first */
    public static function offsets(): array
    {
        $offsets = array_values(array_unique(array_filter(array_map('intval', [RenewalQuotations::quoteDaysBefore(), ...(array) config('erp.renewals.reminders', [])]), fn (int $d): bool => $d >= 0)));
        rsort($offsets);

        return $offsets;
    }

    /** Returns how many notices and reminders were sent. */
    public function sendDue(CarbonImmutable $today): int
    {
        $rows = DB::table('expiry_register as r')->join('quotations as q', 'q.id', '=', 'r.renewal_quotation_id')->join('parties as c', 'c.id', '=', 'r.policyholder_party_id')
            ->where('r.status', ExpiryRegisterStatus::RenewalOffered->value)->where('q.status', 'issued')->where('r.expiry', '>=', $today->toDateString())
            ->orderBy('r.expiry')->get(['r.id', 'r.policy_id', 'r.policy_number', 'r.expiry', 'r.policyholder_party_id', 'c.display_name as customer', 'q.id as quotation_id', 'q.number as quotation_number',
                'q.gross_premium_minor', 'q.currency', 'q.valid_until']);
        $sent = 0;
        foreach ($rows as $row) {
            $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse((string) $row->expiry), false);
            $due = array_values(array_filter(self::offsets(), fn (int $offset): bool => $offset >= $daysLeft));
            $offset = $due === [] ? null : end($due);
            if ($offset === null || DB::table('renewal_notices')->where('policy_id', $row->policy_id)->where('offset_days', $offset)->exists()) {
                continue;
            }
            $sent += $this->send($row, $offset, $today) ? 1 : 0;
        }

        return $sent;
    }

    private function send(\stdClass $row, int $offset, CarbonImmutable $today): bool
    {
        $first = DB::table('renewal_notices')->where('policy_id', $row->policy_id)->orderBy('created_at')->first(['generated_document_id', 'stored_document_id']);
        $generatedId = $first?->generated_document_id;
        $storedId = $first?->stored_document_id;
        if ($first === null) {
            try {
                $generated = $this->documents->generateBySystem(DocumentTemplateCode::RenewalNotice, RenewalNoticeDocumentData::OBJECT_TYPE, (string) $row->id);
                [$generatedId, $storedId] = [$generated->id, $generated->storedDocumentId];
            } catch (BusinessRuleViolation $problem) {
                // The notice still goes out; the queue shows it without a PDF (for example no active renewal notice template).
                Log::warning('Renewal notice PDF not generated', ['policy' => $row->policy_number, 'reason' => $problem->reasonCode, 'message' => $problem->getMessage()]);
            }
        }
        $expires = CarbonImmutable::parse((string) $row->expiry)->format('j M Y');
        $renewBy = CarbonImmutable::parse((string) $row->valid_until)->format('j M Y');
        $premium = MinorUnits::format((int) $row->gross_premium_minor, (string) $row->currency);
        $kind = $first === null ? 'notice' : 'reminder';
        $title = ($kind === 'notice' ? 'Renewal notice' : 'Renewal reminder').": policy {$row->policy_number} expires on {$expires}";
        $body = "Dear {$row->customer}, your policy {$row->policy_number} expires on {$expires}. The renewal premium is {$row->currency} {$premium} (quotation {$row->quotation_number}), "
            ."valid until {$renewBy}. Contact your branch or agent to renew.";

        return DB::transaction(function () use ($row, $offset, $today, $generatedId, $storedId, $kind, $title, $body): bool {
            $id = (string) Str::uuid7();
            $inserted = DB::table('renewal_notices')->insertOrIgnore(['id' => $id, 'tenant_id' => TenantContext::id(), 'expiry_register_id' => $row->id, 'policy_id' => $row->policy_id,
                'offset_days' => $offset, 'kind' => $kind, 'quotation_id' => $row->quotation_id, 'generated_document_id' => $generatedId, 'stored_document_id' => $storedId,
                'notification_ids' => '[]', 'sent_on' => $today->toDateString(), 'created_at' => CarbonImmutable::now()]);
            if ($inserted !== 1) {
                return false;
            }
            $ids = $this->notifier->send(new OutgoingNotification('party', (string) $row->policyholder_party_id, (string) $row->customer, null, 'policy', (string) $row->policy_id,
                $title, $body, "renewal_notice:{$row->policy_id}:{$offset}", DocumentTemplateCode::RenewalNotice->value, $storedId === null ? null : (string) $storedId));
            DB::table('renewal_notices')->where('id', $id)->update(['notification_ids' => json_encode($ids, JSON_THROW_ON_ERROR)]);

            return true;
        });
    }
}
