<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Http\Requests;

use App\Modules\Insurance\Product\Domain\Enums\EarningMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProductVersionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'term_months' => ['required', 'integer', 'min:1', 'max:60'],
            'earning_method' => ['required', Rule::in(EarningMethod::supported())],
            // ASSUMPTION: A-4 (OPEN #4) — short-rate cancellation is not implemented; cancellations are pro-rata.
            'short_rate_table' => ['prohibited'],
            'tax_profile' => ['required', 'array'],
            'tax_profile.tax_type' => ['nullable', 'string', 'max:32'],
            'tax_profile.jurisdiction' => ['required_with:tax_profile.tax_type', 'nullable', 'string', 'max:32'],
            'tax_profile.inclusive' => ['required', 'boolean'],
            'tax_profile.refund_tax_on_cancellation' => ['sometimes', 'boolean'],
            'commission_plan_id' => ['nullable', 'uuid'],
            'posting_rule_set' => ['nullable', 'string', 'max:64'],
            'coverages' => ['sometimes', 'array'],
            'coverages.*.code' => ['required', 'string', 'max:32'],
            'coverages.*.name' => ['required', 'string', 'max:255'],
        ];
    }
}
