<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly'],
            'base_price_cents' => ['required', 'integer', 'min:0'],
            'included_units' => ['required', 'integer', 'min:0'],
            'overage_rate_micros' => ['required', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
