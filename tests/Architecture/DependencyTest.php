<?php

declare(strict_types=1);

/** Design §1 dependency direction. Pest's arch plugin (bundled with Pest 3+). */
arch('accounting kernel does not depend on business domains')
    ->expect('App\Modules\Accounting')
    ->not->toUse(['App\Modules\Insurance', 'App\Modules\Finance', 'App\Modules\People', 'App\Modules\Compliance']);

arch('platform depends on nothing above it')
    ->expect('App\Modules\Platform')
    ->not->toUse(['App\Modules\Accounting', 'App\Modules\Insurance', 'App\Modules\Finance', 'App\Modules\People', 'App\Modules\Compliance']);

arch('business modules never touch journal tables directly')
    ->expect(['App\Modules\Insurance', 'App\Modules\Finance', 'App\Modules\People'])
    ->not->toUse(['App\Modules\Accounting\Domain\Models\Journal', 'App\Modules\Accounting\Domain\Models\JournalLine', 'App\Modules\Accounting\Application\PostingEngine']);

arch('business modules never use posting internals or reversal directly')
    ->expect(['App\Modules\Insurance', 'App\Modules\Finance', 'App\Modules\People'])
    ->not->toUse(['App\Modules\Accounting\Application\Posting', 'App\Modules\Accounting\Application\ReversalService']);

/** Design §0 module layout: dependencies point inward (Infrastructure → Application → Domain). */
arch('accounting application does not depend on infrastructure')
    ->expect('App\Modules\Accounting\Application')
    ->not->toUse('App\Modules\Accounting\Infrastructure');

arch('accounting domain does not depend on application or infrastructure')
    ->expect('App\Modules\Accounting\Domain')
    ->not->toUse(['App\Modules\Accounting\Application', 'App\Modules\Accounting\Infrastructure']);

/** CONTEXT.md non-negotiable #4: only the posting engine's journal path writes journals. */
arch('journal writer is used only by the kernel posting path')
    ->expect('App\Modules\Accounting\Application\Posting\JournalWriter')
    ->toOnlyBeUsedIn([
        'App\Modules\Accounting\Application\PostingEngine',
        'App\Modules\Accounting\Application\ReversalService',
        'App\Modules\Accounting\Application\ManualJournals',
        // Gap fix GA-15 (D-81): the year-end closing journal, a kernel journal computed from the ledger (one class, not the close namespace).
        'App\Modules\Accounting\Application\Close\YearEndClose',
    ]);

/** Design §1: business contexts depend on Platform + Accounting\Application contracts only, and not on each other's Domain. */
arch('business contexts use only the accounting application layer')
    ->expect(['App\Modules\Insurance', 'App\Modules\Finance', 'App\Modules\People'])
    ->not->toUse(['App\Modules\Accounting\Domain', 'App\Modules\Accounting\Infrastructure', 'App\Modules\Accounting\Http']);

arch('insurance does not use the finance domain')->expect('App\Modules\Insurance')->not->toUse('App\Modules\Finance\Bank\Domain');
arch('finance does not use the insurance domain')->expect('App\Modules\Finance')->not->toUse([
    'App\Modules\Insurance\Party\Domain', 'App\Modules\Insurance\Product\Domain', 'App\Modules\Insurance\Policy\Domain',
    'App\Modules\Insurance\Collections\Domain', 'App\Modules\Insurance\Commission\Domain', 'App\Modules\Insurance\Claims\Domain',
]);

/** Distribution design note §0 (DECISION D-12): Distribution feeds Insurance's commission subledger; Insurance uses its application layer only. */
arch('distribution depends on platform and the accounting application layer only')->expect('App\Modules\Distribution')->not->toUse([
    'App\Modules\Insurance', 'App\Modules\Finance', 'App\Modules\People', 'App\Modules\Compliance',
    'App\Modules\Accounting\Domain', 'App\Modules\Accounting\Infrastructure', 'App\Modules\Accounting\Http',
]);
arch('other contexts do not use the distribution domain')->expect(['App\Modules\Insurance', 'App\Modules\Finance', 'App\Modules\People'])
    ->not->toUse(['App\Modules\Distribution\Domain', 'App\Modules\Distribution\Infrastructure', 'App\Modules\Distribution\Http']);

arch('strict types everywhere')->expect('App')->toUseStrictTypes();
arch('no floats in accounting')->expect('App\Modules\Accounting')->not->toUse(['floatval', 'round', 'number_format']);
/** Phase 3 design INVARIANT "no floats" in rating (slice R2): integer minor units and basis points, half-even division in RatingMath. */
arch('no floats in rating')->expect('App\Modules\Insurance\Rating')->not->toUse(['floatval', 'round', 'number_format', 'fdiv', 'ceil', 'floor']);

/** Addendum §B.1 (People and Payroll MVP): People uses Platform, the accounting application layer and Finance application contracts only; payroll has no floats. */
arch('people does not use insurance or distribution')->expect('App\Modules\People')->not->toUse(['App\Modules\Insurance', 'App\Modules\Distribution']);
arch('people does not use the finance domain')->expect('App\Modules\People')->not->toUse(['App\Modules\Finance\Bank\Domain', 'App\Modules\Finance\Bank\Infrastructure']);
arch('no floats in payroll')->expect('App\Modules\People\Payroll')->not->toUse(['floatval', 'round', 'number_format', 'fdiv', 'ceil', 'floor']);
