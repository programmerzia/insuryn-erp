<?php

declare(strict_types=1);

namespace App\Modules\Accounting;

use App\Modules\Accounting\Application\AmountEvaluator;
use App\Modules\Accounting\Application\Contracts\PostingDispatcher;
use App\Modules\Accounting\Application\Contracts\SubledgerReconciler;
use App\Modules\Accounting\Application\Expressions\PostingFunctionProvider;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalApprovalHandler;
use App\Modules\Accounting\Application\Periods\PeriodReopenApprovalHandler;
use App\Modules\Accounting\Application\PostingRuleRepository;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Accounting\Application\Reversals\ReversalApprovalHandler;
use App\Modules\Accounting\Infrastructure\QueuePostingDispatcher;
use App\Modules\Platform\Approvals\ApprovalHandlerRegistry;
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
        // Business contexts tag their SubledgerReconciler implementations (design §6.2); the kernel never names them.
        $this->app->when(ReconciliationService::class)->needs('$reconcilers')->giveTagged(SubledgerReconciler::class);
        $this->mergeConfigFrom(base_path('config/erp.php'), 'erp');
    }

    public function boot(ApprovalHandlerRegistry $approvals): void
    {
        $approvals->register('journal', ManualJournalApprovalHandler::class);
        $approvals->register('journal_reversal', ReversalApprovalHandler::class);
        $approvals->register('fiscal_period_reopen', PeriodReopenApprovalHandler::class);
    }
}
