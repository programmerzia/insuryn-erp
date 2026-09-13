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
 * The policy schedule (slice R8): the policy as it stands — parties, product and class, period, premium (net, VAT and duties, gross), installments
 * — with its special terms: every endorsement's reason and, once policies carry a frozen rating result (R7), the manual rating adjustments and
 * any special terms it records, plus the rating breakdown. A quote has no schedule (DOCUMENT_OBJECT_NOT_READY). ASSUMPTION: A-104.
 */
final class PolicyScheduleDocumentData implements DocumentDataProvider
{
    public function __construct(private readonly PolicyDocumentFacts $facts) {}

    public function objectType(): string
    {
        return 'policy';
    }

    public function templateCodes(): array
    {
        return [DocumentTemplateCode::PolicySchedule];
    }

    public function subject(string $objectId, DocumentTemplateCode $code): DocumentSubject
    {
        $policy = $this->facts->policy($objectId);
        if ($policy->status === 'quote' || $policy->number === null) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_NOT_READY', 'A quote has no schedule yet. Issue the policy first.');
        }

        return $this->facts->subject($policy);
    }

    public function variables(string $objectId, DocumentTemplateCode $code, string $locale): array
    {
        $policy = $this->facts->policy($objectId);
        $l = fn (string $en, string $bn): string => DocumentValues::label($locale, $en, $bn);
        $currency = (string) $policy->currency;
        $money = fn (int $minor): string => DocumentValues::money($minor, $currency);
        $rating = $this->facts->ratingResult($policy);

        $details = [['label' => $l('Status', 'অবস্থা'), 'value' => self::status((string) $policy->status, $locale)]];
        if ($policy->issued_at !== null) {
            $details[] = ['label' => $l('Issued on', 'ইস্যুর তারিখ'), 'value' => DocumentValues::date(substr((string) $policy->issued_at, 0, 10))];
        }
        $details[] = ['label' => $l('Policy version', 'পলিসি সংস্করণ'), 'value' => (string) $policy->version];
        $details[] = ['label' => $l('Installments', 'কিস্তি'), 'value' => (string) $policy->installment_count];
        if ($policy->cancel_date !== null) {
            $details[] = ['label' => $l('Cancelled from', 'বাতিলের তারিখ'), 'value' => DocumentValues::date((string) $policy->cancel_date)];
        }

        $rows = [['label' => $l('Net premium', 'নিট প্রিমিয়াম'), 'amount' => $money((int) $policy->net_premium_minor)]];
        $duties = is_array($rating['duties'] ?? null) ? $rating['duties'] : [];
        if ($duties !== [] && (int) ($rating['gross_premium_minor'] ?? -1) === (int) $policy->gross_premium_minor) {
            foreach ($duties as $duty) {
                $rows[] = ['label' => (string) ($locale === 'bn' ? ($duty['label_bn'] ?? '') : ($duty['label_en'] ?? '')), 'amount' => $money((int) ($duty['amount_minor'] ?? 0))];
            }
        } else {
            $rows[] = ['label' => $l('VAT and duties', 'মূসক ও শুল্ক'), 'amount' => $money((int) $policy->tax_minor)];
        }

        $terms = [];
        $endorsements = DB::table('policy_transactions')->where('policy_id', $policy->id)->where('type', 'endorsement')->orderBy('created_at')->orderBy('id')->get(['effective_date', 'reason']);
        foreach ($endorsements as $endorsement) {
            $reason = trim((string) $endorsement->reason);
            $terms[] = $l('Endorsement from ', 'এনডোর্সমেন্ট, কার্যকর ').DocumentValues::date((string) $endorsement->effective_date).($reason === '' ? '' : ': '.$reason);
        }
        foreach (is_array($rating['explanation'] ?? null) ? $rating['explanation'] : [] as $line) {
            if (is_array($line) && str_starts_with((string) ($line['step_code'] ?? ''), 'manual')) {
                $terms[] = (string) ($locale === 'bn' ? ($line['label_bn'] ?? '') : ($line['label_en'] ?? '')).': '.$money((int) ($line['amount_minor'] ?? 0));
            }
        }
        foreach (is_array($rating['special_terms'] ?? null) ? $rating['special_terms'] : [] as $term) {
            if (is_string($term) && trim($term) !== '') {
                $terms[] = trim($term);
            }
        }

        return [
            ...$this->facts->common($policy, $locale),
            'document' => ['title' => $code->title($locale), 'number' => (string) $policy->number, 'date' => DocumentValues::date(substr((string) ($policy->issued_at ?? $policy->inception), 0, 10))],
            'details' => $details,
            'money' => $rows,
            'total' => ['label' => $l('Gross premium', 'মোট প্রিমিয়াম'), 'amount' => $money((int) $policy->gross_premium_minor)],
            'special_terms' => $terms,
            'installments' => DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->orderBy('id')->get(['no', 'due_date', 'amount_minor'])
                ->map(fn (object $i): array => ['no' => (string) $i->no, 'due_date' => DocumentValues::date((string) $i->due_date), 'amount' => $money((int) $i->amount_minor)])->values()->all(),
            'rating' => array_values(array_map(fn (mixed $line): array => ['label' => is_array($line) ? (string) ($locale === 'bn' ? ($line['label_bn'] ?? '') : ($line['label_en'] ?? '')) : '',
                'amount' => $money(is_array($line) ? (int) ($line['amount_minor'] ?? 0) : 0)], is_array($rating['explanation'] ?? null) ? $rating['explanation'] : [])),
        ];
    }

    private static function status(string $status, string $locale): string
    {
        return $locale === 'bn' ? match ($status) {
            'issued' => 'ইস্যুকৃত', 'active' => 'চালু', 'cancelled' => 'বাতিল', 'lapsed' => 'ল্যাপসড', 'expired' => 'মেয়াদোত্তীর্ণ', 'renewed' => 'নবায়নকৃত', default => $status,
        } : ucfirst($status);
    }
}
