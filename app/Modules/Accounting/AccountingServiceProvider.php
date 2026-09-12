<?php

declare(strict_types=1);

namespace App\Modules\Accounting;

use App\Modules\Accounting\Application\AmountEvaluator;
use App\Modules\Accounting\Application\Contracts\PostingDispatcher;
use App\Modules\Accounting\Application\Expressions\PostingFunctionProvider;
use App\Modules\Accounting\Application\PostingRuleRepository;
use App\Modules\Accounting\Infrastructure\QueuePostingDispatcher;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/** Register in bootstrap/providers.php. */
final class AccountingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        PostingDispatcher::class => QueuePostingDispatcher::class,
    ];

    public function register(): void
    {
        // Functions are registered at construction: ExpressionLanguage refuses registration after first parse.
        $this->app->singleton(ExpressionLanguage::class, fn () => new ExpressionLanguage(null, [new PostingFunctionProvider()]));
        $this->app->singleton(AmountEvaluator::class, fn ($app) => new AmountEvaluator($app->make(ExpressionLanguage::class)));
        $this->app->singleton(PostingRuleRepository::class, fn ($app) => new PostingRuleRepository(
            (string) config('erp.posting.rules_path'), $app->make(ExpressionLanguage::class)));
        $this->mergeConfigFrom(base_path('config/erp.php'), 'erp');
    }
}
