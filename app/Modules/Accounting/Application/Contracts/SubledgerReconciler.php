<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Design §6.2. Every accounting subledger (premium, claims, commission, suspense, …) implements this and is tagged with this
 * interface in the container. Balances are in the entity base currency, signed on the control account's normal side.
 */
interface SubledgerReconciler
{
    /** Matches subledger_controls.subledger, which names the control account roles. */
    public function subledger(): string;

    /** Authoritative subledger balance at end of day $asOf, in the entity base currency. */
    public function balanceAt(string $entityId, CarbonImmutable $asOf): Money;

    /**
     * Balance per object at end of day $asOf, for exception drill-down. object_id is the value the subledger's journal lines carry in
     * the dimension named by itemDimension().
     *
     * @return iterable<array{object_type:string, object_id:string, amount_minor:int}>
     */
    public function itemsAt(string $entityId, CarbonImmutable $asOf): iterable;

    /** The journal line dimension (e.g. `policy`, `agent`, `receipt`) that ties control account lines to items. */
    public function itemDimension(): string;
}
