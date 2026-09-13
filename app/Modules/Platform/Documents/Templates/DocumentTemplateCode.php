<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Templates;

/** The documents a tenant prints from templates (Phase 3 design §3). */
enum DocumentTemplateCode: string
{
    case Quotation = 'quotation';
    case CoverNote = 'cover_note';
    case PolicySchedule = 'policy_schedule';
    case Endorsement = 'endorsement';
    case Receipt = 'receipt';
    case RenewalNotice = 'renewal_notice';
    case ClaimAck = 'claim_ack';
    case DischargeVoucher = 'discharge_voucher';

    public function title(string $locale = 'en'): string
    {
        return $locale === 'bn' ? match ($this) {
            self::Quotation => 'প্রিমিয়াম কোটেশন',
            self::CoverNote => 'কভার নোট',
            self::PolicySchedule => 'পলিসি তফসিল',
            self::Endorsement => 'এনডোর্সমেন্ট',
            self::Receipt => 'প্রাপ্তি রসিদ',
            self::RenewalNotice => 'নবায়ন নোটিশ',
            self::ClaimAck => 'দাবি প্রাপ্তি স্বীকারপত্র',
            self::DischargeVoucher => 'দাবি নিষ্পত্তি ভাউচার',
        } : match ($this) {
            self::Quotation => 'Quotation',
            self::CoverNote => 'Cover note',
            self::PolicySchedule => 'Policy schedule',
            self::Endorsement => 'Endorsement',
            self::Receipt => 'Receipt',
            self::RenewalNotice => 'Renewal notice',
            self::ClaimAck => 'Claim acknowledgement',
            self::DischargeVoucher => 'Discharge voucher',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
