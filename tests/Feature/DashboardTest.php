<?php

namespace Tests\Feature;

use App\Actions\Subscriptions\CreateSubscriptionAction;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\UsageDailyAggregate;
use App\Services\DashboardAggregationService;
use App\Support\PlanPricingResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_top_customers_by_usage_this_month_are_ordered_descending(): void
    {
        $now = CarbonImmutable::parse('2026-09-20');
        $merchant = Merchant::factory()->create();

        $high = Customer::factory()->for($merchant)->create(['name' => 'High Usage Co']);
        $mid = Customer::factory()->for($merchant)->create(['name' => 'Mid Usage Co']);
        $low = Customer::factory()->for($merchant)->create(['name' => 'Low Usage Co']);

        UsageDailyAggregate::create(['customer_id' => $high->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-10', 'units_total' => 5000]);
        UsageDailyAggregate::create(['customer_id' => $mid->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-10', 'units_total' => 1000]);
        UsageDailyAggregate::create(['customer_id' => $low->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-10', 'units_total' => 400]);

        $top = app(DashboardAggregationService::class)->topCustomersByUsage($merchant, $now, 5);

        $this->assertSame(['High Usage Co', 'Mid Usage Co', 'Low Usage Co'], array_column($top, 'customer_name'));
        $this->assertSame(5000, $top[0]['units']);
    }

    public function test_churn_risk_flags_drops_over_fifty_percent_but_not_exactly_fifty(): void
    {
        // 10 days into a 30-day September - a deliberately mid-cycle date,
        // to prove the projection (not a raw partial-month total) drives
        // the comparison.
        $now = CarbonImmutable::parse('2026-09-10');
        $merchant = Merchant::factory()->create();

        $steady = Customer::factory()->for($merchant)->create(['name' => 'Steady']);
        $exactlyHalfRate = Customer::factory()->for($merchant)->create(['name' => 'Exactly Half Rate']);
        $bigDrop = Customer::factory()->for($merchant)->create(['name' => 'Big Drop']);
        $noBaseline = Customer::factory()->for($merchant)->create(['name' => 'No Baseline']);

        // Last month: a full 30-day cycle at 100 units/day for everyone with a baseline.
        foreach ([$steady, $exactlyHalfRate, $bigDrop] as $customer) {
            UsageDailyAggregate::create(['customer_id' => $customer->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-08-15', 'units_total' => 3000]);
        }

        // This month so far (days 1-10): rates chosen so the PROJECTED full-month total is what matters.
        // Steady: same 100/day rate -> projects back to 3000 -> 0% drop. A raw partial-total
        // comparison (1000 vs 3000) would wrongly look like a ~67% drop this early in the month.
        UsageDailyAggregate::create(['customer_id' => $steady->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-05', 'units_total' => 1000]);
        // Exactly half rate -> projects to exactly 1500 -> exactly -50%, not "more than" 50%.
        UsageDailyAggregate::create(['customer_id' => $exactlyHalfRate->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-05', 'units_total' => 500]);
        // 40 units/day -> projects to 1200 -> -60% drop.
        UsageDailyAggregate::create(['customer_id' => $bigDrop->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-05', 'units_total' => 400]);
        UsageDailyAggregate::create(['customer_id' => $noBaseline->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-05', 'units_total' => 100]); // no prior-month baseline

        $atRisk = app(DashboardAggregationService::class)->churnRiskCustomers($merchant, $now);

        $names = array_column($atRisk, 'customer_name');
        $this->assertContains('Big Drop', $names);
        $this->assertNotContains('Steady', $names); // flat usage must never be flagged, regardless of what day of the month it is checked
        $this->assertNotContains('Exactly Half Rate', $names); // exactly 50% is not "more than 50%"
        $this->assertNotContains('No Baseline', $names); // nothing to drop from
    }

    public function test_projected_overage_revenue_extrapolates_accrued_usage_across_the_cycle(): void
    {
        $now = CarbonImmutable::parse('2026-09-20');
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();

        $plan = Plan::factory()->for($merchant)->create([
            'included_units' => 3000,
            'overage_rate_micros' => 50000, // ₹0.05/unit
        ]);

        (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        // 4000 units accrued across the first 20 days of a 30-day cycle ->
        // run-rate of 200/day -> projected 6000 for the full cycle ->
        // 3000 units over the 3000 allowance -> 3000 x 0.05 = 150.00 = 15000 cents.
        UsageDailyAggregate::create(['customer_id' => $customer->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-10', 'units_total' => 4000]);

        $projected = app(DashboardAggregationService::class)->projectedOverageRevenue($merchant, $now);

        $this->assertSame(15000, $projected);
    }

    public function test_dashboard_endpoint_returns_data_scoped_to_the_authenticated_merchant(): void
    {
        [$apiKey, $plaintext] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKey->merchant)->create();
        UsageDailyAggregate::create([
            'customer_id' => $customer->id,
            'merchant_id' => $apiKey->merchant_id,
            'usage_date' => now()->toDateString(),
            'units_total' => 100,
        ]);

        $response = $this->withHeaders($this->authHeaders($plaintext))
            ->getJson("/api/merchants/{$apiKey->merchant_id}/dashboard");

        $response->assertOk();
        $response->assertJsonStructure(['merchant_id', 'cycle', 'total_usage_this_month', 'projected_overage_revenue_cents', 'top_customers', 'churn_risk_customers']);
        $this->assertSame($apiKey->merchant_id, $response->json('merchant_id'));
    }

    public function test_dashboard_endpoint_rejects_a_different_merchants_api_key(): void
    {
        [, $plaintext] = $this->createApiKey();
        $otherMerchant = Merchant::factory()->create();

        $response = $this->withHeaders($this->authHeaders($plaintext))
            ->getJson("/api/merchants/{$otherMerchant->id}/dashboard");

        $response->assertForbidden();
    }
}
