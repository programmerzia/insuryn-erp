<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Rating\Application\DutyBook;
use App\Modules\Insurance\Rating\Domain\Enums\DutyBasis;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Duties tab of the tariff editor (slice R10a): record a duty for product classes and end one from a date, through DutyBook (`rating.manage_plans`,
 * DUTY_OVERLAP, audited). Values arrive as stored integers: rate in basis points, amounts and band bounds in minor units.
 */
final class DutiesPageController
{
    public function __construct(private readonly DutyBook $duties) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', Rule::in(['vat', 'stamp', 'levy'])], 'basis' => ['required', Rule::enum(DutyBasis::class)],
            'rate_bp' => ['nullable', 'integer', 'min:0', 'max:10000'], 'amount_minor' => ['nullable', 'integer', 'min:0'],
            'bands' => ['nullable', 'array'], 'bands.*.from' => ['required', 'integer', 'min:0'], 'bands.*.to' => ['nullable', 'integer'], 'bands.*.amount_minor' => ['required', 'integer', 'min:0'],
            'class_codes' => ['required', 'array', 'min:1'], 'class_codes.*' => ['string'], 'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'], 'label_en' => ['required', 'string', 'max:255'], 'label_bn' => ['required', 'string', 'max:255'],
            'verify' => ['boolean'], 'source' => ['nullable', 'string', 'max:64'],
        ], ['class_codes.required' => 'Choose at least one product class.', 'rate_bp.integer' => 'Enter the rate as a percentage with at most two decimals.']);
        $basis = DutyBasis::from((string) $data['basis']);
        $int = fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $duty = $this->duties->record([
            'code' => $data['code'], 'basis' => $basis->value,
            'rate_bp' => $basis === DutyBasis::PctOfPremium ? $int($data['rate_bp'] ?? null) : null,
            'amount_minor' => $basis === DutyBasis::FlatPerPolicy ? $int($data['amount_minor'] ?? null) : null,
            'bands' => $basis === DutyBasis::PerSumInsuredBand ? array_map(fn (mixed $b): array => ['from' => (int) ((array) $b)['from'], 'to' => $int(((array) $b)['to'] ?? null),
                'amount_minor' => (int) ((array) $b)['amount_minor']], array_values((array) ($data['bands'] ?? []))) : null,
            'class_codes' => array_values(array_map('strval', (array) $data['class_codes'])), 'effective_from' => $data['effective_from'], 'effective_to' => ($data['effective_to'] ?? null) ?: null,
            'label_en' => $data['label_en'], 'label_bn' => $data['label_bn'], 'verify' => (bool) ($data['verify'] ?? true), 'source' => ($data['source'] ?? null) ?: null,
        ], PageSupport::actor($request));

        return back()->with('status', "Duty {$duty->code} recorded from ".CarbonImmutable::parse((string) $data['effective_from'])->format('j M Y').'.');
    }

    public function end(Request $request, string $duty): RedirectResponse
    {
        /** @var array{effective_to: string} $data */
        $data = $request->validate(['effective_to' => ['required', 'date_format:Y-m-d']]);
        $this->duties->end($duty, $data['effective_to'], PageSupport::actor($request));

        return back()->with('status', 'Duty ends on '.CarbonImmutable::parse($data['effective_to'])->format('j M Y').'.');
    }
}
