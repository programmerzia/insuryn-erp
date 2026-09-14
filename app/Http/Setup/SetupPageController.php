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
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Insurance\Product\Application\Templates\ProductClassTemplates;
use App\Modules\Insurance\Rating\Application\DutyBook;
use App\Modules\Insurance\Rating\Application\RatingPlanService;
use App\Modules\Insurance\Rating\Application\Templates\TariffTemplates;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Authorization\RoleAssignmentService;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Setup\CompanySetup;
use App\Modules\Platform\Setup\SetupProgress;
use App\Modules\Platform\Tax\TaxRateSetup;
use App\Modules\Platform\Tenancy\BusinessClock;
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
        private readonly BusinessClock $clock,
        private readonly PermissionChecker $permissions,
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
        $entity = DB::table('legal_entities')->orderBy('created_at')->first(['id', 'code', 'name', 'base_currency', 'timezone']);
        $today = $this->clock->today();
        $firstPeriod = DB::table('fiscal_periods')->orderBy('starts')->first(['starts']);
        $template = (string) config('erp.setup.chart_of_accounts_template');
        $imported = DB::table('accounts')->count();
        $tenantName = (string) DB::table('tenants')->where('id', TenantContext::id())->value('name');

        return Inertia::render('setup/Index', [
            'steps' => $steps,
            'current' => $current,
            'finished' => $this->progress->isFinished(),
            // Gap fix GA-18: a company not saved yet gets a short code suggested from its name.
            'company' => ['code' => $entity === null ? CompanySetup::suggestCode($tenantName) : (string) $entity->code, 'suggested' => $entity === null,
                'name' => $entity === null ? $tenantName : (string) $entity->name,
                'timezone' => $this->clock->timezone(), 'timezones' => timezone_identifiers_list(),
                'branches' => DB::table('branches')->orderBy('code')->get(['code', 'name'])->map(fn (object $b): array => ['code' => (string) $b->code, 'name' => (string) $b->name])->values()->all()],
            // Gap fix GA-18, ASSUMPTION A-211: a new fiscal year starts in January (calendar year) unless changed; July is the other usual choice.
            'fiscalYear' => ['opened' => $firstPeriod !== null, 'first_month' => $firstPeriod === null ? $today->format('Y').'-'.str_pad((string) config('erp.setup.fiscal_year_first_month', 1), 2, '0', STR_PAD_LEFT) : substr((string) $firstPeriod->starts, 0, 7),
                'base_currency' => $entity->base_currency ?? (string) DB::table('tenants')->where('id', TenantContext::id())->value('base_currency'),
                'periods' => DB::table('fiscal_periods')->count()],
            'chartOfAccounts' => ['template' => $template, 'templates' => collect(ChartOfAccountsSetup::TEMPLATES)->map(fn (array $t, string $id): array => ['id' => $id, ...$t])->values()->all(),
                'rows' => $imported > 0 ? [] : $coa->template($template), 'imported' => $imported > 0 ? $imported : null,
                // Role descriptions without their design-note asides ("(Distribution D6)", "(LATER)").
                'roles' => DB::table('account_roles')->orderBy('code')->pluck('description', 'code')->map(fn (mixed $d): string => (string) preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $d))->all()],
            'product' => ['linesOfBusiness' => self::LINES_OF_BUSINESS,
                'existing' => DB::table('products')->orderBy('code')->get(['code', 'name', 'insurance_class'])->map(fn (object $p): array => (array) $p)->values()->all(),
                'vatInForce' => DB::table('tax_rates')->where('tax_type', 'VAT')->where('withholding', false)->value('rate_bp'),
                // Gap fix GA-18: rated products from the tariff templates, and the template tariffs still waiting for a second person to approve them.
                'templates' => $this->productTemplates(),
                'canUseTemplates' => $this->permissions->has($actor, 'rating.manage_plans'),
                'tariffsToApprove' => DB::table('rating_plans')->whereIn('status', ['draft', 'approved'])->orderBy('code')->get(['id', 'code', 'name', 'version', 'status'])
                    ->map(fn (object $p): array => ['id' => (string) $p->id, 'code' => (string) $p->code, 'name' => (string) $p->name, 'version' => (int) $p->version, 'status' => (string) $p->status])->values()->all()],
            'bankAccounts' => $this->bankAccountsStep($entity === null ? null : (string) $entity->id, $today),
            'underwriting' => $this->underwritingDefaults(),
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
            'approvers' => implode(' → ', array_map(fn (string $code): string => $roleNames[$code] ?? $code, $r->roles))], $policies->defaults($this->clock->today())),
            'existing' => DB::table('approval_policies')->count()];
    }

    /**
     * Gap fix GA-18: the tariff templates the product step offers — a rated product of the class with its placeholder tariff and duties.
     *
     * @return list<array{class_code: string, class_name: string, code: string, name: string, lob: string, plan_code: string, product_exists: bool}>
     */
    private function productTemplates(): array
    {
        $templates = [];
        foreach (ProductClassTemplates::PRODUCTS as $class => [$code, $name, $lob]) {
            $plan = TariffTemplates::planFor($class, '2026-01-01');
            $templates[] = ['class_code' => $class, 'class_name' => (string) (DB::table('product_classes')->where('code', $class)->value('name_en') ?? $class), 'code' => $code, 'name' => $name,
                'lob' => $lob, 'plan_code' => (string) ($plan['code'] ?? ''), 'product_exists' => DB::table('products')->where('code', $code)->exists()];
        }

        return $templates;
    }

    /**
     * Gap fix GA-18: the bank accounts step — accounts already added, the GL accounts a bank account may post to, and the one the posting rules use for
     * the main bank (bank_main) as the default.
     *
     * @return array{existing: array<int, array{bank_name: string, account_no_masked: string, currency: string, gl: string}>, glAccounts: array<int, array{id: string, code: string, name: string}>,
     *     defaultGlAccountId: string|null, currency: string|null}
     */
    private function bankAccountsStep(?string $entityId, CarbonImmutable $today): array
    {
        if ($entityId === null) {
            return ['existing' => [], 'glAccounts' => [], 'defaultGlAccountId' => null, 'currency' => null];
        }
        $day = $today->toDateString();
        $bankMain = DB::table('account_role_mappings')->where('entity_id', $entityId)->where('role_code', 'bank_main')->where('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))->orderByDesc('effective_from')->value('account_id');

        return [
            'existing' => DB::table('bank_accounts as b')->join('accounts as a', 'a.id', '=', 'b.gl_account_id')->where('b.entity_id', $entityId)->orderBy('b.bank_name')
                ->get(['b.bank_name', 'b.account_no_masked', 'b.currency', 'a.code', 'a.name'])
                ->map(fn (object $b): array => ['bank_name' => (string) $b->bank_name, 'account_no_masked' => (string) $b->account_no_masked, 'currency' => (string) $b->currency, 'gl' => "{$b->code} {$b->name}"])->values()->all(),
            'glAccounts' => DB::table('accounts')->where('entity_id', $entityId)->where('type', 'asset')->where('is_postable', true)->where('is_control', false)->where('status', 'active')
                ->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $a): array => ['id' => (string) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name])->values()->all(),
            'defaultGlAccountId' => $bankMain === null ? null : (string) $bankMain,
            'currency' => (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency'),
        ];
    }

    /**
     * Gap fix GA-18: the placeholder underwriting limits (A-90) as the step shows them, and how many limits the tenant already has.
     *
     * @return array{defaults: list<array{role: string, class: string, amount: string}>, existing: int}
     */
    private function underwritingDefaults(): array
    {
        $roles = DB::table('roles')->pluck('name', 'code')->map(fn (mixed $n): string => (string) $n)->all();
        $classes = DB::table('product_classes')->pluck('name_en', 'code')->map(fn (mixed $n): string => (string) $n)->all();
        $currency = (string) (DB::table('legal_entities')->orderBy('created_at')->value('base_currency') ?? 'BDT');
        $rows = [];
        foreach (UnderwritingLimits::DEFAULTS as $role => $limits) {
            if (! isset($roles[$role])) {
                continue;
            }
            foreach ($limits as $class => $max) {
                $rows[] = ['role' => $roles[$role], 'class' => $classes[$class] ?? $class, 'amount' => PageSupport::money($max, $currency)];
            }
        }

        return ['defaults' => $rows, 'existing' => DB::table('underwriting_limits')->count()];
    }

    /** Gap fix GA-18: a bank account the premium is taken into, posting to a GL account of the chart (bank_main's by default). */
    public function bankAccounts(Request $request, BankAccountService $bankAccounts): RedirectResponse
    {
        /** @var array{bank_name: string, account_no_masked: string, gl_account_id: string} $data */
        $data = $request->validate(['bank_name' => ['required', 'string', 'max:255'], 'account_no_masked' => ['required', 'string', 'max:64'], 'gl_account_id' => ['required', 'uuid']],
            ['gl_account_id.required' => 'Choose the account in the chart this bank account posts to.', 'account_no_masked.required' => 'Enter the account number, or its last digits.']);
        $entity = DB::table('legal_entities')->orderBy('created_at')->first(['id', 'base_currency']);
        if ($entity === null || DB::table('accounts')->doesntExist()) {
            throw ValidationException::withMessages(['form' => 'Save the company, the fiscal year and the chart of accounts first: a bank account posts to an account in the chart.']);
        }
        $account = $bankAccounts->create((string) $entity->id, $data['gl_account_id'], $data['bank_name'], $data['account_no_masked'], (string) $entity->base_currency, PageSupport::actor($request));

        return $this->saved($request, 'bank_accounts', "Bank account {$account->bank_name} {$account->account_no_masked} added. Add another here or in Bank.");
    }

    /** Gap fix GA-18: the placeholder underwriting limits (A-90, flagged verify) from today (a limit never starts in the past). */
    public function underwritingLimits(Request $request, UnderwritingLimits $limits): RedirectResponse
    {
        $created = $limits->acceptDefaults($this->clock->today(), PageSupport::actor($request));

        return $this->saved($request, 'underwriting_limits', $created === 0 ? 'Underwriting limits kept as they were: every role and class already has one.'
            : $created.' underwriting '.($created === 1 ? 'limit' : 'limits').' set. Change them any time in Admin → Underwriting limits.');
    }

    public function approvals(Request $request, ApprovalPolicyService $policies): RedirectResponse
    {
        $created = $policies->acceptDefaults($this->clock->today(),PageSupport::actor($request));

        return $this->saved($request, 'approvals', $created === 0 ? 'Approval limits kept as they were: they already cover these cases.'
            : $created.' approval '.($created === 1 ? 'limit' : 'limits').' set. Change them any time in Admin → Approval limits.');
    }

    public function company(Request $request, CompanySetup $company): RedirectResponse
    {
        /** @var array{code: string, name: string, timezone?: string|null, branches: list<array{code: string, name: string}>} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'], 'name' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
            'branches' => ['required', 'array', 'min:1', 'max:50'], 'branches.*.code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'branches.*.name' => ['required', 'string', 'max:255']],
            ['branches.required' => 'Add at least one branch, such as your head office.', 'code.regex' => 'Use letters, digits, - or _.', 'branches.*.code.distinct' => 'Each branch needs its own code.']);
        $company->save(strtoupper($data['code']), $data['name'], array_map(fn (array $b): array => ['code' => strtoupper($b['code']), 'name' => $b['name']], $data['branches']), PageSupport::actor($request),
            $data['timezone'] ?? null);

        return $this->saved($request, 'company', 'Company and branches saved.');
    }

    public function fiscalYear(Request $request, FiscalYearSetup $fiscalYear): RedirectResponse
    {
        /** @var array{first_month: string, base_currency: string} $data */
        $data = $request->validate(['first_month' => ['required', 'date_format:Y-m'], 'base_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/']]);
        $created = $fiscalYear->open(CarbonImmutable::createFromFormat('!Y-m', $data['first_month']) ?: $this->clock->today(),$data['base_currency'], PageSupport::actor($request));

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

        return $this->saved($request, 'chart_of_accounts', count($rows).' accounts added to the chart of accounts. Add or change accounts any time in Accounting → Chart of accounts.');
    }

    public function product(Request $request, ProductCatalogue $catalogue, TaxRateSetup $taxRates): RedirectResponse
    {
        if (($request->input('template') ?? '') !== '') {
            return $this->productFromTemplate($request, $catalogue, $taxRates);
        }
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
            // Part A step 11: the month-end close earns the premium for the days on cover in the month. Gap audit GA-44 (D-71, A-182): 1/365 per day
            // (`daily_365`) by default; `monthly` stays available per product version and through erp.setup.earning_method.
            $catalogue->addVersion($product->id, ['effective_from' => $from->toDateString(), 'term_months' => (int) $data['term_months'], 'earning_method' => (string) config('erp.setup.earning_method', 'daily_365'),
                'tax_profile' => ['tax_type' => $withVat ? 'VAT' : null, 'jurisdiction' => $withVat ? $jurisdiction : null, 'inclusive' => (bool) ($data['vat_inclusive'] ?? true)]], $actor);
        });

        return $this->saved($request, 'product', "Product {$data['name']} created.");
    }

    /**
     * Gap fix GA-18: a rated product started from a tariff template — the product with the class's risk schema and coverages (ProductClassTemplates), the
     * class's duties (VAT and stamp duty) when none is in force for it, and the template tariff as a DRAFT rating plan. ASSUMPTION A-212: a tariff moves
     * money, so the person who creates it here never approves it (R2 SoD, A-69): someone else holding rating.approve_plans approves and activates it in
     * Tariffs, and the step says so. Everything is recorded through the owning services (permissions, audit), in one transaction.
     */
    private function productFromTemplate(Request $request, ProductCatalogue $catalogue, TaxRateSetup $taxRates): RedirectResponse
    {
        /** @var array{template: string, code: string, name: string, effective_from: string} $data */
        $data = $request->validate(['template' => ['required', Rule::in(array_keys(ProductClassTemplates::PRODUCTS))], 'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'], 'effective_from' => ['required', 'date_format:Y-m-d']]);
        $actor = PageSupport::actor($request);
        $class = $data['template'];
        $code = strtoupper($data['code']);
        if (DB::table('products')->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'A product with this code already exists.']);
        }
        $from = CarbonImmutable::parse($data['effective_from']);
        $plan = TariffTemplates::planFor($class, $from->toDateString()) ?? throw ValidationException::withMessages(['template' => 'There is no tariff template for this class.']);
        $jurisdiction = (string) config('erp.setup.tax_jurisdiction');
        $planId = DB::transaction(function () use ($data, $actor, $class, $code, $from, $plan, $jurisdiction, $catalogue, $taxRates): string {
            $vat = collect(TariffTemplates::dutiesFor($class, $from->toDateString()))->firstWhere('code', 'vat');
            if (is_array($vat) && isset($vat['rate_bp'])) {
                $taxRates->ensure($jurisdiction, 'VAT', (int) $vat['rate_bp'], true, $from, $actor);
            }
            foreach (TariffTemplates::dutiesFor($class, $from->toDateString()) as $duty) {
                $inForce = DB::table('duties')->where('code', $duty['code'])->whereRaw('class_codes::jsonb @> ?::jsonb', [json_encode([$class], JSON_THROW_ON_ERROR)])
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from->toDateString()))->exists();
                if (! $inForce) {
                    app(DutyBook::class)->record($duty, $actor);
                }
            }
            $existingPlan = DB::table('rating_plans')->where('code', $plan['code'])->orderByDesc('version')->value('id');
            $planId = $existingPlan === null ? app(RatingPlanService::class)->createFromDefinition($plan, $actor)->id : (string) $existingPlan;
            $product = $catalogue->createProduct($code, $data['name'], ProductClassTemplates::PRODUCTS[$class][2], $actor, 'non_life');
            $catalogue->addVersion($product->id, ['effective_from' => $from->toDateString(), 'term_months' => 12, 'earning_method' => (string) config('erp.setup.earning_method', 'daily_365'),
                'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => $jurisdiction, 'inclusive' => true], ...ProductClassTemplates::versionTerms($class)], $actor);

            return $planId;
        });
        $status = (string) DB::table('rating_plans')->where('id', $planId)->value('status');

        return $this->saved($request, 'product', $status === 'active' ? "Product {$data['name']} created and rated by the {$plan['code']} tariff."
            : "Product {$data['name']} created. Its tariff {$plan['code']} is a draft: someone other than you approves and activates it in Tariffs before quotes can be rated.")
            ->with('next', ['label' => "Open tariff {$plan['code']}", 'url' => "/rating/plans/{$planId}"]);
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
