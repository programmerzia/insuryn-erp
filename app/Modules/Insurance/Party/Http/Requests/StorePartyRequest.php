<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Http\Requests;

use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePartyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(PartyKind::class)],
            'display_name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', Rule::enum(PartyRoleType::class)],
        ];
    }
}
