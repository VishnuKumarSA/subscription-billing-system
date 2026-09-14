<?php

namespace App\Console\Commands;

use App\Jobs\AggregateCustomerDailyUsageJob;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Nightly aggregation entrypoint. Chunks the CUSTOMER roster (not a single
 * customer's raw events) at config('billing.aggregation_chunk_size') and
 * dispatches one small, idempotent job per customer. Each job then runs a
 * single indexed SUM query scoped to that customer/day - see
 * AggregateCustomerDailyUsageAction and the README "Queue architecture"
 * section for why chunking the roster (not the event log) is the design
 * that keeps this cheap regardless of usage_events' total size.
 */
class AggregateDailyUsage extends Command
{
    protected $signature = 'usage:aggregate-daily {date? : Date to aggregate (Y-m-d), defaults to yesterday}';

    protected $description = 'Dispatch chunked per-customer jobs to roll up usage_events into usage_daily_aggregates';

    public function handle(): int
    {
        $date = $this->argument('date')
            ? CarbonImmutable::parse($this->argument('date'))->toDateString()
            : CarbonImmutable::yesterday()->toDateString();

        $chunkSize = (int) config('billing.aggregation_chunk_size');
        $dispatched = 0;

        Customer::query()->orderBy('id')->chunkById($chunkSize, function ($customers) use ($date, &$dispatched) {
            foreach ($customers as $customer) {
                AggregateCustomerDailyUsageJob::dispatch($customer->id, $date);
                $dispatched++;
            }
        });

        $this->info("Dispatched {$dispatched} aggregation jobs for {$date}.");

        return self::SUCCESS;
    }
}
