<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory;

use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Insurance\Regulatory\Application\Provisions\TechnicalProvisionsCloseCheck;
use Illuminate\Support\ServiceProvider;

/** Market gap G5: wires regulatory returns and technical provisions — the quarterly close task "Technical provisions" (a conditional close task, D-111). */
final class RegulatoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([TechnicalProvisionsCloseCheck::class], CloseTaskCheck::class);
    }
}
