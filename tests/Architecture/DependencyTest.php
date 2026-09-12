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
    ]);

arch('strict types everywhere')->expect('App')->toUseStrictTypes();
arch('no floats in accounting')->expect('App\Modules\Accounting')->not->toUse(['floatval', 'round', 'number_format']);
