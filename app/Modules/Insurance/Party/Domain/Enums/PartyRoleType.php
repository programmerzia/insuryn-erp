<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Domain\Enums;

/** Design §2.4 party_roles.role; spec §3: one party can hold several roles. */
enum PartyRoleType: string
{
    case Customer = 'customer';
    case Policyholder = 'policyholder';
    case Insured = 'insured';
    case Beneficiary = 'beneficiary';
    case Agent = 'agent';
    case Vendor = 'vendor';
    case Reinsurer = 'reinsurer';
    case Employee = 'employee';
}
