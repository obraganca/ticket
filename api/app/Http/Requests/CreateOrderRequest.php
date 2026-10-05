<?php

namespace App\Http\Requests;

use App\Rules\ValidCpf;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $quantity = max(1, (int) $this->input('quantity', 1));

        return [
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('tickets.max_quantity_per_order', 6)],
            'buyer.name' => ['required', 'string', 'max:255'],
            'buyer.email' => ['required', 'email', 'max:255'],
            'buyer.document' => ['required', 'string', new ValidCpf],

            // Titulares por ingresso são opcionais: sem eles, o titular é o comprador.
            'tickets' => ['sometimes', 'nullable', 'array', 'size:'.$quantity],
            'tickets.*.name' => ['required_with:tickets', 'string', 'max:255'],
            'tickets.*.email' => ['required_with:tickets', 'email', 'max:255'],
        ];
    }
}
