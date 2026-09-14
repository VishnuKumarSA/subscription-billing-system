<?php

namespace App\Http\Resources;

use App\Models\SubscriptionPlanSegment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionPlanSegment */
class SubscriptionPlanSegmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'plan_name' => $this->plan?->name,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'base_price_cents_snapshot' => $this->base_price_cents_snapshot,
            'included_units_snapshot' => $this->included_units_snapshot,
            'overage_rate_micros_snapshot' => $this->overage_rate_micros_snapshot,
            'currency_snapshot' => $this->currency_snapshot,
        ];
    }
}
