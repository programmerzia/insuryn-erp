<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application\Documents;

use App\Modules\Platform\Documents\Generation\DocumentDataProvider;
use App\Modules\Platform\Documents\Generation\DocumentSubject;
use App\Modules\Platform\Documents\Generation\DocumentValues;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Gap audit GA-41: the discharge voucher for one claim payment — the payee signs that the amount settles the claim in full, usually before the
 * money is released. It can be printed once the payment is approved (approved, release requested or awaiting release approval, or paid); a payment
 * still waiting for approval or rejected has nothing agreed to sign (DOCUMENT_OBJECT_NOT_READY). The PDF belongs to the claim page; its number is the
 * claim number with the payment's sequence, like CLM-HO-2026-000001/P1. ASSUMPTION: A-183.
 */
final class DischargeVoucherDocumentData implements DocumentDataProvider
{
    private const PRINTABLE = ['approved', 'release_requested', 'release_pending_approval', 'paid'];

    public function __construct(private readonly ClaimDocumentFacts $facts) {}

    public function objectType(): string
    {
        return 'claim_payment';
    }

    public function templateCodes(): array
    {
        return [DocumentTemplateCode::DischargeVoucher];
    }

    public function subject(string $objectId, DocumentTemplateCode $code): DocumentSubject
    {
        $payment = $this->payment($objectId);
        $claim = $this->facts->claim((string) $payment->claim_id);

        return $this->facts->subject($claim, $this->facts->policy($claim), self::number($claim, $payment));
    }

    public function variables(string $objectId, DocumentTemplateCode $code, string $locale): array
    {
        $payment = $this->payment($objectId);
        $claim = $this->facts->claim((string) $payment->claim_id);
        $policy = $this->facts->policy($claim);
        $bag = $this->facts->variables($claim, $policy, $locale);
        $l = fn (string $en, string $bn): string => DocumentValues::label($locale, $en, $bn);
        $money = fn (int $minor): string => DocumentValues::money($minor, (string) $payment->currency);
        $payee = (string) DB::table('parties')->where('id', $payment->payee_party_id)->value('display_name');
        $details = [...$bag['details'], ['label' => $l('Payment approved on', 'পরিশোধ অনুমোদনের তারিখ'), 'value' => DocumentValues::date((string) $payment->approved_on)]];
        if ($payment->paid_on !== null) {
            $details[] = ['label' => $l('Paid on', 'পরিশোধের তারিখ'), 'value' => DocumentValues::date((string) $payment->paid_on)];
        }

        return [
            ...$bag['common'],
            'parties' => [...$bag['common']['parties'], ['role' => $l('Payee', 'প্রাপক'), 'name' => $payee]],
            'document' => ['title' => $code->title($locale), 'number' => self::number($claim, $payment), 'date' => DocumentValues::date((string) ($payment->paid_on ?? $payment->approved_on))],
            'details' => $details,
            'money' => [['label' => $l('Claim payment to ', 'দাবি পরিশোধ: ').$payee, 'amount' => $money((int) $payment->amount_minor)]],
            'total' => ['label' => $l('Amount in full and final settlement', 'পূর্ণ ও চূড়ান্ত নিষ্পত্তির অর্থ'), 'amount' => $money((int) $payment->amount_minor)],
        ];
    }

    /** @throws BusinessRuleViolation DOCUMENT_OBJECT_UNKNOWN, DOCUMENT_OBJECT_NOT_READY */
    private function payment(string $paymentId): \stdClass
    {
        $payment = DB::table('claim_payments')->where('id', $paymentId)->first();
        if (! $payment instanceof \stdClass) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_UNKNOWN', 'That claim payment does not exist.');
        }
        if (! in_array((string) $payment->status, self::PRINTABLE, true)) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_NOT_READY', 'A discharge voucher can be printed once the payment is approved.');
        }

        return $payment;
    }

    private static function number(\stdClass $claim, \stdClass $payment): string
    {
        $sequence = DB::table('claim_payments')->where('claim_id', $claim->id)
            ->where(fn ($q) => $q->where('created_at', '<', $payment->created_at)->orWhere(fn ($same) => $same->where('created_at', $payment->created_at)->where('id', '<=', $payment->id)))
            ->count();

        return "{$claim->number}/P{$sequence}";
    }
}
