<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Http\Controllers;

use App\Http\Pages\ObjectDocuments;
use App\Http\Pages\ObjectHistory;
use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Product\Domain\Enums\RiskFieldType;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingDecisions;
use App\Modules\Insurance\Underwriting\Domain\Enums\KycStatus;
use App\Modules\Insurance\Underwriting\Domain\Enums\ProposalStatus;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\PermissionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proposal page (slice R5, UX brief §6.2 object page): terms and premium, KYC, underwriting outcome and reasons, documents, timeline and audit. Actions: make a
 * proposal from an issued quotation, verify or waive KYC, submit to underwriting, attach documents.
 */
final class ProposalPageController
{
    /** People who prepare proposals, decide referrals, and read-only quote policies. */
    public const AREA = [QuotationService::PERMISSION, UnderwritingDecisions::PERMISSION, 'policy.create'];

    /** ASSUMPTION: A-92 — who may attach proposal documents: whoever prepares it (quotation.create) or decides it (underwriting.decide), on its branch. */
    public const ATTACH_DOCUMENTS = [QuotationService::PERMISSION, UnderwritingDecisions::PERMISSION];

    public function __construct(
        private readonly ProposalService $proposals,
        private readonly PermissionChecker $permissions,
    ) {}

    public function store(Request $request, string $quotation): RedirectResponse
    {
        $proposal = $this->proposals->createFromQuotation($quotation, PageSupport::actor($request));

        return redirect("/proposals/{$proposal->id}")->with('status', "Proposal {$proposal->number} created.");
    }

    public function show(Request $request, string $proposal, ObjectHistory $history, ObjectDocuments $documents): Response
    {
        $actor = PageSupport::actor($request);
        self::authorizeArea($this->permissions, $actor, self::AREA);
        $model = Proposal::query()->findOrFail($proposal);
        $can = fn (string $permission): bool => $this->permissions->has($actor, $permission, AuthorizationScope::branch($model->entity_id, $model->branch_id));
        $draft = $model->status === ProposalStatus::Draft;
        $subjects = [['proposal', $model->id]];

        return Inertia::render('proposals/Show', [
            'proposal' => self::present($model),
            'risk' => self::risk($model),
            // Flow fix X7: the risk details needed only for the proposal (a chassis number), entered here while it is a draft; submitting refuses while one is empty.
            'riskDetails' => self::riskDetailsOf($model, $draft && $can(QuotationService::PERMISSION)),
            'kycIdTypes' => array_map(fn (string $t): array => ['value' => $t, 'label' => self::idTypeLabel($t)], (array) config('erp.underwriting.kyc_id_types', [])),
            'documentUpload' => array_any(self::ATTACH_DOCUMENTS, $can) ? "/proposals/{$model->id}/documents" : null,
            'can' => [
                'verify_kyc' => $draft && $can(QuotationService::PERMISSION),
                'waive_kyc' => $draft && $model->kyc_status === KycStatus::Pending && $can(UnderwritingDecisions::PERMISSION),
                'submit' => $draft && $can(QuotationService::PERMISSION),
                'decide' => $model->status === ProposalStatus::Submitted && $can(UnderwritingDecisions::PERMISSION),
                // Slice R6: a cover note for an approved proposal without an active one.
                'issue_cover_note' => $model->status === ProposalStatus::Approved && $can(\App\Modules\Insurance\CoverNote\Application\CoverNoteService::ISSUE)
                    && ! DB::table('cover_notes')->where('proposal_id', $model->id)->where('status', 'active')->exists(),
                // Slice R7: the policy of an approved proposal.
                'issue_policy' => $model->status === ProposalStatus::Approved && $can('policy.issue'),
            ],
            'policyIssue' => [
                'allow_credit' => (bool) DB::table('product_versions')->where('id', $model->product_version_id)->value('allow_credit_issue'),
                'valid_until' => ($until = DB::table('quotations')->where('id', $model->quotation_id)->value('valid_until')) === null ? null : (string) $until,
                'policy' => $model->policy_id === null ? null : ['id' => $model->policy_id, 'number' => (string) DB::table('policies')->where('id', $model->policy_id)->value('number')],
            ],
            'today' => \Carbon\CarbonImmutable::today()->toDateString(),
            'coverNoteMaxDays' => \App\Modules\Insurance\CoverNote\Application\CoverNoteService::maxDays($model->class_code),
            'coverNotes' => DB::table('cover_notes')->where('proposal_id', $model->id)->orderByDesc('issued_at')->get(['id', 'number', 'status', 'valid_from', 'valid_to', 'cancel_reason'])
                ->map(fn (object $n): array => ['id' => (string) $n->id, 'number' => (string) $n->number, 'status' => (string) $n->status, 'valid_from' => (string) $n->valid_from,
                    'valid_to' => (string) $n->valid_to, 'cancel_reason' => $n->cancel_reason === null ? null : (string) $n->cancel_reason])->values()->all(),
            'timeline' => $history->timeline($subjects),
            'accounting' => Inertia::defer(fn (): array => [], 'history'),
            'audit' => Inertia::defer(fn (): array => $history->audit($subjects), 'history'),
            'documents' => Inertia::defer(fn (): array => $documents->forPage('proposal', $model->id, "/proposals/{$model->id}"), 'history'),
        ]);
    }

    public function kyc(Request $request, string $proposal): RedirectResponse
    {
        /** @var array{action: string, id_type?: string|null, id_number?: string|null, reason?: string|null} $data */
        $data = $request->validate(['action' => ['required', Rule::in(['verify', 'waive'])], 'id_type' => ['required_if:action,verify', 'nullable', 'string', 'max:32'],
            'id_number' => ['required_if:action,verify', 'nullable', 'string', 'max:64'], 'reason' => ['required_if:action,waive', 'nullable', 'string', 'max:1000']]);
        $actor = PageSupport::actor($request);
        if ($data['action'] === 'verify') {
            $this->proposals->verifyKyc($proposal, (string) ($data['id_type'] ?? ''), (string) ($data['id_number'] ?? ''), $actor);
        } else {
            $this->proposals->waiveKyc($proposal, (string) ($data['reason'] ?? ''), $actor);
        }

        return redirect("/proposals/{$proposal}")->with('status', $data['action'] === 'verify' ? 'Identity verified.' : 'KYC waived.');
    }

    /** Flow fix X7: enter the risk details the proposal needs (only those with required_at proposal). */
    public function riskDetails(Request $request, string $proposal): RedirectResponse
    {
        /** @var array{risk_inputs: array<string, mixed>} $data */
        $data = $request->validate(['risk_inputs' => ['required', 'array', 'max:50']]);
        try {
            $this->proposals->completeRiskDetails($proposal, $data['risk_inputs'], PageSupport::actor($request));
        } catch (RiskInputsInvalid $invalid) {
            $model = Proposal::query()->findOrFail($proposal);
            $schema = ProductVersion::query()->whereKey($model->product_version_id)->firstOrFail()->riskSchema();
            $errors = [];
            foreach ($invalid->errors as $key => $code) {
                $field = $schema->field($key);
                $errors["risk_inputs.{$key}"] = self::problem($code, $field === null ? $key : $field->labelEn);
            }

            return back()->withErrors($errors);
        }

        return redirect("/proposals/{$proposal}")->with('status', 'Risk details saved.');
    }

    public function submit(Request $request, string $proposal): RedirectResponse
    {
        $submitted = $this->proposals->submit($proposal, PageSupport::actor($request));

        return redirect("/proposals/{$proposal}")->with('status', $submitted->status === ProposalStatus::Approved ? 'Proposal approved: no referral needed.' : 'Proposal referred to underwriting.');
    }

    /** Slice R7 (design §2 step 4): issue the policy of an approved proposal and open it. Premium received with a reference unless the product issues on credit (A-117). */
    public function issuePolicy(Request $request, string $proposal, PolicyLifecycle $lifecycle, \App\Http\Pages\NextSteps $nextSteps): RedirectResponse
    {
        /** @var array{on: string, installment_count: int|string, premium_received?: bool|null, premium_reference?: string|null} $data */
        $data = $request->validate(['on' => ['required', 'date_format:Y-m-d'], 'installment_count' => ['required', 'integer', 'min:1', 'max:12'], 'premium_received' => ['nullable', 'boolean'],
            'premium_reference' => ['nullable', 'string', 'max:128']]);
        $reference = ($data['premium_received'] ?? false) ? trim((string) ($data['premium_reference'] ?? '')) : null;
        $actor = PageSupport::actor($request);
        $policy = $lifecycle->issueFromProposal($proposal, \Carbon\CarbonImmutable::parse($data['on']), $actor, (int) $data['installment_count'], $reference);

        // Flow fix X1: the premium receipt is the next step (Part A step 3).
        return redirect("/policies/{$policy->id}")->with('status', "Policy {$policy->number} issued.")->with('next', $nextSteps->afterIssue($actor, $policy->id));
    }

    public function attachDocument(Request $request, string $proposal, ObjectDocuments $documents): RedirectResponse
    {
        $model = Proposal::query()->findOrFail($proposal);
        $this->permissions->authorizeAny(PageSupport::actor($request), self::ATTACH_DOCUMENTS, AuthorizationScope::branch($model->entity_id, $model->branch_id));

        return $documents->attach($request, 'proposal', $model->id, "/proposals/{$model->id}");
    }

    public function downloadDocument(Request $request, string $proposal, string $document, ObjectDocuments $documents): StreamedResponse
    {
        self::authorizeArea($this->permissions, PageSupport::actor($request), self::AREA);
        $model = Proposal::query()->findOrFail($proposal);

        return $documents->download($request, 'proposal', $model->id, $document);
    }

    /**
     * Opens for holders of any of the permissions in any scope (branch-scoped roles); actions check the proposal's branch.
     *
     * @param list<string> $permissions
     */
    public static function authorizeArea(PermissionChecker $checker, string $actor, array $permissions): void
    {
        if (array_intersect($permissions, $checker->permissionsOf($actor)) === []) {
            throw new PermissionDenied($actor, implode('|', $permissions));
        }
    }

    /** @return array<string, mixed> */
    public static function present(Proposal $proposal): array
    {
        $names = DB::table('users')->whereIn('id', array_filter([$proposal->submitted_by, $proposal->decided_by, $proposal->kyc_verified_by, $proposal->manual_loading_by]))->pluck('name', 'id');
        $producer = $proposal->producer_id === null ? null : DB::table('producers')->where('id', $proposal->producer_id)->value('code');
        $money = fn (int $minor): string => PageSupport::money($minor, $proposal->currency);

        return [
            'id' => $proposal->id, 'number' => $proposal->number, 'status' => $proposal->status->value, 'underwriting_status' => $proposal->underwriting_status?->value,
            'quotation' => ['id' => $proposal->quotation_id, 'number' => (string) DB::table('quotations')->where('id', $proposal->quotation_id)->value('number')],
            'customer' => (string) DB::table('parties')->where('id', $proposal->customer_party_id)->value('display_name'),
            'producer' => $producer === null ? null : (string) $producer,
            'product' => (string) DB::table('products')->where('id', $proposal->product_id)->value('code'), 'class_code' => $proposal->class_code,
            'inception' => $proposal->inception->toDateString(), 'currency' => $proposal->currency,
            'sum_insured' => $money($proposal->sum_insured_minor), 'net_premium' => $money($proposal->net_premium_minor), 'duties' => $money($proposal->duties_minor),
            'gross_premium' => $money($proposal->gross_premium_minor), 'rating_result' => $proposal->ratingResult()->toArray(),
            'kyc_status' => $proposal->kyc_status->value, 'kyc_id_type' => $proposal->kyc_id_type === null ? null : self::idTypeLabel($proposal->kyc_id_type), 'kyc_id_number' => $proposal->kyc_id_number,
            'kyc_waiver_reason' => $proposal->kyc_waiver_reason, 'kyc_by' => $proposal->kyc_verified_by === null ? null : (string) ($names[$proposal->kyc_verified_by] ?? ''),
            'referral_reasons' => $proposal->referral_reasons ?? [],
            'manual_loading' => $proposal->manual_loading_bp === null ? null : PageSupport::percent($proposal->manual_loading_bp), 'manual_loading_reason' => $proposal->manual_loading_reason,
            'submitted_by' => $proposal->submitted_by === null ? null : (string) ($names[$proposal->submitted_by] ?? ''), 'submitted_at' => $proposal->submitted_at?->toIso8601String(),
            'decided_by' => $proposal->decided_by === null ? null : (string) ($names[$proposal->decided_by] ?? ''), 'decision_reason' => $proposal->decision_reason,
            'policy_id' => $proposal->policy_id,
        ];
    }

    /** @return list<array{label_en: string, label_bn: string, value: string}> */
    public static function risk(Proposal $proposal): array
    {
        $schema = ProductVersion::query()->whereKey($proposal->product_version_id)->firstOrFail()->riskSchema();
        $rows = [];
        foreach ($schema->fields as $field) {
            $value = $proposal->risk_inputs[$field->key] ?? null;
            $shown = match (true) {
                $value === null => '—',
                $field->type === RiskFieldType::Money && is_int($value) => PageSupport::money($value, $proposal->currency),
                $field->type === RiskFieldType::Select => (string) (array_column($field->options, 'label_en', 'value')[(string) $value] ?? $value),
                $field->type === RiskFieldType::Boolean => $value === true ? 'Yes' : 'No',
                default => is_scalar($value) ? (string) $value : '—',
            };
            $rows[] = ['label_en' => $field->labelEn, 'label_bn' => $field->labelBn, 'value' => $shown];
        }

        return $rows;
    }

    /**
     * Flow fix X7: the proposal-stage risk fields (schema definitions), their current values and which are still empty.
     *
     * @return array{fields: list<array<string, mixed>>, values: array<string, mixed>, missing: list<string>, editable: bool}
     */
    public static function riskDetailsOf(Proposal $proposal, bool $editable): array
    {
        $keys = array_keys(ProposalService::proposalStageFields($proposal));
        $schema = ProductVersion::query()->whereKey($proposal->product_version_id)->firstOrFail()->riskSchema();

        return [
            'fields' => array_values(array_filter($schema->toArray(), fn (array $f): bool => in_array($f['key'], $keys, true))),
            'values' => array_intersect_key($proposal->risk_inputs ?? [], array_flip($keys)),
            'missing' => ProposalService::missingRiskDetails($proposal),
            'editable' => $editable && $keys !== [],
        ];
    }

    private static function problem(string $code, string $label): string
    {
        return match ($code) {
            'REQUIRED' => 'Enter the '.mb_strtolower($label).'.',
            'TOO_LONG' => 'This is too long.',
            'NOT_AN_OPTION' => 'Choose one of the options.',
            'NOT_A_DATE' => 'Enter a date like 15 Sep 2026.',
            'NOT_INTEGER' => 'Enter a whole number.',
            'BELOW_MIN' => 'The value is too low.',
            'ABOVE_MAX' => 'The value is too high.',
            default => 'Check this value.',
        };
    }

    private static function idTypeLabel(string $type): string
    {
        return match ($type) {
            'nid' => 'National ID', 'passport' => 'Passport', 'birth_certificate' => 'Birth certificate', 'trade_licence' => 'Trade licence', 'tin' => 'Tax ID (TIN)',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
