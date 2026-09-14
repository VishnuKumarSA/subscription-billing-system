<?php

namespace App\Actions\Subscriptions;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\PlanPricingResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opens a subscription and its first plan segment together. Billing cycles
 * are calendar-month aligned (1st to 1st) regardless of signup date -
 * a subscription starting mid-month gets a full-length first cycle
 * (period_start/period_end are the calendar month's boundaries) with the
 * segment itself starting on the actual join date, so proration naturally
 * falls out of the normal segment-overlap math instead of needing a
 * separate "first invoice" code path.
 */
class CreateSubscriptionAction
{
    public function __construct(private readonly PlanPricingResolver $pricingResolver) {}

    public function execute(Customer $customer, Plan $plan, CarbonImmutable $startDate): Subscription
    {
        return DB::transaction(function () use ($customer, $plan, $startDate) {
            $periodStart = $startDate->startOfMonth();
            $periodEnd = $periodStart->addMonthNoOverflow();

            $subscription = Subscription::create([
                'customer_id' => $customer->id,
                'merchant_id' => $customer->merchant_id,
                'current_plan_id' => $plan->id,
                'status' => 'active',
                'current_period_start' => $periodStart->toDateString(),
                'current_period_end' => $periodEnd->toDateString(),
            ]);

            $pricing = $this->pricingResolver->resolve($plan->id);

            $subscription->segments()->create([
                'plan_id' => $plan->id,
                'starts_on' => $startDate->toDateString(),
                'ends_on' => null,
                'base_price_cents_snapshot' => $pricing->basePriceCents,
                'included_units_snapshot' => $pricing->includedUnits,
                'overage_rate_micros_snapshot' => $pricing->overageRateMicros,
                'currency_snapshot' => $pricing->currency,
            ]);

            return $subscription->fresh();
        });
    }
}
