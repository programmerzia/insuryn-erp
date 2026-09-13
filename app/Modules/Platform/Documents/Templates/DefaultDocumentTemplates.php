<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Templates;

/**
 * The templates a tenant starts with (slice R8), in English and Bangla, for every class: printable A4 HTML — the title and number, parties,
 * product and period, a details table, the money table, the sections the document needs (installments and special terms on a schedule, the
 * wording of an endorsement, what a receipt paid), a closing sentence and a signature line. The letterhead and the footer (generated at and
 * reference) come from the layout. Tenants edit their copy on the template screen; this class is only the starting point.
 * ASSUMPTION: A-107 — the fixed wording (closing sentences, signature lines, Bangla translations) is a placeholder to verify with the insurer.
 */
final class DefaultDocumentTemplates
{
    public static function body(DocumentTemplateCode $code, string $locale): string
    {
        $t = self::words($locale);
        $sections = self::sections($code, $locale);

        $html = <<<BLADE
<div class="doc-head">
  <h1>{{ \$document['title'] }}</h1>
  <p class="meta">{$t['number']} {{ \$document['number'] }} · {$t['date']} {{ \$document['date'] }}</p>
</div>

@if(\$parties)
<table class="parties">
  <tbody>
  @foreach(\$parties as \$party)
    <tr><th>{{ \$party['role'] }}</th><td>{{ \$party['name'] }}</td></tr>
  @endforeach
  @if(\$product['name'])
    <tr><th>{$t['product']}</th><td>{{ \$product['name'] }} ({{ \$product['code'] }})</td></tr>
  @endif
  @if(\$period['from'])
    <tr><th>{$sections['period']}</th><td>{$t['from']} {{ \$period['from'] }} {$t['to']} {{ \$period['to'] }}{$t['until']}</td></tr>
  @endif
  </tbody>
</table>
@endif

@if(\$details)
<h2>{$sections['details']}</h2>
<table class="details">
  <tbody>
  @foreach(\$details as \$row)
    <tr><th>{{ \$row['label'] }}</th><td>{{ \$row['value'] }}</td></tr>
  @endforeach
  </tbody>
</table>
@endif

BLADE;

        if ($code === DocumentTemplateCode::Receipt) {
            $html .= <<<BLADE
@if(\$allocations)
<h2>{$t['applied_to']}</h2>
<table class="money">
  <thead><tr><th>{$t['reference']}</th><th>{$t['description']}</th><th class="num">{$t['amount']} ({{ \$currency }})</th></tr></thead>
  <tbody>
  @foreach(\$allocations as \$row)
    <tr><td>{{ \$row['reference'] }}</td><td>{{ \$row['description'] }}</td><td class="num">{{ \$row['amount'] }}</td></tr>
  @endforeach
  </tbody>
</table>
@endif
<table class="money">
  <tbody><tr class="total"><td>{{ \$total['label'] }} ({{ \$currency }})</td><td class="num">{{ \$total['amount'] }}</td></tr></tbody>
</table>

BLADE;
        } else {
            $html .= <<<BLADE
@if(\$money || \$total['amount'])
<h2>{$sections['money']}</h2>
<table class="money">
  <thead><tr><th>{$t['item']}</th><th class="num">{$t['amount']} ({{ \$currency }})</th></tr></thead>
  <tbody>
  @foreach(\$money as \$row)
    <tr><td>{{ \$row['label'] }}</td><td class="num">{{ \$row['amount'] }}</td></tr>
  @endforeach
  @if(\$total['amount'])
    <tr class="total"><td>{{ \$total['label'] }}</td><td class="num">{{ \$total['amount'] }}</td></tr>
  @endif
  </tbody>
</table>
@endif

BLADE;
        }

        if (in_array($code, [DocumentTemplateCode::PolicySchedule, DocumentTemplateCode::Quotation], true)) {
            $html .= <<<BLADE
@if(\$rating)
<h2>{$t['calculation']}</h2>
<table class="money">
  <tbody>
  @foreach(\$rating as \$row)
    <tr><td>{{ \$row['label'] }}</td><td class="num">{{ \$row['amount'] }}</td></tr>
  @endforeach
  </tbody>
</table>
@endif

BLADE;
        }

        if ($code === DocumentTemplateCode::PolicySchedule) {
            $html .= <<<BLADE
@if(\$installments)
<h2>{$t['installments']}</h2>
<table class="money">
  <thead><tr><th>{$t['no']}</th><th>{$t['due_date']}</th><th class="num">{$t['amount']} ({{ \$currency }})</th></tr></thead>
  <tbody>
  @foreach(\$installments as \$row)
    <tr><td>{{ \$row['no'] }}</td><td>{{ \$row['due_date'] }}</td><td class="num">{{ \$row['amount'] }}</td></tr>
  @endforeach
  </tbody>
</table>
@endif

<h2>{$t['special_terms']}</h2>
@if(\$special_terms)
<ol class="terms">
  @foreach(\$special_terms as \$term)
  <li>{{ \$term }}</li>
  @endforeach
</ol>
@else
<p>{$t['no_special_terms']}</p>
@endif

BLADE;
        }

        if ($code === DocumentTemplateCode::Endorsement) {
            $html .= <<<BLADE
@if(\$special_terms)
<h2>{$t['wording']}</h2>
@foreach(\$special_terms as \$term)
<p>{{ \$term }}</p>
@endforeach
@endif

BLADE;
        }

        $forCompany = $locale === 'bn' ? "{{ \$company['name'] }}-এর {$t['for_company']}" : "{$t['for_company']} {{ \$company['name'] }}";
        $signature = $code === DocumentTemplateCode::DischargeVoucher
            ? "<div class=\"signatures\"><div>{$t['claimant_signature']}</div><div>{$t['witness']}</div><div>{$forCompany}</div></div>"
            : "<div class=\"signatures\"><div></div><div></div><div>{$forCompany}<br>{$t['authorised']}</div></div>";

        return $html."<p class=\"closing\">{$sections['closing']}</p>\n\n{$signature}\n";
    }

    /** @return array<string, string> */
    private static function words(string $locale): array
    {
        return $locale === 'bn' ? [
            'number' => 'নম্বর', 'date' => 'তারিখ', 'product' => 'বীমা পণ্য', 'from' => '', 'to' => 'হতে', 'until' => ' পর্যন্ত', 'applied_to' => 'যে খাতে সমন্বয় করা হয়েছে', 'reference' => 'রেফারেন্স',
            'description' => 'বিবরণ', 'amount' => 'টাকার পরিমাণ', 'item' => 'খাত', 'calculation' => 'প্রিমিয়াম হিসাব', 'installments' => 'কিস্তি', 'no' => 'ক্রমিক', 'due_date' => 'পরিশোধের তারিখ',
            'special_terms' => 'বিশেষ শর্তাবলি', 'no_special_terms' => 'কোনো বিশেষ শর্ত নেই।', 'wording' => 'এনডোর্সমেন্টের বিবরণ', 'claimant_signature' => 'দাবিদারের স্বাক্ষর',
            'witness' => 'সাক্ষীর স্বাক্ষর', 'for_company' => 'পক্ষে', 'authorised' => 'অনুমোদিত স্বাক্ষরকারী',
        ] : [
            'number' => 'No.', 'date' => 'Date', 'product' => 'Product', 'from' => 'From', 'to' => 'to', 'until' => '', 'applied_to' => 'Applied to', 'reference' => 'Reference',
            'description' => 'Description', 'amount' => 'Amount', 'item' => 'Item', 'calculation' => 'Premium calculation', 'installments' => 'Installments', 'no' => 'No.', 'due_date' => 'Due date',
            'special_terms' => 'Special terms', 'no_special_terms' => 'No special terms apply.', 'wording' => 'Endorsement wording', 'claimant_signature' => 'Signature of claimant',
            'witness' => 'Signature of witness', 'for_company' => 'For', 'authorised' => 'Authorised signatory',
        ];
    }

    /** @return array{period: string, details: string, money: string, closing: string} */
    private static function sections(DocumentTemplateCode $code, string $locale): array
    {
        if ($locale === 'bn') {
            return match ($code) {
                DocumentTemplateCode::Quotation => ['period' => 'প্রস্তাবিত মেয়াদ', 'details' => 'ঝুঁকির বিবরণ', 'money' => 'প্রিমিয়াম',
                    'closing' => 'এই কোটেশন বীমা চুক্তি নয়। পলিসি বা কভার নোট ইস্যু হওয়ার পরই বীমা সুরক্ষা শুরু হবে।'],
                DocumentTemplateCode::CoverNote => ['period' => 'অস্থায়ী সুরক্ষার মেয়াদ', 'details' => 'ঝুঁকির বিবরণ', 'money' => 'প্রিমিয়াম',
                    'closing' => 'এই কভার নোট বীমার অস্থায়ী প্রমাণপত্র এবং ইস্যুতব্য পলিসির শর্তাবলি সাপেক্ষে বৈধ।'],
                DocumentTemplateCode::PolicySchedule => ['period' => 'বীমার মেয়াদ', 'details' => 'পলিসির বিবরণ', 'money' => 'প্রিমিয়াম',
                    'closing' => 'এই তফসিল পলিসির অংশ এবং পলিসির শর্তাবলির সাথে একত্রে পড়তে হবে।'],
                DocumentTemplateCode::Endorsement => ['period' => 'বীমার মেয়াদ', 'details' => 'এনডোর্সমেন্ট', 'money' => 'প্রিমিয়াম পরিবর্তন',
                    'closing' => 'এই এনডোর্সমেন্ট পলিসির অংশ। অন্যান্য সকল শর্ত অপরিবর্তিত থাকবে।'],
                DocumentTemplateCode::Receipt => ['period' => 'মেয়াদ', 'details' => 'রসিদের বিবরণ', 'money' => 'অর্থ',
                    'closing' => 'চেক বা অন্য মাধ্যমে প্রদত্ত অর্থ নগদায়ন সাপেক্ষে এই রসিদ বৈধ।'],
                DocumentTemplateCode::RenewalNotice => ['period' => 'নবায়নের মেয়াদ', 'details' => 'নবায়নের বিবরণ', 'money' => 'নবায়ন প্রিমিয়াম',
                    'closing' => 'বীমা সুরক্ষা অবিচ্ছিন্ন রাখতে অনুগ্রহ করে মেয়াদ শেষ হওয়ার আগে পলিসি নবায়ন করুন।'],
                DocumentTemplateCode::ClaimAck => ['period' => 'বীমার মেয়াদ', 'details' => 'দাবির বিবরণ', 'money' => 'অর্থ',
                    'closing' => 'আমরা আপনার দাবি পেয়েছি। সার্ভে ও প্রয়োজনীয় কাগজপত্রের বিষয়ে শীঘ্রই আপনার সাথে যোগাযোগ করা হবে।'],
                DocumentTemplateCode::DischargeVoucher => ['period' => 'বীমার মেয়াদ', 'details' => 'দাবি ও পরিশোধের বিবরণ', 'money' => 'নিষ্পত্তি',
                    'closing' => 'আমি উপরোক্ত অর্থ এই দাবির পূর্ণ ও চূড়ান্ত নিষ্পত্তি হিসেবে গ্রহণ করলাম।'],
            };
        }

        return match ($code) {
            DocumentTemplateCode::Quotation => ['period' => 'Proposed period', 'details' => 'Risk details', 'money' => 'Premium',
                'closing' => 'This quotation is not a contract of insurance. Cover starts only when a policy or cover note is issued.'],
            DocumentTemplateCode::CoverNote => ['period' => 'Temporary cover', 'details' => 'Risk details', 'money' => 'Premium',
                'closing' => 'This cover note is temporary evidence of insurance, subject to the terms of the policy to be issued.'],
            DocumentTemplateCode::PolicySchedule => ['period' => 'Period of insurance', 'details' => 'Policy details', 'money' => 'Premium',
                'closing' => 'This schedule forms part of the policy and must be read together with the policy wording.'],
            DocumentTemplateCode::Endorsement => ['period' => 'Period of insurance', 'details' => 'Endorsement', 'money' => 'Premium change',
                'closing' => 'This endorsement forms part of the policy. All other terms remain unchanged.'],
            DocumentTemplateCode::Receipt => ['period' => 'Period', 'details' => 'Receipt details', 'money' => 'Amount',
                'closing' => 'Payments by cheque or other instruments are subject to realisation.'],
            DocumentTemplateCode::RenewalNotice => ['period' => 'Renewal period', 'details' => 'Renewal details', 'money' => 'Renewal premium',
                'closing' => 'Please renew before the expiry date to keep your cover without a break.'],
            DocumentTemplateCode::ClaimAck => ['period' => 'Period of insurance', 'details' => 'Claim details', 'money' => 'Amount',
                'closing' => 'We have received your claim and will contact you about the survey and the documents needed.'],
            DocumentTemplateCode::DischargeVoucher => ['period' => 'Period of insurance', 'details' => 'Claim and payment', 'money' => 'Settlement',
                'closing' => 'I accept the amount above in full and final settlement of this claim.'],
        };
    }
}
