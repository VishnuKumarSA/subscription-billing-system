<?php

namespace App\Http\Resources;

use App\Models\InvoiceLineItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceLineItem */
class InvoiceLineItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'plan_id' => $this->plan_id,
            'plan_name' => $this->plan_name_snapshot,
            'segment_starts_on' => $this->segment_starts_on?->toDateString(),
            'segment_ends_on' => $this->segment_ends_on?->toDateString(),
            'day_fraction' => round((float) $this->day_fraction, 6),
            'base_price_cents_snapshot' => $this->base_price_cents_snapshot,
            'prorated_base_cents' => $this->prorated_base_cents,
            'included_units_snapshot' => $this->included_units_snapshot,
            'prorated_included_units' => $this->prorated_included_units,
            'usage_units' => $this->usage_units,
            'overage_units' => $this->overage_units,
            'overage_rate_micros_snapshot' => $this->overage_rate_micros_snapshot,
            'overage_amount_cents' => $this->overage_amount_cents,
            'line_total_cents' => $this->line_total_cents,
        ];
    }
}
