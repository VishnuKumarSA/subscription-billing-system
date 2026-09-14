<?php

namespace Tests\Feature;

use App\Actions\Usage\AggregateCustomerDailyUsageAction;
use App\Jobs\AggregateCustomerDailyUsageJob;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\UsageDailyAggregate;
use App\Models\UsageEvent;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AggregationTest extends TestCase
{
    public function test_sums_multiple_events_for_the_same_customer_and_day(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();

        // 100 raw usage events for one customer on one day.
        UsageEvent::factory()->count(100)->create([
            'customer_id' => $customer->id,
            'merchant_id' => $merchant->id,
            'usage_date' => '2026-09-10',
            'units' => 1,
        ]);

        (new AggregateCustomerDailyUsageAction)->execute($customer->id, '2026-09-10');

        // usage_date is a DATE column - assert through the model (whose
        // cast correctly re-parses the stored value) rather than a raw
        // assertDatabaseHas() date-string match, since drivers without a
        // native DATE type (e.g. SQLite, used in tests) may round-trip a
        // time component that a literal 'Y-m-d' string won't equal.
        $aggregate = UsageDailyAggregate::where('customer_id', $customer->id)->first();
        $this->assertSame('2026-09-10', $aggregate->usage_date->toDateString());
        $this->assertSame(100, $aggregate->units_total);
    }

    public function test_only_aggregates_the_requested_day(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();

        UsageEvent::factory()->create(['customer_id' => $customer->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-09', 'units' => 50]);
        UsageEvent::factory()->create(['customer_id' => $customer->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-10', 'units' => 30]);

        (new AggregateCustomerDailyUsageAction)->execute($customer->id, '2026-09-10');

        $aggregate = UsageDailyAggregate::where('customer_id', $customer->id)->whereDate('usage_date', '2026-09-10')->first();
        $this->assertSame(30, $aggregate->units_total);
    }

    public function test_rerunning_aggregation_is_safe_and_does_not_double_count(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();

        UsageEvent::factory()->count(5)->create([
            'customer_id' => $customer->id,
            'merchant_id' => $merchant->id,
            'usage_date' => '2026-09-10',
            'units' => 10,
        ]);

        $action = new AggregateCustomerDailyUsageAction;
        $action->execute($customer->id, '2026-09-10');
        $action->execute($customer->id, '2026-09-10');
        $action->execute($customer->id, '2026-09-10');

        $this->assertDatabaseCount('usage_daily_aggregates', 1);
        $this->assertSame(50, UsageDailyAggregate::first()->units_total);
    }

    public function test_aggregation_reflects_new_events_recorded_after_a_previous_run(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();

        UsageEvent::factory()->create(['customer_id' => $customer->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-10', 'units' => 10]);

        $action = new AggregateCustomerDailyUsageAction;
        $action->execute($customer->id, '2026-09-10');
        $this->assertSame(10, UsageDailyAggregate::first()->units_total);

        UsageEvent::factory()->create(['customer_id' => $customer->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-10', 'units' => 15]);
        $action->execute($customer->id, '2026-09-10');

        $this->assertDatabaseCount('usage_daily_aggregates', 1);
        $this->assertSame(25, UsageDailyAggregate::first()->units_total);
    }

    public function test_the_scheduled_command_dispatches_a_job_per_customer_and_processes_the_whole_roster(): void
    {
        config(['billing.aggregation_chunk_size' => 2]);

        $merchant = Merchant::factory()->create();
        $customers = Customer::factory()->count(5)->for($merchant)->create();

        foreach ($customers as $customer) {
            UsageEvent::factory()->create([
                'customer_id' => $customer->id,
                'merchant_id' => $merchant->id,
                'usage_date' => '2026-09-10',
                'units' => 42,
            ]);
        }

        // QUEUE_CONNECTION=sync in the test environment, so dispatched jobs
        // run inline - this exercises the real command -> job -> action path.
        Artisan::call('usage:aggregate-daily', ['date' => '2026-09-10']);

        $this->assertDatabaseCount('usage_daily_aggregates', 5);
        foreach ($customers as $customer) {
            $this->assertDatabaseHas('usage_daily_aggregates', [
                'customer_id' => $customer->id,
                'units_total' => 42,
            ]);
        }
    }

    public function test_job_is_idempotent_when_dispatched_twice(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        UsageEvent::factory()->create(['customer_id' => $customer->id, 'merchant_id' => $merchant->id, 'usage_date' => '2026-09-10', 'units' => 20]);

        (new AggregateCustomerDailyUsageJob($customer->id, '2026-09-10'))->handle(new AggregateCustomerDailyUsageAction);
        (new AggregateCustomerDailyUsageJob($customer->id, '2026-09-10'))->handle(new AggregateCustomerDailyUsageAction);

        $this->assertDatabaseCount('usage_daily_aggregates', 1);
        $this->assertSame(20, UsageDailyAggregate::first()->units_total);
    }
}
