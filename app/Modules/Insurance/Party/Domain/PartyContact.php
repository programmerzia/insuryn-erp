<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Domain;

use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;

/**
 * GA-17: how to reach a party and identify it. The mobile is kept in international form: a Bangladeshi number typed as 01XXXXXXXXX, 8801XXXXXXXXX or
 * +8801XXXXXXXXX becomes +8801XXXXXXXXX; another country's number must start with + and its country code. The identity number is an individual's NID or an
 * organisation's business registration number (BRN); a date of birth belongs to an individual, a contact person to an organisation.
 */
final readonly class PartyContact
{
    public function __construct(
        public ?string $mobile = null,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $identityNo = null,
        public ?CarbonImmutable $dateOfBirth = null,
        public ?string $contactPerson = null,
    ) {}

    /**
     * @param array{mobile?: string|null, email?: string|null, address?: string|null, identity_no?: string|null, date_of_birth?: string|null, contact_person?: string|null} $input
     *
     * @throws BusinessRuleViolation PARTY_MOBILE_INVALID, PARTY_EMAIL_INVALID, PARTY_DATE_OF_BIRTH_INVALID, PARTY_FIELD_NOT_FOR_KIND
     */
    public static function fromInput(PartyKind $kind, array $input, ?CarbonImmutable $today = null): self
    {
        $text = fn (string $key): ?string => isset($input[$key]) && trim((string) $input[$key]) !== '' ? trim((string) $input[$key]) : null;
        $email = $text('email');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new BusinessRuleViolation('PARTY_EMAIL_INVALID', 'Enter an email address like name@example.com.');
        }
        $born = $text('date_of_birth');
        $dateOfBirth = null;
        if ($born !== null) {
            if ($kind !== PartyKind::Individual) {
                throw new BusinessRuleViolation('PARTY_FIELD_NOT_FOR_KIND', 'Only a person has a date of birth.');
            }
            $dateOfBirth = preg_match('/^\d{4}-\d{2}-\d{2}$/', $born) === 1 ? CarbonImmutable::parse($born) : null;
            if ($dateOfBirth === null || $dateOfBirth->greaterThan($today ?? CarbonImmutable::today()) || $dateOfBirth->year < 1900) {
                throw new BusinessRuleViolation('PARTY_DATE_OF_BIRTH_INVALID', 'Enter a date of birth in the past.');
            }
        }
        $contactPerson = $text('contact_person');
        if ($contactPerson !== null && $kind === PartyKind::Individual) {
            $contactPerson = null; // a person is their own contact
        }

        return new self(self::mobile($text('mobile')), $email === null ? null : mb_strtolower($email), $text('address'), $text('identity_no'), $dateOfBirth, $contactPerson);
    }

    /** @throws BusinessRuleViolation PARTY_MOBILE_INVALID */
    public static function mobile(?string $typed): ?string
    {
        if ($typed === null || trim($typed) === '') {
            return null;
        }
        $plus = str_starts_with(trim($typed), '+');
        $digits = (string) preg_replace('/[\s\-().]/', '', trim($typed));
        $digits = ltrim($digits, '+');
        if (preg_match('/^\d+$/', $digits) !== 1) {
            throw new BusinessRuleViolation('PARTY_MOBILE_INVALID', 'Enter a mobile number like 01712 345678.');
        }
        if (preg_match('/^(?:880)?(1[3-9]\d{8})$/', $digits, $bd) === 1 || preg_match('/^0(1[3-9]\d{8})$/', $digits, $bd) === 1) {
            return '+880'.$bd[1];
        }
        if ($plus && preg_match('/^[1-9]\d{7,14}$/', $digits) === 1) {
            return '+'.$digits;
        }

        throw new BusinessRuleViolation('PARTY_MOBILE_INVALID', 'Enter a Bangladeshi mobile number like 01712 345678, or a number abroad starting with + and the country code.');
    }

    /** @return array{mobile: string|null, email: string|null, address: string|null, identity_no: string|null, date_of_birth: string|null, contact_person: string|null} */
    public function toColumns(): array
    {
        return ['mobile' => $this->mobile, 'email' => $this->email, 'address' => $this->address, 'identity_no' => $this->identityNo,
            'date_of_birth' => $this->dateOfBirth?->toDateString(), 'contact_person' => $this->contactPerson];
    }
}
