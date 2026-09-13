<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application\Documents;

use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Documents\Generation\DocumentDataProvider;
use App\Modules\Platform\Documents\Generation\DocumentSubject;
use App\Modules\Platform\Documents\Generation\DocumentValues;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * The receipt for the customer (slice R8; market cross-check Part A step 3): who paid, when, how and the reference, what the money paid (the
 * allocations still standing, by policy and installment) and what is held in suspense. A bounced cheque gets no receipt (DOCUMENT_OBJECT_NOT_READY).
 * ASSUMPTION: A-104.
 */
final class ReceiptDocumentData implements DocumentDataProvider
{
    public function objectType(): string
    {
        return 'receipt';
    }

    public function templateCodes(): array
    {
        return [DocumentTemplateCode::Receipt];
    }

    public function subject(string $objectId, DocumentTemplateCode $code): DocumentSubject
    {
        $receipt = $this->receipt($objectId);
        if ($receipt->status === 'bounced') {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_NOT_READY', 'This cheque bounced, so no receipt can be printed for it.');
        }

        return new DocumentSubject('receipt', (string) $receipt->id, (string) $receipt->number, null, AuthorizationScope::branch((string) $receipt->entity_id, (string) $receipt->branch_id));
    }

    public function variables(string $objectId, DocumentTemplateCode $code, string $locale): array
    {
        $receipt = $this->receipt($objectId);
        $l = fn (string $en, string $bn): string => DocumentValues::label($locale, $en, $bn);
        $currency = (string) $receipt->currency;
        $money = fn (int $minor): string => DocumentValues::money($minor, $currency);

        $allocations = DB::table('receipt_allocations as a')->leftJoin('policies as p', 'p.id', '=', 'a.policy_id')
            ->leftJoin('installments as i', fn ($join) => $join->on('i.id', '=', 'a.target_id')->where('a.target_type', '=', 'installment'))
            ->where('a.receipt_id', $receipt->id)->whereNull('a.reversed_on')->orderBy('a.allocated_at')->orderBy('a.id')
            ->get(['a.target_type', 'a.amount_minor', 'a.suspense_item_id', 'p.number as policy_number', 'p.policyholder_party_id', 'i.no as installment_no']);
        $rows = [];
        foreach ($allocations as $allocation) {
            $rows[] = ['reference' => (string) ($allocation->policy_number ?? ''), 'amount' => $money((int) $allocation->amount_minor),
                'description' => match ((string) $allocation->target_type) {
                    'installment' => $l('Installment ', 'কিস্তি ').(string) $allocation->installment_no,
                    'policy' => $l('Premium', 'প্রিমিয়াম'),
                    'claim_recovery' => $l('Claim recovery', 'দাবি পুনরুদ্ধার'),
                    default => $l('Other', 'অন্যান্য'),
                }.($allocation->suspense_item_id !== null ? $l(' (from suspense)', ' (সাসপেন্স থেকে)') : '')];
        }
        $suspense = DB::table('suspense_items')->where('receipt_id', $receipt->id)->first(['amount_minor', 'allocated_minor']);
        if ($suspense instanceof \stdClass && (int) $suspense->amount_minor - (int) $suspense->allocated_minor > 0) {
            $rows[] = ['reference' => '', 'description' => $l('Held in suspense until it is matched', 'সমন্বয় না হওয়া পর্যন্ত সাসপেন্সে রাখা'), 'amount' => $money((int) $suspense->amount_minor - (int) $suspense->allocated_minor)];
        }

        $payerId = $receipt->party_id ?? ($allocations->first()->policyholder_party_id ?? null);
        $payer = $payerId === null ? null : DB::table('parties')->where('id', $payerId)->value('display_name');
        $details = [['label' => $l('Received on', 'প্রাপ্তির তারিখ'), 'value' => DocumentValues::date((string) $receipt->value_date)],
            ['label' => $l('Method', 'পরিশোধের মাধ্যম'), 'value' => self::channel((string) $receipt->channel, $locale)]];
        if ($receipt->cheque_no !== null) {
            $details[] = ['label' => $l('Cheque', 'চেক'), 'value' => trim("{$receipt->cheque_no} {$receipt->cheque_bank}").($receipt->cheque_date === null ? '' : ', '.DocumentValues::date((string) $receipt->cheque_date))];
        }
        if ($receipt->reference !== null && trim((string) $receipt->reference) !== '') {
            $details[] = ['label' => $l('Reference', 'রেফারেন্স'), 'value' => (string) $receipt->reference];
        }

        return [
            'company' => ['name' => (string) DB::table('legal_entities')->where('id', $receipt->entity_id)->value('name')],
            'document' => ['title' => $code->title($locale), 'number' => (string) $receipt->number, 'date' => DocumentValues::date((string) $receipt->value_date)],
            'currency' => $currency,
            'parties' => is_string($payer) ? [['role' => $l('Received from', 'প্রদানকারী'), 'name' => $payer]] : [],
            'details' => $details,
            'allocations' => $rows,
            'total' => ['label' => $l('Amount received', 'প্রাপ্ত অর্থ'), 'amount' => $money((int) $receipt->amount_minor)],
        ];
    }

    /** @throws BusinessRuleViolation DOCUMENT_OBJECT_UNKNOWN */
    private function receipt(string $receiptId): \stdClass
    {
        $receipt = DB::table('receipts')->where('id', $receiptId)->first();
        if (! $receipt instanceof \stdClass) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_UNKNOWN', 'That receipt does not exist.');
        }

        return $receipt;
    }

    private static function channel(string $channel, string $locale): string
    {
        return $locale === 'bn' ? match ($channel) {
            'bank_transfer' => 'ব্যাংক ট্রান্সফার', 'cash' => 'নগদ', 'cheque' => 'চেক', 'card' => 'কার্ড', 'mobile_money' => 'মোবাইল ব্যাংকিং', default => $channel,
        } : match ($channel) {
            'bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque', 'card' => 'Card', 'mobile_money' => 'Mobile money', default => $channel,
        };
    }
}
