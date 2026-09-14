<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'merchant_id' => $this->merchant_id,
            'current_plan' => new PlanResource($this->whenLoaded('currentPlan')),
            'status' => $this->status,
            'current_period_start' => $this->current_period_start?->toDateString(),
            'current_period_end' => $this->current_period_end?->toDateString(),
            'segments' => SubscriptionPlanSegmentResource::collection($this->whenLoaded('segments')),
        ];
    }
}
