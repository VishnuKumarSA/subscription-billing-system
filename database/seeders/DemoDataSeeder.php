<?php

namespace Database\Seeders;

use App\Actions\Billing\ChangeSubscriptionPlanAction;
use App\Actions\Billing\GenerateInvoiceAction;
use App\Actions\Subscriptions\CreateSubscriptionAction;
use App\Actions\Usage\AggregateCustomerDailyUsageAction;
use App\Actions\Usage\RecordUsageEventAction;
use App\Billing\OverageCalculator;
use App\Billing\ProrationCalculator;
use App\DTOs\UsageEventData;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\PlanPricingResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Builds a realistic dataset entirely through the real Actions (not raw
 * inserts), so seeding exercises the same idempotency/aggregation/billing
 * code paths the tests and the API do. Dates are relative to "now" rather
 * than hardcoded, so the demo still looks like "this month" / "last month"
 * whenever it's actually run.
 *
 * Covers every scenario the assignment brief asks to be able to demo:
 * multiple merchants, multiple plans per merchant, an overage customer, a
 * churn-risk (>50% MoM drop) customer, a mid-cycle plan-change customer,
 * and at least one already-generated invoice.
 */
class DemoDataSeeder extends Seeder
{
    private PlanPricingResolver $pricingResolver;

    private RecordUsageEventAction $recordUsage;

    private AggregateCustomerDailyUsageAction $aggregate;

    private ChangeSubscriptionPlanAction $changePlan;

    private GenerateInvoiceAction $generateInvoice;

    public function __construct()
    {
        $this->pricingResolver = app(PlanPricingResolver::class);
        $this->recordUsage = new RecordUsageEventAction;
        $this->aggregate = new AggregateCustomerDailyUsageAction;
        $this->changePlan = new ChangeSubscriptionPlanAction($this->pricingResolver);
        $this->generateInvoice = new GenerateInvoiceAction(new ProrationCalculator, new OverageCalculator);
    }

    public function run(): void
    {
        $now = CarbonImmutable::now();
        $thisMonthStart = $now->startOfMonth();
        $lastMonthStart = $thisMonthStart->subMonthNoOverflow();

        [$acme, $acmeKey] = $this->makeMerchantWithApiKey('Acme Corp');

        $starter = Plan::create([
            'merchant_id' => $acme->id, 'name' => 'Starter', 'billing_cycle' => 'monthly',
            'base_price_cents' => 100000, 'included_units' => 10000, 'overage_rate_micros' => 100000, 'currency' => 'INR',
        ]);
        $growth = Plan::create([
            'merchant_id' => $acme->id, 'name' => 'Growth', 'billing_cycle' => 'monthly',
            'base_price_cents' => 300000, 'included_units' => 250000, 'overage_rate_micros' => 50000, 'currency' => 'INR',
        ]);
        $pro = Plan::create([
            'merchant_id' => $acme->id, 'name' => 'Pro', 'billing_cycle' => 'monthly',
            'base_price_cents' => 600000, 'included_units' => 500000, 'overage_rate_micros' => 40000, 'currency' => 'INR',
        ]);

        // --- Top-usage customers on Growth, all under their allowance (matches the wireframe's "Top 5" panel shape) ---
        $this->seedSteadyCustomer($acme, $growth, 'Beta Retail Pvt Ltd', $thisMonthStart, $lastMonthStart, $now, dailyUnits: 7600); // ~91% of 250,000
        $this->seedSteadyCustomer($acme, $growth, 'Craft Foods Co.', $thisMonthStart, $lastMonthStart, $now, dailyUnits: 6500);   // ~78%
        $this->seedSteadyCustomer($acme, $growth, 'Delta Mart', $thisMonthStart, $lastMonthStart, $now, dailyUnits: 5800);        // ~70%

        // --- Churn-risk customers: high last month, down >50% this month (matches the wireframe's churn panel) ---
        $this->seedChurnRiskCustomer($acme, $growth, 'Nova Traders', $thisMonthStart, $lastMonthStart, $now, lastMonthDailyUnits: 6000, dropRatio: 0.62);
        $this->seedChurnRiskCustomer($acme, $growth, 'QuickMart', $thisMonthStart, $lastMonthStart, $now, lastMonthDailyUnits: 5000, dropRatio: 0.55);

        // --- Overage customer: on Starter (10,000 included), usage well past the allowance ---
        $this->seedOverageCustomer($acme, $starter, 'Orbit Solutions', $thisMonthStart, $lastMonthStart, $now);

        // --- Mid-cycle plan-change customer: Starter for the first part of THIS month, upgraded to Growth partway through ---
        $this->seedMidCyclePlanChangeCustomer($acme, $starter, $growth, $thisMonthStart, $lastMonthStart, $now);

        // --- A second merchant, for demonstrating tenant isolation ---
        [$globex, $globexKey] = $this->makeMerchantWithApiKey('Globex Inc');
        $globexPlan = Plan::create([
            'merchant_id' => $globex->id, 'name' => 'Standard', 'billing_cycle' => 'monthly',
            'base_price_cents' => 200000, 'included_units' => 20000, 'overage_rate_micros' => 80000, 'currency' => 'INR',
        ]);
        $this->seedSteadyCustomer($globex, $globexPlan, 'Initech LLC', $thisMonthStart, $lastMonthStart, $now, dailyUnits: 400);

        $this->command?->info('');
        $this->command?->info('Demo API keys (send as the X-API-Key header):');
        $this->command?->info("  Acme Corp  (merchant #{$acme->id}): {$acmeKey}");
        $this->command?->info("  Globex Inc (merchant #{$globex->id}): {$globexKey}");
        $this->command?->info('');
    }

    /** @return array{0: Merchant, 1: string} */
    private function makeMerchantWithApiKey(string $name): array
    {
        $merchant = Merchant::create(['name' => $name]);
        $pair = ApiKey::generatePlaintextAndHash();
        ApiKey::create([
            'merchant_id' => $merchant->id,
            'name' => 'Demo key',
            'key_hash' => $pair['hash'],
        ]);

        return [$merchant, $pair['key']];
    }

    private function openSubscription(Customer $customer, Plan $plan, CarbonImmutable $startDate): Subscription
    {
        return (new CreateSubscriptionAction($this->pricingResolver))->execute($customer, $plan, $startDate);
    }

    private function recordAndAggregate(Customer $customer, CarbonImmutable $date, int $units): void
    {
        if ($units <= 0) {
            return;
        }

        $this->recordUsage->execute(new UsageEventData(
            customerId: $customer->id,
            merchantId: $customer->merchant_id,
            usageDate: $date->toDateString(),
            units: $units,
            idempotencyKey: (string) Str::uuid(),
        ));

        $this->aggregate->execute($customer->id, $date->toDateString());
    }

    private function simulateRange(Customer $customer, CarbonImmutable $start, CarbonImmutable $endExclusive, int $baseUnits): void
    {
        for ($date = $start; $date->lessThan($endExclusive); $date = $date->addDay()) {
            // +/-15% daily variance so the usage trend isn't a flat line.
            $units = (int) round($baseUnits * (1 + (mt_rand(-15, 15) / 100)));
            $this->recordAndAggregate($customer, $date, $units);
        }
    }

    private function seedSteadyCustomer(
        Merchant $merchant, Plan $plan, string $name,
        CarbonImmutable $thisMonthStart, CarbonImmutable $lastMonthStart, CarbonImmutable $now,
        int $dailyUnits,
    ): Customer {
        $customer = Customer::factory()->for($merchant)->create(['name' => $name]);

        $subscription = $this->openSubscription($customer, $plan, $lastMonthStart);
        $this->simulateRange($customer, $lastMonthStart, $thisMonthStart, $dailyUnits);
        $this->generateInvoice->execute($subscription->fresh());

        $this->simulateRange($customer, $thisMonthStart, $now->addDay(), $dailyUnits);

        return $customer;
    }

    private function seedChurnRiskCustomer(
        Merchant $merchant, Plan $plan, string $name,
        CarbonImmutable $thisMonthStart, CarbonImmutable $lastMonthStart, CarbonImmutable $now,
        int $lastMonthDailyUnits, float $dropRatio,
    ): Customer {
        $customer = Customer::factory()->for($merchant)->create(['name' => $name]);

        $subscription = $this->openSubscription($customer, $plan, $lastMonthStart);
        $this->simulateRange($customer, $lastMonthStart, $thisMonthStart, $lastMonthDailyUnits);
        $this->generateInvoice->execute($subscription->fresh());

        $thisMonthDailyUnits = (int) round($lastMonthDailyUnits * (1 - $dropRatio));
        $this->simulateRange($customer, $thisMonthStart, $now->addDay(), $thisMonthDailyUnits);

        return $customer;
    }

    private function seedOverageCustomer(
        Merchant $merchant, Plan $plan, string $name,
        CarbonImmutable $thisMonthStart, CarbonImmutable $lastMonthStart, CarbonImmutable $now,
    ): Customer {
        $customer = Customer::factory()->for($merchant)->create(['name' => $name]);

        $subscription = $this->openSubscription($customer, $plan, $lastMonthStart);
        // 500/day x ~30 days ≈ 15,000, comfortably past Starter's 10,000 allowance.
        $this->simulateRange($customer, $lastMonthStart, $thisMonthStart, 500);
        $this->generateInvoice->execute($subscription->fresh());

        $this->simulateRange($customer, $thisMonthStart, $now->addDay(), 500);

        return $customer;
    }

    private function seedMidCyclePlanChangeCustomer(
        Merchant $merchant, Plan $originalPlan, Plan $newPlan,
        CarbonImmutable $thisMonthStart, CarbonImmutable $lastMonthStart, CarbonImmutable $now,
    ): Customer {
        $customer = Customer::factory()->for($merchant)->create(['name' => 'Helix Systems']);

        $subscription = $this->openSubscription($customer, $originalPlan, $lastMonthStart);
        $this->simulateRange($customer, $lastMonthStart, $thisMonthStart, 300);
        $this->generateInvoice->execute($subscription->fresh());

        // Change plan partway into the current (still open) cycle, leaving
        // enough days on each side to show usage in both segments.
        $daysIntoMonth = max(1, $thisMonthStart->diffInDays($now));
        $changeOffset = min(6, max(1, intdiv($daysIntoMonth, 2)));
        $changeDate = $thisMonthStart->addDays($changeOffset);
        if ($changeDate->greaterThan($now)) {
            $changeDate = $now;
        }

        $this->simulateRange($customer, $thisMonthStart, $changeDate, 350);

        $this->changePlan->execute($subscription->fresh(), $newPlan, $changeDate);

        $this->simulateRange($customer, $changeDate, $now->addDay(), 4000);

        return $customer;
    }
}
