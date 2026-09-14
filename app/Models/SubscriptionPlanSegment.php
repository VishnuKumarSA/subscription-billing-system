<?php

namespace App\Models;

use App\DTOs\PlanPricingData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPlanSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'plan_id',
        'starts_on',
        'ends_on',
        'base_price_cents_snapshot',
        'included_units_snapshot',
        'overage_rate_micros_snapshot',
        'currency_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'base_price_cents_snapshot' => 'integer',
            'included_units_snapshot' => 'integer',
            'overage_rate_micros_snapshot' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function pricingSnapshot(): PlanPricingData
    {
        return new PlanPricingData(
            planId: $this->plan_id,
            planName: $this->plan?->name ?? '',
            basePriceCents: $this->base_price_cents_snapshot,
            includedUnits: $this->included_units_snapshot,
            overageRateMicros: $this->overage_rate_micros_snapshot,
            currency: $this->currency_snapshot,
        );
    }
}
