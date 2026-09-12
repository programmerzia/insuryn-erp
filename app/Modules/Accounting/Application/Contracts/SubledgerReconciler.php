<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

use Brick\Money\Money;
use Carbon\CarbonImmutable;

/** Design §6.2. Every accounting subledger (premium, claims, commission, suspense, …) implements this. */
interface SubledgerReconciler
{
    public function subledger(): string;

    /** Authoritative subledger balance at end of day $asOf, in the entity base currency. */
    public function balanceAt(string $entityId, CarbonImmutable $asOf): Money;

    /** @return iterable<array{object_type:string, object_id:string, amount_minor:int}> */
    public function itemsAt(string $entityId, CarbonImmutable $asOf): iterable;
}
