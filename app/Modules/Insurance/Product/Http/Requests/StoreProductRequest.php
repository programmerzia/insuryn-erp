<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProductRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('products', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'lob' => ['required', 'string', 'max:32'],
        ];
    }
}
