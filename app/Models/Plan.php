<?php

namespace App\Models;

use App\DTOs\PlanPricingData;
use App\Support\PlanPricingResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plan extends Model
{
    use HasFactory;

    /**
     * Explicit cache invalidation on every write - the TTL in
     * PlanPricingResolver is a safety net, not the primary mechanism.
     */
    protected static function booted(): void
    {
        static::saved(fn (Plan $plan) => app(PlanPricingResolver::class)->forget($plan->id));
        static::deleted(fn (Plan $plan) => app(PlanPricingResolver::class)->forget($plan->id));
    }

    protected $fillable = [
        'merchant_id',
        'name',
        'billing_cycle',
        'base_price_cents',
        'included_units',
        'overage_rate_micros',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'base_price_cents' => 'integer',
            'included_units' => 'integer',
            'overage_rate_micros' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function toPricingData(): PlanPricingData
    {
        return new PlanPricingData(
            planId: $this->id,
            planName: $this->name,
            basePriceCents: $this->base_price_cents,
            includedUnits: $this->included_units,
            overageRateMicros: $this->overage_rate_micros,
            currency: $this->currency,
        );
    }
}
