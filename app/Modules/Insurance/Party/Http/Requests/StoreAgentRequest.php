<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAgentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'party_id' => ['required', 'uuid', Rule::exists('parties', 'id'), Rule::unique('producers', 'party_id')],
            'code' => ['required', 'string', 'max:32', Rule::unique('producers', 'code')],
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'parent_agent_id' => ['nullable', 'uuid', Rule::exists('producers', 'id')],
            'commission_plan_id' => ['nullable', 'uuid'],
        ];
    }
}
