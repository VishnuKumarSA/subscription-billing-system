<?php

namespace App\Actions\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPlanSegment;
use App\Support\PlanPricingResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The mid-cycle plan-change flow: close the currently open segment and open
 * a new one, snapshotting the new plan's pricing at the moment of change.
 *
 * Usage events/aggregates are never touched here - they stay a plain
 * customer+date+units record. The association between a unit of usage and
 * "which plan/rate applied" is reconstructed later, at invoice time, by
 * matching usage_date against this segment's date window. That keeps the
 * write-hot usage tables completely decoupled from plan-change bookkeeping.
 */
class ChangeSubscriptionPlanAction
{
    public function __construct(private readonly PlanPricingResolver $pricingResolver) {}

    public function execute(Subscription $subscription, Plan $newPlan, CarbonImmutable $effectiveDate): SubscriptionPlanSegment
    {
        return DB::transaction(function () use ($subscription, $newPlan, $effectiveDate) {
            /** @var Subscription $locked */
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $openSegment = $locked->segments()->whereNull('ends_on')->first();

            if ($openSegment && $effectiveDate->lessThan(CarbonImmutable::parse($openSegment->starts_on))) {
                throw new \InvalidArgumentException('effective_date cannot be before the current segment started.');
            }

            if ($openSegment) {
                $openSegment->update(['ends_on' => $effectiveDate->toDateString()]);
            }

            $pricing = $this->pricingResolver->resolve($newPlan->id);

            $newSegment = $locked->segments()->create([
                'plan_id' => $newPlan->id,
                'starts_on' => $effectiveDate->toDateString(),
                'ends_on' => null,
                'base_price_cents_snapshot' => $pricing->basePriceCents,
                'included_units_snapshot' => $pricing->includedUnits,
                'overage_rate_micros_snapshot' => $pricing->overageRateMicros,
                'currency_snapshot' => $pricing->currency,
            ]);

            $locked->update(['current_plan_id' => $newPlan->id]);

            return $newSegment;
        });
    }
}
