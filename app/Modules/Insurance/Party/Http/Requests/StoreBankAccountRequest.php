<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBankAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:255'],
            'account_no' => ['required', 'string', 'regex:/^[0-9][0-9 \-]{3,40}$/'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
