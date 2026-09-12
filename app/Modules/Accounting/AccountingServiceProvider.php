<?php

declare(strict_types=1);

namespace App\Modules\Accounting;

use App\Modules\Accounting\Application\AmountEvaluator;
use App\Modules\Accounting\Application\PostingRuleRepository;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/** Register in bootstrap/providers.php. */
final class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExpressionLanguage::class, fn () => new ExpressionLanguage());
        $this->app->singleton(AmountEvaluator::class, fn ($app) => new AmountEvaluator($app->make(ExpressionLanguage::class)));
        $this->app->singleton(PostingRuleRepository::class, fn ($app) => new PostingRuleRepository(
            (string) config('erp.posting.rules_path'), $app->make(ExpressionLanguage::class)));
        $this->mergeConfigFrom(base_path('config/erp.php'), 'erp');
    }
}
