<?php

namespace App\Jobs;

use App\Actions\Usage\AggregateCustomerDailyUsageAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scoped to exactly one customer, one day. Idempotent by construction
 * (see AggregateCustomerDailyUsageAction), so the default queue retry/
 * backoff is safe without any custom retry logic.
 */
class AggregateCustomerDailyUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $customerId,
        public readonly string $usageDate,
    ) {}

    public function handle(AggregateCustomerDailyUsageAction $action): void
    {
        $action->execute($this->customerId, $this->usageDate);
    }
}
