<?php

namespace App\Support;

use App\DTOs\PlanPricingData;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

/**
 * The single place plan pricing is read for "what does this plan currently
 * cost" purposes (display, and sourcing a NEW subscription_plan_segments
 * snapshot at plan-change time).
 *
 * It is deliberately never used by invoice generation, which always reads
 * the price already frozen onto a segment - that separation is what makes
 * a stale cache entry structurally incapable of producing a wrong bill.
 */
class PlanPricingResolver
{
    public function resolve(int $planId): PlanPricingData
    {
        $ttl = now()->addMinutes((int) config('billing.plan_pricing_cache_ttl_minutes'));

        $data = Cache::remember(self::cacheKey($planId), $ttl, function () use ($planId) {
            return Plan::findOrFail($planId)->toPricingData()->toArray();
        });

        return PlanPricingData::fromArray($data);
    }

    public function forget(int $planId): void
    {
        Cache::forget(self::cacheKey($planId));
    }

    public static function cacheKey(int $planId): string
    {
        return "plan:{$planId}:pricing";
    }
}
