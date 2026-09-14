<?php

namespace App\Services;

use App\Billing\OverageCalculator;
use App\Billing\ProrationCalculator;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Subscription;
use App\Models\UsageDailyAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every method here reads only usage_daily_aggregates (never usage_events)
 * plus small, whereIn-batched lookups on customers/subscriptions - so
 * nothing in this service scans a table whose size depends on raw usage
 * volume. All three methods are scoped to one merchant and use the
 * (merchant_id, usage_date) index.
 */
class DashboardAggregationService
{
    public function __construct(
        private readonly ProrationCalculator $proration,
        private readonly OverageCalculator $overage,
    ) {}

    public function build(Merchant $merchant, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        return [
            'merchant_id' => $merchant->id,
            'cycle' => [
                'start' => $now->startOfMonth()->toDateString(),
                'end' => $now->startOfMonth()->addMonthNoOverflow()->toDateString(),
            ],
            'total_usage_this_month' => $this->totalUsageThisMonth($merchant, $now),
            'projected_overage_revenue_cents' => $this->projectedOverageRevenue($merchant, $now),
            'top_customers' => $this->topCustomersByUsage($merchant, $now),
            'churn_risk_customers' => $this->churnRiskCustomers($merchant, $now),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Top 5 customers by usage this calendar month.
     */
    public function topCustomersByUsage(Merchant $merchant, CarbonImmutable $now, int $limit = 5): array
    {
        $monthStart = $now->startOfMonth();
        $monthEnd = $monthStart->addMonthNoOverflow();

        $rows = UsageDailyAggregate::query()
            ->select('customer_id', DB::raw('SUM(units_total) as units'))
            ->where('merchant_id', $merchant->id)
            ->whereDate('usage_date', '>=', $monthStart->toDateString())
            ->whereDate('usage_date', '<', $monthEnd->toDateString())
            ->groupBy('customer_id')
            ->orderByDesc('units')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $customerIds = $rows->pluck('customer_id');
        $customers = Customer::whereIn('id', $customerIds)->get()->keyBy('id');
        $allowances = $this->currentAllowancesByCustomer($customerIds);

        return $rows->map(function ($row) use ($customers, $allowances) {
            $allowance = $allowances->get($row->customer_id);

            return [
                'customer_id' => $row->customer_id,
                'customer_name' => $customers->get($row->customer_id)?->name,
                'units' => (int) $row->units,
                'included_units' => $allowance,
                'percent_of_allowance' => $allowance ? round(($row->units / $allowance) * 100, 1) : null,
            ];
        })->values()->all();
    }

    /**
     * Customers whose usage this month is on track to be down more than 50%
     * versus last month. A customer with zero usage last month has no
     * baseline to drop from, so they are excluded rather than reported as a
     * 100% drop.
     *
     * This month's usage-so-far is run-rate projected across the full
     * month (same technique as projectedOverageRevenue) before comparing -
     * a raw partial-month total compared against a full previous month
     * would mechanically look like a big "drop" on, say, the 10th of the
     * month for every customer with perfectly flat usage, which isn't a
     * churn signal at all. Projecting first means the dashboard gives a
     * meaningful answer on any day of the cycle, not only near its end.
     */
    public function churnRiskCustomers(Merchant $merchant, CarbonImmutable $now): array
    {
        $thisMonthStart = $now->startOfMonth();
        $thisMonthEnd = $thisMonthStart->addMonthNoOverflow();
        $lastMonthStart = $thisMonthStart->subMonthNoOverflow();

        $today = $now->lessThan($thisMonthEnd) ? $now : $thisMonthEnd->subDay();
        $daysElapsed = max(1, $thisMonthStart->diffInDays($today) + 1);
        $daysInMonth = $thisMonthStart->diffInDays($thisMonthEnd);

        $thisMonthSoFar = $this->sumByCustomer($merchant->id, $thisMonthStart, $today->addDay());
        $lastMonth = $this->sumByCustomer($merchant->id, $lastMonthStart, $thisMonthStart);

        if ($lastMonth->isEmpty()) {
            return [];
        }

        $customers = Customer::whereIn('id', $lastMonth->keys())->get()->keyBy('id');

        $atRisk = [];

        foreach ($lastMonth as $customerId => $previousUnits) {
            if ($previousUnits <= 0) {
                continue;
            }

            $soFar = (int) $thisMonthSoFar->get($customerId, 0);
            $projectedUnits = ($soFar / $daysElapsed) * $daysInMonth;
            $dropRatio = 1 - ($projectedUnits / $previousUnits);

            if ($dropRatio > 0.5) {
                $atRisk[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customers->get($customerId)?->name,
                    'previous_month_units' => (int) $previousUnits,
                    'current_month_units' => $soFar,
                    'projected_month_units' => (int) round($projectedUnits),
                    'percent_change' => round(-$dropRatio * 100, 1),
                ];
            }
        }

        usort($atRisk, fn ($a, $b) => $a['percent_change'] <=> $b['percent_change']);

        return $atRisk;
    }

    /**
     * Linear run-rate projection: for each active subscription, take the
     * overage accrued so far in the current segment and extrapolate it
     * across the remaining days of that segment within the cycle, then
     * price the projected overage at the segment's rate. Summed across the
     * merchant's customers. This is intentionally simple - no seasonality
     * or trend modelling - and documented as such.
     *
     * Subscriptions are grouped by their effective accrual start date and
     * fetched with ONE grouped SUM query per distinct date, rather than one
     * query per subscription - in the common case (no plan change this
     * cycle, so every customer shares the same calendar-month cycle start)
     * that's a single query for the whole merchant, not N.
     */
    public function projectedOverageRevenue(Merchant $merchant, CarbonImmutable $now): int
    {
        $subscriptions = Subscription::where('merchant_id', $merchant->id)
            ->where('status', 'active')
            ->with(['segments' => fn ($q) => $q->whereNull('ends_on')])
            ->get();

        $customerIdsByEffectiveStart = [];
        $perCustomer = [];

        foreach ($subscriptions as $subscription) {
            $segment = $subscription->segments->first();

            if (! $segment) {
                continue;
            }

            $cycleStart = CarbonImmutable::parse($subscription->current_period_start);
            $cycleEnd = CarbonImmutable::parse($subscription->current_period_end);
            $segmentStart = CarbonImmutable::parse($segment->starts_on);
            $effectiveStart = $segmentStart->greaterThan($cycleStart) ? $segmentStart : $cycleStart;

            $prorationResult = $this->proration->calculate(
                $cycleStart, $cycleEnd, $segmentStart, null,
                $segment->base_price_cents_snapshot, $segment->included_units_snapshot,
            );

            if ($prorationResult->segmentDays === 0) {
                continue;
            }

            $today = $now->lessThan($cycleEnd) ? $now : $cycleEnd->subDay();
            $daysElapsed = max(1, $effectiveStart->diffInDays($today) + 1);

            $customerIdsByEffectiveStart[$effectiveStart->toDateString()][] = $subscription->customer_id;
            $perCustomer[$subscription->customer_id] = [
                'prorationResult' => $prorationResult,
                'overageRateMicros' => $segment->overage_rate_micros_snapshot,
                'daysElapsed' => $daysElapsed,
                'today' => $today,
            ];
        }

        if (empty($perCustomer)) {
            return 0;
        }

        $accruedByCustomer = [];

        foreach ($customerIdsByEffectiveStart as $startDate => $customerIds) {
            // Billing cycles are calendar-month aligned, so "today" is the
            // same for every subscription regardless of which group it's in.
            $today = $perCustomer[$customerIds[0]]['today'];

            $sums = UsageDailyAggregate::whereIn('customer_id', $customerIds)
                ->whereDate('usage_date', '>=', $startDate)
                ->whereDate('usage_date', '<=', $today->toDateString())
                ->selectRaw('customer_id, SUM(units_total) as units')
                ->groupBy('customer_id')
                ->pluck('units', 'customer_id');

            foreach ($sums as $customerId => $units) {
                $accruedByCustomer[$customerId] = (int) $units;
            }
        }

        $totalCents = 0;

        foreach ($perCustomer as $customerId => $data) {
            $accruedUnits = $accruedByCustomer[$customerId] ?? 0;
            $projectedUnits = (int) round(($accruedUnits / $data['daysElapsed']) * $data['prorationResult']->segmentDays);

            $overageResult = $this->overage->calculate(
                $projectedUnits, $data['prorationResult']->proratedIncludedUnits, $data['overageRateMicros'],
            );

            $totalCents += $overageResult->overageAmountCents;
        }

        return $totalCents;
    }

    public function totalUsageThisMonth(Merchant $merchant, CarbonImmutable $now): int
    {
        $monthStart = $now->startOfMonth();
        $monthEnd = $monthStart->addMonthNoOverflow();

        return (int) UsageDailyAggregate::where('merchant_id', $merchant->id)
            ->whereDate('usage_date', '>=', $monthStart->toDateString())
            ->whereDate('usage_date', '<', $monthEnd->toDateString())
            ->sum('units_total');
    }

    private function sumByCustomer(int $merchantId, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return UsageDailyAggregate::query()
            ->select('customer_id', DB::raw('SUM(units_total) as units'))
            ->where('merchant_id', $merchantId)
            ->whereDate('usage_date', '>=', $start->toDateString())
            ->whereDate('usage_date', '<', $end->toDateString())
            ->groupBy('customer_id')
            ->pluck('units', 'customer_id');
    }

    private function currentAllowancesByCustomer(Collection $customerIds): Collection
    {
        return Subscription::whereIn('customer_id', $customerIds)
            ->where('status', 'active')
            ->with(['segments' => fn ($q) => $q->whereNull('ends_on')])
            ->get()
            ->mapWithKeys(fn (Subscription $s) => [
                $s->customer_id => $s->segments->first()?->included_units_snapshot,
            ]);
    }
}
