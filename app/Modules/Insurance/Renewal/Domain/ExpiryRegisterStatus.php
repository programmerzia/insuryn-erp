<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Domain;

/**
 * Phase 3 design §4 expiry register (slice R9, D-42): where a policy coming up for renewal stands.
 *
 * - upcoming: in the register, no renewal quotation offered (yet, or never for a policy without a rating plan).
 * - renewal_offered: a renewal quotation was offered (the quotation itself stays `issued`, A-127).
 * - renewed: the renewal policy was issued.
 * - lapsed: closed by the system — expired without a renewal (no_response), or the policy was cancelled or lapsed for non-payment.
 * - not_renewed: a person recorded why the customer did not renew.
 */
enum ExpiryRegisterStatus: string
{
    case Upcoming = 'upcoming';
    case RenewalOffered = 'renewal_offered';
    case Renewed = 'renewed';
    case Lapsed = 'lapsed';
    case NotRenewed = 'not_renewed';

    /** @return list<string> */
    public static function open(): array
    {
        return [self::Upcoming->value, self::RenewalOffered->value];
    }
}
