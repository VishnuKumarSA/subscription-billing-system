<?php

namespace Tests\Feature;

use App\Actions\Billing\ChangeSubscriptionPlanAction;
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

class PlanChangeTest extends TestCase
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

    private function invoiceAction(): GenerateInvoiceAction
    {
        return new GenerateInvoiceAction(new ProrationCalculator, new OverageCalculator);
    }

    /**
     * September 1-14: Basic plan. September 15-30: Pro plan (both within a
     * Sept 1 - Oct 1, 30-day cycle). Usage recorded in each window must be
     * billed at that window's own plan/rate, and the base price prorated
     * across both segments.
     */
    public function test_mid_cycle_upgrade_produces_two_correctly_priced_line_items(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();

        $basic = Plan::factory()->for($merchant)->create([
            'name' => 'Basic', 'base_price_cents' => 100000, 'included_units' => 10000, 'overage_rate_micros' => 100000,
        ]);
        $pro = Plan::factory()->for($merchant)->create([
            'name' => 'Pro', 'base_price_cents' => 400000, 'included_units' => 40000, 'overage_rate_micros' => 40000,
        ]);

        $resolver = app(PlanPricingResolver::class);
        $subscription = (new CreateSubscriptionAction($resolver))->execute($customer, $basic, CarbonImmutable::parse('2026-09-01'));

        // Usage while still on Basic.
        $this->recordAndAggregate($customer, '2026-09-05', 12000); // 2000 over Basic's 10000 allowance

        (new ChangeSubscriptionPlanAction($resolver))->execute($subscription, $pro, CarbonImmutable::parse('2026-09-15'));

        // Usage while on Pro.
        $this->recordAndAggregate($customer, '2026-09-20', 45000); // 5000 over Pro's 40000 allowance

        $invoice = $this->invoiceAction()->execute($subscription->fresh());

        $this->assertCount(2, $invoice->lineItems);

        $basicLine = $invoice->lineItems->firstWhere('plan_name_snapshot', 'Basic');
        $proLine = $invoice->lineItems->firstWhere('plan_name_snapshot', 'Pro');

        // Basic segment: Sept 1-15 = 14 days out of a 30-day cycle. The
        // included allowance is prorated by the same day-fraction as the
        // base price (10000 x 14/30 = 4667) - otherwise a plan change would
        // grant a full month's allowance for a partial-month segment.
        $this->assertSame(14, (int) round($basicLine->day_fraction * 30));
        $this->assertSame(12000, $basicLine->usage_units);
        $this->assertSame(4667, $basicLine->prorated_included_units);
        $this->assertSame(7333, $basicLine->overage_units); // 12000 - 4667, usage attributed only to the Basic window

        // Pro segment: Sept 15-30 = 16 days out of a 30-day cycle -> prorated allowance 40000 x 16/30 = 21333.
        $this->assertSame(16, (int) round($proLine->day_fraction * 30));
        $this->assertSame(45000, $proLine->usage_units);
        $this->assertSame(21333, $proLine->prorated_included_units);
        $this->assertSame(23667, $proLine->overage_units); // 45000 - 21333, usage attributed only to the Pro window

        $expectedTotal = $basicLine->line_total_cents + $proLine->line_total_cents;
        $this->assertSame($expectedTotal, $invoice->total_cents);
    }

    public function test_mid_cycle_downgrade_bills_each_segment_at_its_own_rate(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();

        $pro = Plan::factory()->for($merchant)->create([
            'name' => 'Pro', 'base_price_cents' => 400000, 'included_units' => 40000, 'overage_rate_micros' => 40000,
        ]);
        $basic = Plan::factory()->for($merchant)->create([
            'name' => 'Basic', 'base_price_cents' => 100000, 'included_units' => 10000, 'overage_rate_micros' => 100000,
        ]);

        $resolver = app(PlanPricingResolver::class);
        $subscription = (new CreateSubscriptionAction($resolver))->execute($customer, $pro, CarbonImmutable::parse('2026-09-01'));

        $this->recordAndAggregate($customer, '2026-09-05', 20000);

        (new ChangeSubscriptionPlanAction($resolver))->execute($subscription, $basic, CarbonImmutable::parse('2026-09-15'));

        $this->recordAndAggregate($customer, '2026-09-20', 8000);

        $invoice = $this->invoiceAction()->execute($subscription->fresh());

        $this->assertCount(2, $invoice->lineItems);
        $proLine = $invoice->lineItems->firstWhere('plan_name_snapshot', 'Pro');
        $basicLine = $invoice->lineItems->firstWhere('plan_name_snapshot', 'Basic');

        // Pro segment: Sept 1-15 = 14 days -> prorated allowance 40000 x 14/30 = 18667.
        $this->assertSame(20000, $proLine->usage_units);
        $this->assertSame(18667, $proLine->prorated_included_units);
        $this->assertSame(1333, $proLine->overage_units); // 20000 - 18667

        // Basic segment: Sept 15-30 = 16 days -> prorated allowance 10000 x 16/30 = 5333.
        $this->assertSame(8000, $basicLine->usage_units);
        $this->assertSame(5333, $basicLine->prorated_included_units);
        $this->assertSame(2667, $basicLine->overage_units); // 8000 - 5333
    }

    public function test_multiple_plan_changes_in_one_cycle_produce_one_line_item_per_segment(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();

        $planA = Plan::factory()->for($merchant)->create(['name' => 'A']);
        $planB = Plan::factory()->for($merchant)->create(['name' => 'B']);
        $planC = Plan::factory()->for($merchant)->create(['name' => 'C']);

        $resolver = app(PlanPricingResolver::class);
        $subscription = (new CreateSubscriptionAction($resolver))->execute($customer, $planA, CarbonImmutable::parse('2026-09-01'));

        $changeAction = new ChangeSubscriptionPlanAction($resolver);
        $changeAction->execute($subscription, $planB, CarbonImmutable::parse('2026-09-10'));
        $changeAction->execute($subscription, $planC, CarbonImmutable::parse('2026-09-20'));

        $invoice = $this->invoiceAction()->execute($subscription->fresh());

        $this->assertCount(3, $invoice->lineItems);
        $this->assertEqualsCanonicalizing(
            ['A', 'B', 'C'],
            $invoice->lineItems->pluck('plan_name_snapshot')->all(),
        );
    }

    public function test_a_same_day_double_change_does_not_bill_the_superseded_segment(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();

        $planA = Plan::factory()->for($merchant)->create(['name' => 'A']);
        $planB = Plan::factory()->for($merchant)->create(['name' => 'B']);
        $planC = Plan::factory()->for($merchant)->create(['name' => 'C']);

        $resolver = app(PlanPricingResolver::class);
        $subscription = (new CreateSubscriptionAction($resolver))->execute($customer, $planA, CarbonImmutable::parse('2026-09-01'));

        $changeAction = new ChangeSubscriptionPlanAction($resolver);
        // Two changes both effective on the 10th - plan B's segment (10th-10th) is zero days.
        $changeAction->execute($subscription, $planB, CarbonImmutable::parse('2026-09-10'));
        $changeAction->execute($subscription, $planC, CarbonImmutable::parse('2026-09-10'));

        $invoice = $this->invoiceAction()->execute($subscription->fresh());

        // Only the original A segment (1st-10th) and the C segment (10th-30th) bill - B never billed for a day.
        $this->assertCount(2, $invoice->lineItems);
        $this->assertEqualsCanonicalizing(
            ['A', 'C'],
            $invoice->lineItems->pluck('plan_name_snapshot')->all(),
        );
    }
}
