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
        if (is_string($transaction->endorsement_kind ?? null)) {
            return $this->detailsVariables($transaction, $policy, $code, $locale);
        }

        return [
            ...$this->facts->common($policy, $locale),
            'document' => ['title' => $code->title($locale), 'number' => (string) $this->number($transaction), 'date' => DocumentValues::date(substr((string) $transaction->created_at, 0, 10))],
            'details' => [['label' => $l('Policy', 'পলিসি'), 'value' => (string) $policy->number], ['label' => $l('Effective from', 'কার্যকর তারিখ'), 'value' => DocumentValues::date((string) $transaction->effective_date)],
                ['label' => $l('Reason', 'কারণ'), 'value' => $reason], ['label' => $l('Policy version', 'পলিসি সংস্করণ'), 'value' => (string) $transaction->policy_version]],
            'money' => [['label' => $l('Net premium change', 'নিট প্রিমিয়াম পরিবর্তন'), 'amount' => $money((int) $transaction->net_delta_minor)],
                ['label' => $l('VAT and duties change', 'মূসক ও শুল্ক পরিবর্তন'), 'amount' => $money((int) $transaction->tax_delta_minor)],
                // Slice R7: a re-rated endorsement's stamp duty change.
                ...((int) ($transaction->stamp_duty_delta_minor ?? 0) === 0 ? [] : [['label' => $l('Stamp duty change', 'স্ট্যাম্প শুল্ক পরিবর্তন'), 'amount' => $money((int) $transaction->stamp_duty_delta_minor)]])],
            // Slice R7: the re-rating of the new risk, line by line.
            'rating' => $this->rating($transaction, $locale, $money),
            'total' => ['label' => $l('Total premium change', 'মোট প্রিমিয়াম পরিবর্তন'), 'amount' => $money((int) $transaction->premium_delta_minor)],
            'special_terms' => [$l('From ', '').DocumentValues::date((string) $transaction->effective_date).$l(' the policy is endorsed', ' তারিখ থেকে পলিসিটি এনডোর্স করা হলো')
                .($reason === '' ? '' : ': '.$reason).$l('. All other terms remain unchanged.', '। অন্যান্য সকল শর্ত অপরিবর্তিত থাকবে।')],
        ];
    }

    /**
     * Gap fixes W7 (GA-25): an endorsement that changes no premium — what changed, from what to what, and the wording; no premium rows.
     *
     * @return array<string, mixed>
     */
    private function detailsVariables(\stdClass $transaction, \stdClass $policy, DocumentTemplateCode $code, string $locale): array
    {
        $l = fn (string $en, string $bn): string => DocumentValues::label($locale, $en, $bn);
        $change = is_string($transaction->details_change) ? (array) json_decode($transaction->details_change, true) : [];
        $labels = ['insured_name' => $l('Name of the insured', 'বীমাগ্রহীতার নাম'), 'address' => $l('Address', 'ঠিকানা'), 'mortgagee' => $l('Mortgagee', 'বন্ধকগ্রহীতা'),
            'mobile' => $l('Mobile', 'মোবাইল'), 'email' => $l('Email', 'ইমেইল')];
        $kinds = ['name' => $l('Name of the insured', 'বীমাগ্রহীতার নাম'), 'address' => $l('Address', 'ঠিকানা'), 'mortgagee' => $l('Mortgagee', 'বন্ধকগ্রহীতা'), 'contact' => $l('Contact details', 'যোগাযোগের তথ্য')];
        $none = $l('None', 'নেই');
        $rows = [];
        $wording = [];
        foreach ((array) ($change['after'] ?? []) as $key => $after) {
            $before = ((array) ($change['before'] ?? []))[$key] ?? null;
            $label = $labels[$key] ?? (string) $key;
            $rows[] = ['label' => $label.$l(' before', ' (আগে)'), 'value' => $before === null || $before === '' ? $none : (string) $before];
            $rows[] = ['label' => $label.$l(' now', ' (এখন)'), 'value' => $after === null || $after === '' ? $none : (string) $after];
            $wording[] = $after === null || $after === ''
                ? $l("{$label} is removed", "{$label} বাদ দেওয়া হলো")
                : $l("{$label} reads: {$after}", "{$label}: {$after}");
        }
        $reason = trim((string) $transaction->reason);
        $from = DocumentValues::date((string) $transaction->effective_date);

        return [
            ...$this->facts->common($policy, $locale),
            'document' => ['title' => $code->title($locale), 'number' => $this->number($transaction), 'date' => DocumentValues::date(substr((string) $transaction->created_at, 0, 10))],
            'details' => [['label' => $l('Policy', 'পলিসি'), 'value' => (string) $policy->number], ['label' => $l('Effective from', 'কার্যকর তারিখ'), 'value' => $from],
                ['label' => $l('Change', 'পরিবর্তন'), 'value' => $kinds[(string) $transaction->endorsement_kind] ?? (string) $transaction->endorsement_kind], ...$rows,
                ['label' => $l('Reason', 'কারণ'), 'value' => $reason], ['label' => $l('Policy version', 'পলিসি সংস্করণ'), 'value' => (string) $transaction->policy_version]],
            'money' => [],
            'rating' => [],
            'total' => ['label' => $l('Total premium change', 'মোট প্রিমিয়াম পরিবর্তন'), 'amount' => ''],
            'special_terms' => [$l("From {$from} ", "{$from} তারিখ থেকে ").implode('; ', $wording).$l('. The premium is unchanged. All other terms remain unchanged.', '। প্রিমিয়াম অপরিবর্তিত। অন্যান্য সকল শর্ত অপরিবর্তিত থাকবে।')],
        ];
    }

    /**
     * @param callable(int): string $money
     * @return list<array{label: string, amount: string}>
     */
    private function rating(\stdClass $transaction, string $locale, callable $money): array
    {
        $decoded = is_string($transaction->rating_result ?? null) ? json_decode($transaction->rating_result, true) : null;
        $lines = is_array($decoded) && is_array($decoded['explanation'] ?? null) ? $decoded['explanation'] : [];

        return array_values(array_map(fn (mixed $line): array => ['label' => is_array($line) ? (string) ($locale === 'bn' ? ($line['label_bn'] ?? '') : ($line['label_en'] ?? '')) : '',
            'amount' => $money(is_array($line) ? (int) ($line['amount_minor'] ?? 0) : 0)], $lines));
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
