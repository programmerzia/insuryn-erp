<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Renewal\Application\ExpiryRegister;
use App\Modules\Insurance\Renewal\Application\RenewalQuotations;
use App\Modules\Insurance\Renewal\Domain\ExpiryRegisterStatus;
use App\Modules\Insurance\Renewal\Domain\RenewalReasons;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 3 design §6 "Expiry register queue" (slice R9): policies coming up for renewal by bucket, branch, producer and status, soonest first; the inspector
 * shows the policy, its renewal quotation, the notices sent, and offers a renewal quotation now or records why the policy is not renewed.
 * ASSUMPTION: A-126 — the queue opens for renewal.manage held in any scope; actions check the policy's branch. The register is brought up to date on read.
 */
final class RenewalsPageController
{
    /** Closed rows stay on the queue this many days after expiry. */
    private const RECENT_DAYS = 30;

    public function __construct(
        private readonly ExpiryRegister $register,
        private readonly RenewalQuotations $renewalQuotations,
        private readonly PermissionChecker $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        // Follow-up H1: a branch-scoped user lists only their branches' expiring policies (and picks only their branches in the filter).
        $reach = $this->permissions->authorizeArea($actor, [ExpiryRegister::PERMISSION]);
        $today = app(BusinessClock::class)->today();
        $this->register->build($today);
        $entity = PageSupport::entity();
        $buckets = ExpiryRegister::buckets();
        $bucket = in_array((int) $request->query('bucket'), $buckets, true) ? (int) $request->query('bucket') : null;
        $status = in_array($request->query('status'), array_column(ExpiryRegisterStatus::cases(), 'value'), true) ? (string) $request->query('status') : null;
        $branch = is_string($request->query('branch')) && \Illuminate\Support\Str::isUuid($request->query('branch')) ? $request->query('branch') : null;
        $producer = is_string($request->query('producer')) && \Illuminate\Support\Str::isUuid($request->query('producer')) ? $request->query('producer') : null;

        $rows = $reach->constrain(DB::table('expiry_register as r'), 'r.entity_id', 'r.branch_id')->leftJoin('parties as c', 'c.id', '=', 'r.policyholder_party_id')->leftJoin('products as pr', 'pr.id', '=', 'r.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'r.branch_id')->leftJoin('producers as a', 'a.id', '=', 'r.agent_id')->leftJoin('parties as ap', 'ap.id', '=', 'a.party_id')
            ->leftJoin('quotations as q', 'q.id', '=', 'r.renewal_quotation_id')->leftJoin('policies as rp', 'rp.id', '=', 'r.renewal_policy_id')
            ->leftJoin('policies as p', 'p.id', '=', 'r.policy_id')
            ->where('r.entity_id', $entity['id'])->where('r.expiry', '>=', $today->subDays(self::RECENT_DAYS)->toDateString())
            ->when($bucket !== null, fn ($q) => $q->where('r.bucket', $bucket)->whereIn('r.status', ExpiryRegisterStatus::open()))
            ->when($status !== null, fn ($q) => $q->where('r.status', $status))
            ->when($branch !== null, fn ($q) => $q->where('r.branch_id', $branch))
            ->when($producer !== null, fn ($q) => $q->where('r.agent_id', $producer))
            ->orderByRaw("case when r.status in ('upcoming','renewal_offered') then 0 else 1 end")->orderBy('r.expiry');
        $total = (clone $rows)->count(); // GA-40
        $rows = $rows->limit(PageSupport::listPageSize())
            ->get(['r.*', 'c.display_name as customer', 'pr.code as product', 'b.code as branch_code', 'a.code as producer_code', 'ap.display_name as producer_name',
                'q.number as quotation_number', 'q.status as quotation_status', 'q.gross_premium_minor as quotation_gross', 'q.currency as quotation_currency', 'q.valid_until as quotation_valid_until',
                'rp.number as renewal_policy_number', 'p.status as policy_status', 'p.gross_premium_minor as policy_gross', 'p.currency as policy_currency']);
        $notices = DB::table('renewal_notices')->whereIn('expiry_register_id', $rows->pluck('id')->all())->orderBy('created_at')->get()->groupBy('expiry_register_id');
        $manageable = [];
        $reasonChoices = RenewalReasons::choices();

        return Inertia::render('renewals/Index', [
            'today' => $today->toDateString(),
            'buckets' => $buckets,
            'filters' => ['bucket' => $bucket, 'status' => $status, 'branch' => $branch, 'producer' => $producer],
            'quoteDaysBefore' => RenewalQuotations::quoteDaysBefore(),
            'reasons' => array_map(fn (string $code, array $labels): array => ['value' => $code, 'label' => $labels['en']], array_keys($reasonChoices), $reasonChoices),
            'branches' => $reach->constrain(DB::table('branches'), 'entity_id', 'id')->where('entity_id', $entity['id'])->orderBy('code')->get(['id', 'code'])->map(fn (object $b): array => ['id' => (string) $b->id, 'code' => (string) $b->code])->all(),
            'producers' => DB::table('producers as a')->join('parties as ap', 'ap.id', '=', 'a.party_id')->orderBy('a.code')->get(['a.id', 'a.code', 'ap.display_name'])
                ->map(fn (object $a): array => ['id' => (string) $a->id, 'label' => "{$a->code} {$a->display_name}"])->all(),
            'entriesTotal' => $total,
            'entries' => $rows->map(function (object $r) use ($actor, $today, $notices, &$manageable): array {
                $key = "{$r->entity_id}:{$r->branch_id}";
                $canManage = $manageable[$key] ??= $this->permissions->has($actor, ExpiryRegister::PERMISSION, AuthorizationScope::branch((string) $r->entity_id, (string) $r->branch_id));
                $open = in_array($r->status, ExpiryRegisterStatus::open(), true);
                $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse((string) $r->expiry), false);
                $openQuote = $r->quotation_status !== null && in_array($r->quotation_status, ['draft', 'issued'], true);

                return [
                    'id' => (string) $r->id, 'policy_id' => (string) $r->policy_id, 'policy_number' => (string) $r->policy_number, 'policy_status' => (string) $r->policy_status,
                    'customer' => (string) $r->customer, 'product' => (string) $r->product, 'branch' => (string) $r->branch_code,
                    'producer' => $r->producer_code === null ? null : "{$r->producer_code} {$r->producer_name}", 'expiry' => (string) $r->expiry, 'days_left' => $daysLeft,
                    'bucket' => $r->bucket === null ? null : (int) $r->bucket, 'status' => (string) $r->status, 'rated' => (bool) $r->rated,
                    'premium' => PageSupport::money((int) $r->policy_gross, (string) $r->policy_currency),
                    'quotation' => $r->renewal_quotation_id === null ? null : ['id' => (string) $r->renewal_quotation_id, 'number' => (string) $r->quotation_number, 'status' => (string) $r->quotation_status,
                        'premium' => PageSupport::money((int) $r->quotation_gross, (string) $r->quotation_currency), 'valid_until' => (string) $r->quotation_valid_until],
                    'quote_problem' => $r->quote_problem === null ? null : (string) $r->quote_problem,
                    'renewal_policy' => $r->renewal_policy_id === null ? null : ['id' => (string) $r->renewal_policy_id, 'number' => (string) $r->renewal_policy_number],
                    'reason' => RenewalReasons::label($r->reason === null ? null : (string) $r->reason), 'reason_note' => $r->reason_note === null ? null : (string) $r->reason_note,
                    'notices' => ($notices->get($r->id) ?? collect())->map(fn (object $n): array => ['id' => (string) $n->id, 'kind' => (string) $n->kind, 'offset_days' => (int) $n->offset_days,
                        'sent_on' => (string) $n->sent_on, 'document_url' => $n->stored_document_id === null ? null : "/policies/{$r->policy_id}/documents/{$n->stored_document_id}"])->values()->all(),
                    'can_offer' => $canManage && $open && (bool) $r->rated && ! $openQuote && $daysLeft >= 0,
                    'can_record' => $canManage && ($open || $r->status === ExpiryRegisterStatus::Lapsed->value),
                ];
            })->values()->all(),
        ]);
    }

    public function offer(Request $request, string $entry): RedirectResponse
    {
        $quotation = $this->renewalQuotations->offerNow($entry, PageSupport::actor($request));

        return redirect("/quotations/{$quotation->id}")->with('status', "Renewal quotation {$quotation->number} offered.");
    }

    public function notRenewed(Request $request, string $entry): RedirectResponse
    {
        /** @var array{reason: string, note?: string|null} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:32'], 'note' => ['nullable', 'string', 'max:1000']]);
        $this->register->recordNotRenewed($entry, $data['reason'], $data['note'] ?? null, PageSupport::actor($request));

        return redirect('/renewals')->with('status', 'Recorded as not renewed.');
    }
}
