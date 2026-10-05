<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price_cents' => ['sometimes', 'required', 'integer', 'min:0'],
            'total' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }
}
