<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Http\Controllers;

use App\Http\Pages\ObjectHistory;
use App\Http\Pages\PageSupport;
use App\Modules\Finance\Payables\Application\PayablesQuery;
use App\Modules\Finance\Payables\Application\SupplierService;
use App\Modules\Finance\Payables\Domain\Models\Supplier;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Payables → Suppliers (addendum v2 §B.4 Screens): the queue with a create drawer, and the supplier page with its bills, statement and bank account. */
final class SuppliersPageController
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SupplierService $suppliers,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), PayablesArea::AREA);
        $entity = PageSupport::entity();
        $open = DB::table('ap_bills')->whereIn('status', ['posted', 'partially_paid'])->groupBy('supplier_id')
            ->selectRaw('supplier_id, sum(payable_minor - paid_minor) as owed, min(due_date) as next_due');
        $rows = DB::table('suppliers as s')->join('parties as p', 'p.id', '=', 's.party_id')->leftJoinSub($open, 'o', 'o.supplier_id', '=', 's.id')
            ->where('s.entity_id', $entity['id'])->orderBy('p.display_name')
            ->get(['s.id', 's.code', 'p.display_name', 's.category', 's.status', 's.payment_terms_days', 's.tin', 's.bin', 's.bank_name', 's.account_no_masked', 'o.owed', 'o.next_due'])
            ->map(fn (object $s): array => ['id' => (string) $s->id, 'code' => (string) $s->code, 'name' => (string) $s->display_name, 'category' => (string) $s->category,
                'category_label' => (string) config("erp.payables.categories.{$s->category}.label", $s->category), 'status' => (string) $s->status, 'terms' => (int) $s->payment_terms_days,
                'tin' => $s->tin, 'bin' => $s->bin, 'bank' => $s->bank_name === null ? null : trim("{$s->bank_name} {$s->account_no_masked}"),
                'owed' => PageSupport::money((int) ($s->owed ?? 0), $entity['currency']), 'next_due' => $s->next_due])->values()->all();

        return Inertia::render('payables/suppliers/Index', ['suppliers' => $rows, 'categories' => PayablesArea::categories(),
            'canManage' => $this->permissions->has(PageSupport::actor($request), SupplierService::PERMISSION)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $supplier = $this->suppliers->create(PageSupport::entity()['id'], $data, PageSupport::actor($request));

        return redirect("/payables/suppliers/{$supplier->id}")->with('status', "Supplier {$supplier->code} added.");
    }

    public function update(Request $request, string $supplier): RedirectResponse
    {
        $data = $this->validated($request, false);
        $this->suppliers->update($supplier, $data, PageSupport::actor($request));

        return redirect("/payables/suppliers/{$supplier}")->with('status', 'Supplier updated.');
    }

    public function show(Request $request, string $supplier, PayablesQuery $query, ObjectHistory $history): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, PayablesArea::AREA);
        $model = Supplier::query()->findOrFail($supplier);
        $party = DB::table('parties')->where('id', $model->party_id)->first(['display_name', 'mobile', 'email', 'address']);
        $today = app(BusinessClock::class)->today();
        $from = self::date($request, 'from', $today->startOfMonth()->subMonths(2));
        $to = self::date($request, 'to', $today);
        $money = fn (int $minor): string => PageSupport::money($minor, 'BDT');
        $statement = $query->statement($model->id, $from, $to);
        $bills = DB::table('ap_bills')->where('supplier_id', $model->id)->orderByDesc('bill_date')->orderByDesc('created_at')
            ->get(['id', 'number', 'supplier_reference', 'bill_date', 'due_date', 'payable_minor', 'paid_minor', 'status'])
            ->map(fn (object $b): array => ['id' => (string) $b->id, 'number' => $b->number, 'reference' => (string) $b->supplier_reference, 'bill_date' => (string) $b->bill_date,
                'due_date' => (string) $b->due_date, 'payable' => $money((int) $b->payable_minor), 'outstanding' => $money((int) $b->payable_minor - (int) $b->paid_minor), 'status' => (string) $b->status])->values()->all();
        $owed = (int) DB::table('ap_bills')->where('supplier_id', $model->id)->whereIn('status', ['posted', 'partially_paid'])->sum(DB::raw('payable_minor - paid_minor'));
        $rates = SupplierService::rates($model->category);

        return Inertia::render('payables/suppliers/Show', [
            'supplier' => ['id' => $model->id, 'code' => $model->code, 'name' => (string) ($party->display_name ?? ''), 'category' => $model->category, 'category_label' => $rates['label'],
                'rates' => ['vat' => PageSupport::percent($rates['vat_bp']), 'vds' => PageSupport::percent($rates['vds_bp']), 'tds' => PageSupport::percent($rates['tds_bp'])],
                'status' => $model->status, 'terms' => $model->payment_terms_days, 'tin' => $model->tin, 'bin' => $model->bin, 'vat_registered' => $model->vat_registered,
                'default_account_id' => $model->default_account_id, 'default_account' => PayablesArea::accountLabel($model->default_account_id),
                'bank_name' => $model->bank_name, 'bank_branch' => $model->bank_branch, 'routing_no' => $model->routing_no, 'account_name' => $model->account_name,
                'account_no_masked' => $model->account_no_masked, 'mobile' => $party->mobile ?? null, 'email' => $party->email ?? null, 'address' => $party->address ?? null,
                'owed' => $money($owed)],
            'bills' => $bills,
            'statement' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'opening' => $money($statement['opening_minor']), 'closing' => $money($statement['closing_minor']),
                'lines' => array_map(fn (array $l): array => ['date' => $l['date'], 'kind' => $l['kind'], 'document' => $l['document'], 'reference' => $l['reference'], 'link' => $l['link'],
                    'debit' => $l['debit_minor'] === 0 ? null : $money($l['debit_minor']), 'credit' => $l['credit_minor'] === 0 ? null : $money($l['credit_minor']),
                    'balance' => $money($l['balance_minor'])], $statement['lines'])],
            'categories' => PayablesArea::categories(),
            'canManage' => $this->permissions->has($actor, SupplierService::PERMISSION),
            'canEnterBills' => $this->permissions->has($actor, 'ap.enter_bills'),
            'timeline' => $history->timeline([['supplier', $model->id]]),
            'audit' => Inertia::defer(fn (): array => $history->audit([['supplier', $model->id], ['party', $model->party_id]]), 'history'),
        ]);
    }

    /**
     * @return array{code: string, name?: string|null, party_id?: string|null, category: string, status?: string, payment_terms_days?: int, tin?: string|null, bin?: string|null,
     *     vat_registered?: bool, default_account_id?: string|null, bank_name?: string|null, bank_branch?: string|null, routing_no?: string|null, account_name?: string|null,
     *     account_no?: string|null, mobile?: string|null, email?: string|null, address?: string|null}
     */
    private function validated(Request $request, bool $creating): array
    {
        /** @var array{code: string, name?: string|null, party_id?: string|null, category: string, status?: string, payment_terms_days?: int|string|null, tin?: string|null, bin?: string|null, vat_registered?: bool|string|null, default_account_id?: string|null, bank_name?: string|null, bank_branch?: string|null, routing_no?: string|null, account_name?: string|null, account_no?: string|null, mobile?: string|null, email?: string|null, address?: string|null} $data */
        $data = $request->validate([
            'code' => [$creating ? 'required' : 'prohibited', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'],
            'name' => ['nullable', 'string', 'max:255'], 'party_id' => ['nullable', 'uuid'],
            'category' => [$creating ? 'required' : 'sometimes', 'string', 'max:32'], 'status' => ['sometimes', 'in:active,on_hold,blocked'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'tin' => ['nullable', 'string', 'max:32'], 'bin' => ['nullable', 'string', 'max:32'],
            'vat_registered' => ['nullable', 'boolean'], 'default_account_id' => ['nullable', 'uuid'],
            'bank_name' => ['nullable', 'string', 'max:255'], 'bank_branch' => ['nullable', 'string', 'max:255'], 'routing_no' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'account_name' => ['nullable', 'string', 'max:255'], 'account_no' => ['nullable', 'string', 'max:34', 'regex:/^[0-9 -]+$/'],
            'mobile' => ['nullable', 'string', 'regex:/^\+[0-9]{8,15}$/'], 'email' => ['nullable', 'email', 'max:254'], 'address' => ['nullable', 'string', 'max:1000'],
        ], ['routing_no.regex' => 'A BEFTN routing number has 9 digits.', 'account_no.regex' => 'Enter the account number in digits.', 'mobile.regex' => 'Enter the mobile number with the country code, like +8801711000000.']);
        $terms = $data['payment_terms_days'] ?? null;
        unset($data['payment_terms_days']);
        if ($terms !== null && $terms !== '') {
            $data['payment_terms_days'] = (int) $terms;
        }
        if (array_key_exists('vat_registered', $data)) {
            $data['vat_registered'] = (bool) $data['vat_registered'];
        }

        return $data;
    }

    private static function date(Request $request, string $key, CarbonImmutable $default): CarbonImmutable
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value) : $default;
    }
}
