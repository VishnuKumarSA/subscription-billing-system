<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $merchantId = $this->user()->merchant_id;

        return [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('merchant_id', $merchantId)),
                // A customer must have at most one active subscription - two
                // would both be picked up by the billing scheduler and double-bill.
                // Upgrading/downgrading an existing subscription goes through
                // POST /subscriptions/{id}/change-plan instead of creating a new one.
                Rule::unique('subscriptions', 'customer_id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'plan_id' => [
                'required',
                'integer',
                Rule::exists('plans', 'id')->where(fn ($query) => $query->where('merchant_id', $merchantId)),
            ],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
