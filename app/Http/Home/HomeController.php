<?php

declare(strict_types=1);

namespace App\Http\Home;

use App\Http\Pages\PageSupport;
use App\Http\Setup\SetupWizard;
use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /home: the signed-in user's work queues (UX brief §5). Every signed-in user may open it; a role without queues sees an empty home.
 * A tenant's first sign-in — no products and setup not finished — goes to the setup wizard instead (session S1) for anyone who can do a step.
 */
final class HomeController
{
    /**
     * Flow fix X4: the work a user starts from Home without passing through a list (Part A steps 1, 5 and 10), for the permissions they hold in any scope.
     * Each entry lists every permission its page needs (the journals pages sit behind accounting.view_journals as well).
     */
    public const STARTS = [
        ['label' => 'New quote', 'href' => '/quotations/create', 'permissions' => ['quotation.create']],
        ['label' => 'Record a receipt', 'href' => '/receipts/create', 'permissions' => ['receipt.create']],
        ['label' => 'Register a claim', 'href' => '/claims/create', 'permissions' => ['claim.register']],
        ['label' => 'New manual journal', 'href' => '/accounting/journals/create', 'permissions' => ['accounting.create_manual_journal', 'accounting.view_journals']],
        // Consistency pass: the new modules' first steps (payables, people and payroll, petty cash).
        ['label' => 'Enter a supplier bill', 'href' => '/payables/bills/create', 'permissions' => ['ap.enter_bills']],
        ['label' => 'Record a petty cash voucher', 'href' => '/petty-cash', 'permissions' => ['pettycash.spend']],
        ['label' => 'Hire employee', 'href' => '/people/employees?new=1', 'permissions' => ['hr.manage_employees']],
        ['label' => 'Calculate payroll', 'href' => '/people/payroll', 'permissions' => ['payroll.prepare']],
    ];

    public function __invoke(Request $request, WorkQueues $queues, SetupWizard $setup, PermissionChecker $permissions): Response|RedirectResponse
    {
        $actor = PageSupport::actor($request);
        if ($setup->shouldOpenFor($actor)) {
            return redirect('/setup');
        }
        $held = $permissions->permissionsOf($actor);
        $starts = array_values(array_map(fn (array $s): array => ['label' => $s['label'], 'href' => $s['href']],
            array_filter(self::STARTS, fn (array $s): bool => array_diff($s['permissions'], $held) === [])));

        return Inertia::render('home/Index', ['queues' => $queues->blocks($actor), 'starts' => $starts]);
    }
}
