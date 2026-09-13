<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class QuotePolicyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')],
            'policyholder_party_id' => ['required', 'uuid', Rule::exists('parties', 'id')],
            'agent_id' => ['nullable', 'uuid', Rule::exists('agents', 'id')],
            'inception' => ['required', 'date_format:Y-m-d'],
            'premium_minor' => ['required', 'integer', 'min:1'],
            'installment_count' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'payers' => ['sometimes', 'array', 'max:10'], 'payers.*.party_id' => ['required', 'uuid'], 'payers.*.share_bp' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
