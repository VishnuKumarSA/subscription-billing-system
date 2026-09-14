<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->merchant_id === $this->route('plan')->merchant_id;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly'],
            'base_price_cents' => ['sometimes', 'integer', 'min:0'],
            'included_units' => ['sometimes', 'integer', 'min:0'],
            'overage_rate_micros' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
