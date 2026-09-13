<?php

declare(strict_types=1);

namespace App\Http\Setup;

use App\Http\Pages\PageSupport;
use App\Models\User;
use App\Modules\Accounting\Application\Setup\ChartOfAccountsSetup;
use App\Modules\Accounting\Application\Setup\FiscalYearSetup;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Platform\Administration\UserAdministration;
use App\Modules\Platform\Approvals\ApprovalPolicyRequest;
use App\Modules\Platform\Approvals\ApprovalPolicyService;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Authorization\RoleAssignmentService;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Setup\CompanySetup;
use App\Modules\Platform\Setup\SetupProgress;
use App\Modules\Platform\Tax\TaxRateSetup;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup wizard screens (session S1, market cross-check G9): one page with the six steps; each step posts on its own, goes through the service
 * that owns the data, marks the step saved and moves on. Re-openable from Admin → Setup.
 */
final class SetupPageController
{
    private const LINES_OF_BUSINESS = ['motor' => 'Motor', 'fire' => 'Fire', 'marine' => 'Marine', 'engineering' => 'Engineering', 'health' => 'Health', 'miscellaneous' => 'Miscellaneous', 'life' => 'Life'];

    public function __construct(
        private readonly SetupWizard $wizard,
        private readonly SetupProgress $progress,
    ) {}

    public function show(Request $request, ChartOfAccountsSetup $coa, ApprovalPolicyService $approvalPolicies): Response
    {
        $actor = PageSupport::actor($request);
        if (! $this->wizard->canUse($actor)) {
            throw new PermissionDenied($actor, 'setup');
        }
        $steps = $this->wizard->steps($actor);
        $requested = (string) $request->query('step', '');
        $current = array_key_exists($requested, SetupWizard::STEPS) ? $requested
            : (collect($steps)->first(fn (array $s): bool => ! $s['done'] && $s['allowed'] && $s['id'] !== 'done')['id'] ?? 'done');
        $entity = DB::table('legal_entities')->orderBy('created_at')->first(['id', 'code', 'name', 'base_currency']);
        $firstPeriod = DB::table('fiscal_periods')->orderBy('starts')->first(['starts']);
        $template = (string) config('erp.setup.chart_of_accounts_template');
        $imported = DB::table('accounts')->count();

        return Inertia::render('setup/Index', [
            'steps' => $steps,
            'current' => $current,
            'finished' => $this->progress->isFinished(),
            'company' => ['code' => $entity->code ?? '', 'name' => $entity->name ?? (string) DB::table('tenants')->where('id', TenantContext::id())->value('name'),
                'branches' => DB::table('branches')->orderBy('code')->get(['code', 'name'])->map(fn (object $b): array => ['code' => (string) $b->code, 'name' => (string) $b->name])->values()->all()],
            'fiscalYear' => ['opened' => $firstPeriod !== null, 'first_month' => $firstPeriod === null ? CarbonImmutable::today()->startOfMonth()->format('Y-m') : substr((string) $firstPeriod->starts, 0, 7),
                'base_currency' => $entity->base_currency ?? (string) DB::table('tenants')->where('id', TenantContext::id())->value('base_currency'),
                'periods' => DB::table('fiscal_periods')->count()],
            'chartOfAccounts' => ['template' => $template, 'templates' => collect(ChartOfAccountsSetup::TEMPLATES)->map(fn (array $t, string $id): array => ['id' => $id, ...$t])->values()->all(),
                'rows' => $imported > 0 ? [] : $coa->template($template), 'imported' => $imported > 0 ? $imported : null,
                // Role descriptions without their design-note asides ("(Distribution D6)", "(LATER)").
                'roles' => DB::table('account_roles')->orderBy('code')->pluck('description', 'code')->map(fn (mixed $d): string => (string) preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $d))->all()],
            'product' => ['linesOfBusiness' => self::LINES_OF_BUSINESS,
                'existing' => DB::table('products')->orderBy('code')->get(['code', 'name', 'insurance_class'])->map(fn (object $p): array => (array) $p)->values()->all(),
                'vatInForce' => DB::table('tax_rates')->where('tax_type', 'VAT')->where('withholding', false)->value('rate_bp')],
            'users' => ['roles' => DB::table('roles')->orderBy('name')->get(['code', 'name'])->map(fn (object $r): array => (array) $r)->values()->all(),
                'existing' => DB::table('users')->where('kind', 'staff')->orderBy('name')->get(['name', 'email'])->map(fn (object $u): array => (array) $u)->values()->all()],
            'approvals' => $this->approvalDefaults($approvalPolicies),
        ]);
    }

    /**
     * Fix F3: the default approval limits (A-55) as the step shows them, and how many limits the tenant already has.
     *
     * @return array{defaults: list<array{label: string, amount: string, approvers: string}>, existing: int}
     */
    private function approvalDefaults(ApprovalPolicyService $policies): array
    {
        $types = ApprovalPolicyService::objectTypes();
        $roleNames = DB::table('roles')->pluck('name', 'code')->map(fn (mixed $n): string => (string) $n)->all();
        $currency = $policies->currency();

        return ['defaults' => array_map(fn (ApprovalPolicyRequest $r): array => ['label' => $types[$r->objectType]['label'] ?? $r->objectType,
            'amount' => ApprovalPolicyService::band($r->minAmountMinor, $r->maxAmountMinor, $currency),
            'approvers' => implode(' → ', array_map(fn (string $code): string => $roleNames[$code] ?? $code, $r->roles))], $policies->defaults(CarbonImmutable::today())),
            'existing' => DB::table('approval_policies')->count()];
    }

    public function approvals(Request $request, ApprovalPolicyService $policies): RedirectResponse
    {
        $created = $policies->acceptDefaults(CarbonImmutable::today(), PageSupport::actor($request));

        return $this->saved($request, 'approvals', $created === 0 ? 'Approval limits kept as they were: they already cover these cases.'
            : $created.' approval '.($created === 1 ? 'limit' : 'limits').' set. Change them any time in Admin → Approval limits.');
    }

    public function company(Request $request, CompanySetup $company): RedirectResponse
    {
        /** @var array{code: string, name: string, branches: list<array{code: string, name: string}>} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'], 'name' => ['required', 'string', 'max:255'],
            'branches' => ['required', 'array', 'min:1', 'max:50'], 'branches.*.code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'branches.*.name' => ['required', 'string', 'max:255']],
            ['branches.required' => 'Add at least one branch, such as your head office.', 'code.regex' => 'Use letters, digits, - or _.', 'branches.*.code.distinct' => 'Each branch needs its own code.']);
        $company->save(strtoupper($data['code']), $data['name'], array_map(fn (array $b): array => ['code' => strtoupper($b['code']), 'name' => $b['name']], $data['branches']), PageSupport::actor($request));

        return $this->saved($request, 'company', 'Company and branches saved.');
    }

    public function fiscalYear(Request $request, FiscalYearSetup $fiscalYear): RedirectResponse
    {
        /** @var array{first_month: string, base_currency: string} $data */
        $data = $request->validate(['first_month' => ['required', 'date_format:Y-m'], 'base_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/']]);
        $created = $fiscalYear->open(CarbonImmutable::createFromFormat('!Y-m', $data['first_month']) ?: CarbonImmutable::today(), $data['base_currency'], PageSupport::actor($request));

        return $this->saved($request, 'fiscal_year', $created > 0 ? 'Fiscal year opened with 12 monthly periods.' : 'Fiscal year saved.');
    }

    public function chartOfAccounts(Request $request, ChartOfAccountsSetup $coa): RedirectResponse
    {
        /** @var array{rows: list<array{code: string, name: string, type: string, normal_side: string, is_control?: bool|null, control_subledger?: string|null, role?: string|null}>} $data */
        $data = $request->validate(['rows' => ['required', 'array', 'min:1', 'max:1000'], 'rows.*.code' => ['present', 'nullable', 'string', 'max:32'], 'rows.*.name' => ['present', 'nullable', 'string', 'max:255'],
            'rows.*.type' => ['present', 'nullable', 'string'], 'rows.*.normal_side' => ['present', 'nullable', 'string'], 'rows.*.is_control' => ['sometimes', 'boolean'],
            'rows.*.control_subledger' => ['nullable', 'string'], 'rows.*.role' => ['nullable', 'string']]);
        $rows = array_map(fn (array $r): array => ['code' => trim((string) $r['code']), 'name' => trim((string) $r['name']), 'type' => (string) $r['type'], 'normal_side' => (string) $r['normal_side'],
            'is_control' => (bool) ($r['is_control'] ?? false), 'control_subledger' => ($r['control_subledger'] ?? '') === '' ? null : $r['control_subledger'], 'role' => ($r['role'] ?? '') === '' ? null : $r['role']], $data['rows']);
        try {
            $outcome = $coa->import((string) config('erp.setup.chart_of_accounts_template'), $rows, PageSupport::actor($request));
        } catch (BusinessRuleViolation $e) {
            if (! in_array($e->reasonCode, ['SETUP_ROLE_ACCOUNT_MISSING', 'SETUP_ROLES_UNMAPPED'], true)) {
                throw $e;
            }
            throw ValidationException::withMessages(['rows' => $e->getMessage()]);
        }
        $errors = [];
        foreach ($outcome->toArray()['errors'] as $error) {
            $errors[$error['row'] === 0 ? 'rows' : 'rows.'.($error['row'] - 2).'.'.$error['field']] ??= $error['message'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->saved($request, 'chart_of_accounts', count($rows).' accounts added to the chart of accounts.');
    }

    public function product(Request $request, ProductCatalogue $catalogue, TaxRateSetup $taxRates): RedirectResponse
    {
        /** @var array{code: string, name: string, lob: string, insurance_class: string, term_months: int, effective_from: string, vat_rate_percent?: string|null, vat_inclusive?: bool} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'], 'name' => ['required', 'string', 'max:255'],
            'lob' => ['required', Rule::in(array_keys(self::LINES_OF_BUSINESS))], 'insurance_class' => ['required', Rule::in(['life', 'non_life'])],
            'term_months' => ['required', 'integer', 'min:1', 'max:60'], 'effective_from' => ['required', 'date_format:Y-m-d'],
            'vat_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'], 'vat_inclusive' => ['sometimes', 'boolean']]);
        $actor = PageSupport::actor($request);
        if (DB::table('products')->where('code', strtoupper($data['code']))->exists()) {
            throw ValidationException::withMessages(['code' => 'A product with this code already exists.']);
        }
        $from = CarbonImmutable::parse($data['effective_from']);
        $jurisdiction = (string) config('erp.setup.tax_jurisdiction');
        $withVat = ($data['vat_rate_percent'] ?? null) !== null && self::basisPoints((string) $data['vat_rate_percent']) > 0;
        DB::transaction(function () use ($data, $actor, $from, $jurisdiction, $withVat, $catalogue, $taxRates): void {
            if ($withVat) {
                $taxRates->ensure($jurisdiction, 'VAT', self::basisPoints((string) $data['vat_rate_percent']), (bool) ($data['vat_inclusive'] ?? true), $from, $actor);
            }
            $product = $catalogue->createProduct(strtoupper($data['code']), $data['name'], $data['lob'], $actor, $data['insurance_class']);
            // Part A step 11: the month-end close earns 1/12 of an annual policy each month.
            $catalogue->addVersion($product->id, ['effective_from' => $from->toDateString(), 'term_months' => (int) $data['term_months'], 'earning_method' => 'monthly',
                'tax_profile' => ['tax_type' => $withVat ? 'VAT' : null, 'jurisdiction' => $withVat ? $jurisdiction : null, 'inclusive' => (bool) ($data['vat_inclusive'] ?? true)]], $actor);
        });

        return $this->saved($request, 'product', "Product {$data['name']} created.");
    }

    public function users(Request $request, UserAdministration $administration, RoleAssignmentService $assignments): RedirectResponse
    {
        /** @var array{users: list<array{name: string, email: string, role: string}>} $data */
        $data = $request->validate(['users' => ['required', 'array', 'min:1', 'max:25'], 'users.*.name' => ['required', 'string', 'max:255'],
            'users.*.email' => ['required', 'email', 'max:255', 'distinct:ignore_case'], 'users.*.role' => ['required', 'string', Rule::exists('roles', 'code')]]);
        $taken = [];
        foreach ($data['users'] as $index => $person) {
            if (User::query()->whereRaw('lower(email) = ?', [Str::lower($person['email'])])->exists()) {
                $taken["users.{$index}.email"] = 'A user with this email already exists.';
            }
        }
        if ($taken !== []) {
            throw ValidationException::withMessages($taken);
        }
        $actor = PageSupport::actor($request);
        foreach ($data['users'] as $person) {
            DB::transaction(function () use ($person, $actor, $administration, $assignments): void {
                $user = $administration->invite($person['name'], $person['email'], $actor);
                $assignments->assign($user->id, (string) DB::table('roles')->where('code', $person['role'])->value('id'), 'tenant', TenantContext::id(), $actor);
            });
        }

        return $this->saved($request, 'users', count($data['users']).' invitation'.(count($data['users']) === 1 ? '' : 's').' sent.');
    }

    public function finish(Request $request): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        if (! $this->wizard->canUse($actor)) {
            throw new PermissionDenied($actor, 'setup');
        }
        $this->progress->complete('done', $actor);

        return redirect('/home')->with('status', 'Setup finished. You can reopen it from Admin → Setup.');
    }

    /** "15" or "7.5" percent → 1500 or 750 basis points, without floating point. */
    private static function basisPoints(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function saved(Request $request, string $step, string $message): RedirectResponse
    {
        $this->progress->complete($step, PageSupport::actor($request));

        return redirect('/setup?step='.SetupWizard::next($step))->with('status', $message);
    }
}
