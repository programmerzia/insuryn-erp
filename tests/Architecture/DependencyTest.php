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

arch('strict types everywhere')->expect('App')->toUseStrictTypes();
arch('no floats in accounting')->expect('App\Modules\Accounting')->not->toUse(['floatval', 'round', 'number_format']);
