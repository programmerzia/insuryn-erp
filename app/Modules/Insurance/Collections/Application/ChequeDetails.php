<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use Carbon\CarbonImmutable;

/** A cheque received (spec §4 cheque register): number, drawee bank and cheque date. */
final readonly class ChequeDetails
{
    public function __construct(
        public string $number,
        public string $bank,
        public CarbonImmutable $date,
    ) {}
}
