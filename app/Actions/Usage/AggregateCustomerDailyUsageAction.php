<?php

namespace App\Actions\Usage;

use App\Models\Customer;
use App\Models\UsageDailyAggregate;
use App\Models\UsageEvent;
use Illuminate\Database\QueryException;

/**
 * Recomputes one customer's total for one day from source and upserts it.
 * Deliberately NOT an incrementing counter - recompute-and-upsert means
 * this is safe to rerun (a retried/duplicated job dispatch just re-derives
 * the same total) without any risk of double-counting.
 *
 * The underlying query is a single SUM scoped to one customer via the
 * (customer_id, usage_date) index on usage_events, so its cost is
 * independent of how large usage_events grows overall.
 *
 * Uses whereDate() rather than a plain where() equality check: usage_date
 * is a DATE column, and comparing it against a plain 'Y-m-d' string needs
 * to work identically whether the driver stores it with or without a time
 * component (MySQL DATE columns store date-only; SQLite, used in tests,
 * has no native DATE type and round-trips a time component).
 */
class AggregateCustomerDailyUsageAction
{
    public function execute(int $customerId, string $usageDate): UsageDailyAggregate
    {
        $customer = Customer::findOrFail($customerId);

        $total = (int) UsageEvent::where('customer_id', $customerId)
            ->whereDate('usage_date', $usageDate)
            ->sum('units');

        $existing = UsageDailyAggregate::where('customer_id', $customerId)
            ->whereDate('usage_date', $usageDate)
            ->first();

        if ($existing) {
            $existing->update(['units_total' => $total, 'merchant_id' => $customer->merchant_id]);

            return $existing;
        }

        try {
            return UsageDailyAggregate::create([
                'customer_id' => $customerId,
                'merchant_id' => $customer->merchant_id,
                'usage_date' => $usageDate,
                'units_total' => $total,
            ]);
        } catch (QueryException $e) {
            // Lost a race with another worker aggregating the same
            // customer/day concurrently - fall back to updating their row.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $existing = UsageDailyAggregate::where('customer_id', $customerId)
                ->whereDate('usage_date', $usageDate)
                ->firstOrFail();

            $existing->update(['units_total' => $total]);

            return $existing;
        }
    }
}
