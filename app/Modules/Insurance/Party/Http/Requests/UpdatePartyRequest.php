<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Http\Requests;

use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePartyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['required', Rule::enum(PartyRoleType::class)],
        ];
    }
}
