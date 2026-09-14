<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'customer_id' => $this->customer_id,
            'merchant_id' => $this->merchant_id,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'status' => $this->status,
            'subtotal_cents' => $this->subtotal_cents,
            'total_cents' => $this->total_cents,
            'currency' => $this->currency,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'line_items' => InvoiceLineItemResource::collection($this->whenLoaded('lineItems')),
        ];
    }
}
