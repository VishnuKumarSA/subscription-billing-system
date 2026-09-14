<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'name' => $this->name,
            'billing_cycle' => $this->billing_cycle,
            'base_price_cents' => $this->base_price_cents,
            'included_units' => $this->included_units,
            'overage_rate_micros' => $this->overage_rate_micros,
            'overage_rate_per_unit' => round($this->overage_rate_micros / 1_000_000, 6),
            'currency' => $this->currency,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
