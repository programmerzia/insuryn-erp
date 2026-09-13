<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAgentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'parent_agent_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('producers', 'id')],
            'branch_id' => ['sometimes', 'required', 'uuid', Rule::exists('branches', 'id')],
            'commission_plan_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'required', 'in:active,suspended,inactive'], // `inactive` is the Phase 1 word for suspended
        ];
    }
}
