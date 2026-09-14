<?php

namespace Tests\Feature;

use App\Actions\Billing\GenerateInvoiceAction;
use App\Actions\Subscriptions\CreateSubscriptionAction;
use App\Actions\Usage\AggregateCustomerDailyUsageAction;
use App\Billing\OverageCalculator;
use App\Billing\ProrationCalculator;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\UsageEvent;
use App\Support\PlanPricingResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BillingCalculationTest extends TestCase
{
    private function recordAndAggregate(Customer $customer, string $date, int $units): void
    {
        UsageEvent::factory()->create([
            'customer_id' => $customer->id,
            'merchant_id' => $customer->merchant_id,
            'usage_date' => $date,
            'units' => $units,
        ]);

        (new AggregateCustomerDailyUsageAction)->execute($customer->id, $date);
    }

    public function test_full_cycle_with_usage_above_allowance_matches_the_brief_worked_example(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create([
            'base_price_cents' => 300000, // ₹3000
            'included_units' => 50000,
            'overage_rate_micros' => 50000, // ₹0.05/unit
        ]);

        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        $this->recordAndAggregate($customer, '2026-09-15', 60000);

        $invoice = (new GenerateInvoiceAction(new ProrationCalculator, new OverageCalculator))
            ->execute($subscription->fresh());

        $this->assertSame(350000, $invoice->total_cents); // 300000 base + 50000 overage
        $this->assertCount(1, $invoice->lineItems);
        $this->assertSame(10000, $invoice->lineItems->first()->overage_units);
    }

    public function test_no_overage_when_usage_is_below_allowance(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create([
            'base_price_cents' => 300000,
            'included_units' => 50000,
            'overage_rate_micros' => 50000,
        ]);

        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        $this->recordAndAggregate($customer, '2026-09-15', 40000);

        $invoice = (new GenerateInvoiceAction(new ProrationCalculator, new OverageCalculator))
            ->execute($subscription->fresh());

        $this->assertSame(300000, $invoice->total_cents);
        $this->assertSame(0, $invoice->lineItems->first()->overage_units);
    }

    public function test_usage_exactly_at_the_allowance_produces_no_overage(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create([
            'base_price_cents' => 300000,
            'included_units' => 50000,
            'overage_rate_micros' => 50000,
        ]);

        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        $this->recordAndAggregate($customer, '2026-09-15', 50000);

        $invoice = (new GenerateInvoiceAction(new ProrationCalculator, new OverageCalculator))
            ->execute($subscription->fresh());

        $this->assertSame(300000, $invoice->total_cents);
        $this->assertSame(0, $invoice->lineItems->first()->overage_units);
    }

    public function test_zero_usage_bills_only_the_base_price(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create([
            'base_price_cents' => 300000,
            'included_units' => 50000,
            'overage_rate_micros' => 50000,
        ]);

        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        $invoice = (new GenerateInvoiceAction(new ProrationCalculator, new OverageCalculator))
            ->execute($subscription->fresh());

        $this->assertSame(300000, $invoice->total_cents);
        $this->assertSame(0, $invoice->lineItems->first()->usage_units);
    }

    public function test_mid_cycle_subscription_start_prorates_the_base_price(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create([
            'base_price_cents' => 300000,
            'included_units' => 50000,
            'overage_rate_micros' => 50000,
        ]);

        // Cycle is Sept 1 - Oct 1 (30 days); subscription starts on the 16th
        // -> 15 remaining days -> exactly half the base price.
        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-16'));

        $invoice = (new GenerateInvoiceAction(new ProrationCalculator, new OverageCalculator))
            ->execute($subscription->fresh());

        $lineItem = $invoice->lineItems->first();
        $this->assertSame(150000, $lineItem->prorated_base_cents);
        $this->assertSame(25000, $lineItem->prorated_included_units);
        $this->assertEqualsWithDelta(0.5, $lineItem->day_fraction, 0.0001);
    }

    public function test_invoice_generation_advances_the_subscription_to_the_next_cycle(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create();

        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        (new GenerateInvoiceAction(new ProrationCalculator, new OverageCalculator))
            ->execute($subscription->fresh());

        $subscription->refresh();
        $this->assertSame('2026-10-01', $subscription->current_period_start->toDateString());
        $this->assertSame('2026-11-01', $subscription->current_period_end->toDateString());
    }
}
