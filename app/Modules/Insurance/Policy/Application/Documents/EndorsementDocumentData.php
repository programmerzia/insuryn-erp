<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\Documents;

use App\Modules\Platform\Documents\Generation\DocumentDataProvider;
use App\Modules\Platform\Documents\Generation\DocumentSubject;
use App\Modules\Platform\Documents\Generation\DocumentValues;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * An endorsement (slice R8): one policy transaction of type endorsement — its effective date, reason and premium change (net, VAT, total). The
 * PDF belongs to the policy's Documents tab; its number is the policy number with the endorsement's sequence, like POL-HO-2026-000001/E1.
 * ASSUMPTION: A-104 (the /E<n> number).
 */
final class EndorsementDocumentData implements DocumentDataProvider
{
    public function __construct(private readonly PolicyDocumentFacts $facts) {}

    public function objectType(): string
    {
        return 'policy_transaction';
    }

    public function templateCodes(): array
    {
        return [DocumentTemplateCode::Endorsement];
    }

    public function subject(string $objectId, DocumentTemplateCode $code): DocumentSubject
    {
        $transaction = $this->transaction($objectId);

        return $this->facts->subject($this->facts->policy((string) $transaction->policy_id), $this->number($transaction));
    }

    public function variables(string $objectId, DocumentTemplateCode $code, string $locale): array
    {
        $transaction = $this->transaction($objectId);
        $policy = $this->facts->policy((string) $transaction->policy_id);
        $l = fn (string $en, string $bn): string => DocumentValues::label($locale, $en, $bn);
        $money = fn (int $minor): string => DocumentValues::money($minor, (string) $policy->currency);
        $reason = trim((string) $transaction->reason);

        return [
            ...$this->facts->common($policy, $locale),
            'document' => ['title' => $code->title($locale), 'number' => (string) $this->number($transaction), 'date' => DocumentValues::date(substr((string) $transaction->created_at, 0, 10))],
            'details' => [['label' => $l('Policy', 'পলিসি'), 'value' => (string) $policy->number], ['label' => $l('Effective from', 'কার্যকর তারিখ'), 'value' => DocumentValues::date((string) $transaction->effective_date)],
                ['label' => $l('Reason', 'কারণ'), 'value' => $reason], ['label' => $l('Policy version', 'পলিসি সংস্করণ'), 'value' => (string) $transaction->policy_version]],
            'money' => [['label' => $l('Net premium change', 'নিট প্রিমিয়াম পরিবর্তন'), 'amount' => $money((int) $transaction->net_delta_minor)],
                ['label' => $l('VAT and duties change', 'মূসক ও শুল্ক পরিবর্তন'), 'amount' => $money((int) $transaction->tax_delta_minor)]],
            'total' => ['label' => $l('Total premium change', 'মোট প্রিমিয়াম পরিবর্তন'), 'amount' => $money((int) $transaction->premium_delta_minor)],
            'special_terms' => [$l('From ', '').DocumentValues::date((string) $transaction->effective_date).$l(' the policy is endorsed', ' তারিখ থেকে পলিসিটি এনডোর্স করা হলো')
                .($reason === '' ? '' : ': '.$reason).$l('. All other terms remain unchanged.', '। অন্যান্য সকল শর্ত অপরিবর্তিত থাকবে।')],
        ];
    }

    /** @throws BusinessRuleViolation DOCUMENT_OBJECT_UNKNOWN */
    private function transaction(string $transactionId): \stdClass
    {
        $transaction = DB::table('policy_transactions')->where('id', $transactionId)->where('type', 'endorsement')->first();
        if (! $transaction instanceof \stdClass) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_UNKNOWN', 'That endorsement does not exist.');
        }

        return $transaction;
    }

    private function number(\stdClass $transaction): string
    {
        $sequence = DB::table('policy_transactions')->where('policy_id', $transaction->policy_id)->where('type', 'endorsement')
            ->where(fn ($q) => $q->where('created_at', '<', $transaction->created_at)->orWhere(fn ($same) => $same->where('created_at', $transaction->created_at)->where('id', '<=', $transaction->id)))
            ->count();

        return DB::table('policies')->where('id', $transaction->policy_id)->value('number').'/E'.$sequence;
    }
}
