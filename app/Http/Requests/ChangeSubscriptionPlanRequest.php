<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subscription = $this->route('subscription');

        return $this->user()?->merchant_id === $subscription->merchant_id;
    }

    public function rules(): array
    {
        $merchantId = $this->user()->merchant_id;

        return [
            'new_plan_id' => [
                'required',
                'integer',
                Rule::exists('plans', 'id')->where(fn ($query) => $query->where('merchant_id', $merchantId)),
            ],
            'effective_date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
