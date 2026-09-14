<?php

declare(strict_types=1);

namespace App\Http\People;

use App\Http\Pages\PageSupport;
use App\Modules\People\Payroll\Application\PayrollRules;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** People → Salary structures and tax slabs (addendum §B.10.11 settings, MVP): payroll settings, the structure per grade and the tax slab table, all flagged verify. */
final class PayrollSettingsPageController
{
    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request, PayrollRules $rules): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, EmployeesPageController::AREA);
        $entity = PageSupport::entity();
        $today = app(BusinessClock::class)->today();
        $settings = $rules->settingsOn($entity['id'], $today);
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $structures = $rules->structuresOn($today);
        $taxYear = PayrollRules::taxYear($today->year, $today->month, $settings['tax_year_start_month'] ?? 7);
        $years = DB::table('payroll_tax_slabs')->distinct()->orderByDesc('tax_year')->pluck('tax_year')->map(fn ($y): string => (string) $y)->all();
        $chosenYear = is_string($request->query('tax_year')) ? $request->query('tax_year') : $taxYear;

        return Inertia::render('people/settings/Index', [
            'settings' => $settings === null ? null : [...$settings, 'pf_employee' => PageSupport::percent($settings['pf_employee_bp']), 'pf_employer' => PageSupport::percent($settings['pf_employer_bp']),
                'festival_bonus' => PageSupport::percent($settings['festival_bonus_bp']), 'tax_exempt_fraction' => PageSupport::percent($settings['tax_exempt_fraction_bp']),
                'tax_exempt_cap' => $money($settings['tax_exempt_cap_minor']), 'minimum_tax' => $money($settings['minimum_tax_minor'])],
            'grades' => DB::table('grades')->orderBy('rank')->orderBy('code')->get(['id', 'code', 'name'])->map(function (object $g) use ($structures, $money): array {
                $s = $structures[(string) $g->id] ?? null;

                return ['id' => (string) $g->id, 'code' => (string) $g->code, 'name' => (string) $g->name, 'structure' => $s === null ? null : ['house_rent' => PageSupport::percent($s['house_rent_bp']),
                    'medical' => PageSupport::percent($s['medical_bp']), 'medical_cap' => $s['medical_cap_minor'] === null ? '' : $money($s['medical_cap_minor']), 'conveyance' => $money($s['conveyance_minor']),
                    'basic_min' => $money($s['basic_min_minor']), 'basic_max' => $s['basic_max_minor'] === null ? '' : $money($s['basic_max_minor']), 'verify' => $s['verify']],
                    'employees' => DB::table('employments')->where('grade_id', $g->id)->whereNull('effective_to')->count()];
            })->values()->all(),
            'taxYear' => $chosenYear,
            'taxYears' => array_values(array_unique([$taxYear, ...$years])),
            'slabs' => DB::table('payroll_tax_slabs')->where('tax_year', $chosenYear)->where('category', $settings['tax_category'] ?? 'general')->orderBy('seq')->get()
                ->map(fn (object $s): array => ['band' => $s->band_minor === null ? '' : $money((int) $s->band_minor), 'rate' => PageSupport::percent((int) $s->rate_bp), 'verify' => (bool) $s->verify])->values()->all(),
            'today' => $today->toDateString(),
            'can' => ['manage' => $this->permissions->has($actor, PayrollRules::PERMISSION)],
        ]);
    }

    public function saveSettings(Request $request, PayrollRules $rules): RedirectResponse
    {
        $entity = PageSupport::entity();
        /** @var array{effective_from: string, pf_employee: string, pf_employer: string, festival_bonus: string, festival_bonus_min_service_months: int, festivals: list<array{name: string, month: string}>,
         *     tax_exempt_fraction: string, tax_exempt_cap: string, minimum_tax: string, commission_taxable?: bool, pf_employment_types: list<string>} $data */
        $data = $request->validate(['effective_from' => ['required', 'date_format:Y-m-d'], 'pf_employee' => ['required'], 'pf_employer' => ['required'], 'festival_bonus' => ['required'],
            'festival_bonus_min_service_months' => ['required', 'integer', 'min:0', 'max:120'], 'festivals' => ['array'], 'festivals.*.name' => ['required', 'string', 'max:60'],
            'festivals.*.month' => ['required', 'date_format:Y-m'], 'tax_exempt_fraction' => ['required'], 'tax_exempt_cap' => ['required'], 'minimum_tax' => ['required'],
            'commission_taxable' => ['boolean'], 'pf_employment_types' => ['array'], 'pf_employment_types.*' => ['in:permanent,probation,contract,intern']]);
        $rules->saveSettings($entity['id'], CarbonImmutable::parse($data['effective_from']), [
            'pf_employee_bp' => PageSupport::basisPoints('pf_employee', $data['pf_employee']), 'pf_employer_bp' => PageSupport::basisPoints('pf_employer', $data['pf_employer']),
            'pf_employment_types' => $data['pf_employment_types'], 'festival_bonus_bp' => PageSupport::basisPoints('festival_bonus', $data['festival_bonus']),
            'festival_bonus_min_service_months' => (int) $data['festival_bonus_min_service_months'], 'festivals' => $data['festivals'],
            'tax_exempt_fraction_bp' => PageSupport::basisPoints('tax_exempt_fraction', $data['tax_exempt_fraction']), 'tax_exempt_cap_minor' => PageSupport::minor('tax_exempt_cap', $data['tax_exempt_cap'], $entity['currency']),
            'minimum_tax_minor' => PageSupport::minor('minimum_tax', $data['minimum_tax'], $entity['currency']), 'commission_taxable' => (bool) ($data['commission_taxable'] ?? false), 'verify' => true,
        ], PageSupport::actor($request));

        return back()->with('status', 'Payroll settings saved.');
    }

    public function saveStructure(Request $request, string $grade, PayrollRules $rules): RedirectResponse
    {
        $currency = PageSupport::entity()['currency'];
        /** @var array{effective_from: string, house_rent: string, medical: string, medical_cap?: string|null, conveyance: string, basic_min?: string|null, basic_max?: string|null} $data */
        $data = $request->validate(['effective_from' => ['required', 'date_format:Y-m-d'], 'house_rent' => ['required'], 'medical' => ['required'], 'medical_cap' => ['nullable'],
            'conveyance' => ['required'], 'basic_min' => ['nullable'], 'basic_max' => ['nullable']]);
        $rules->saveStructure($grade, CarbonImmutable::parse($data['effective_from']), [
            'house_rent_bp' => PageSupport::basisPoints('house_rent', $data['house_rent']), 'medical_bp' => PageSupport::basisPoints('medical', $data['medical']),
            'medical_cap_minor' => ($data['medical_cap'] ?? '') === '' ? null : PageSupport::minor('medical_cap', $data['medical_cap'], $currency),
            'conveyance_minor' => PageSupport::minor('conveyance', $data['conveyance'], $currency), 'basic_min_minor' => ($data['basic_min'] ?? '') === '' ? 0 : PageSupport::minor('basic_min', $data['basic_min'], $currency),
            'basic_max_minor' => ($data['basic_max'] ?? '') === '' ? null : PageSupport::minor('basic_max', $data['basic_max'], $currency),
        ], PageSupport::actor($request));

        return back()->with('status', 'Salary structure saved.');
    }

    public function saveSlabs(Request $request, PayrollRules $rules): RedirectResponse
    {
        $currency = PageSupport::entity()['currency'];
        /** @var array{tax_year: string, bands: list<array{band?: string|null, rate: string}>} $data */
        $data = $request->validate(['tax_year' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'bands' => ['required', 'array', 'min:1'], 'bands.*.band' => ['nullable'], 'bands.*.rate' => ['required']]);
        $bands = array_map(fn (array $b, int $i): array => ['band_minor' => ($b['band'] ?? '') === '' ? null : PageSupport::minor("bands.{$i}.band", $b['band'], $currency),
            'rate_bp' => PageSupport::basisPoints("bands.{$i}.rate", $b['rate'])], $data['bands'], array_keys($data['bands']));
        $settings = $rules->settingsOn(PageSupport::entity()['id'], app(BusinessClock::class)->today());
        $rules->saveSlabs($data['tax_year'], $settings['tax_category'] ?? 'general', $bands, true, PageSupport::actor($request));

        return redirect('/people/payroll-settings?tax_year='.$data['tax_year'])->with('status', "Tax slabs for {$data['tax_year']} saved.");
    }
}
