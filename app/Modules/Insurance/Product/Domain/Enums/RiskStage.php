<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Enums;

/**
 * Flow fix X7: when a required risk field must be known. `quote` — to rate the risk (the default); `proposal` — only once the customer accepts and the
 * proposal is submitted (a motor chassis number: a customer asking for a price rarely has it). Validating at the proposal stage requires both.
 */
enum RiskStage: string
{
    case Quote = 'quote';
    case Proposal = 'proposal';
}
