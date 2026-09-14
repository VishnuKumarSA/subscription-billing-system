<?php

namespace App\Actions\Billing;

use App\Billing\OverageCalculator;
use App\Billing\ProrationCalculator;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\UsageDailyAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generates one invoice for a subscription's current billing period,
 * walking every subscription_plan_segment that overlaps it so a mid-cycle
 * plan change produces one line item per segment, each billed at that
 * segment's own rate.
 *
 * Idempotency / retry-safety: locks the subscription row, then checks the
 * (subscription_id, period_start, period_end) uniqueness guard before doing
 * any work - a retried or duplicated dispatch is a safe no-op that returns
 * the already-generated invoice rather than creating a second one. The DB
 * unique constraint on invoices is the ultimate backstop if two workers
 * somehow race past the lock.
 */
class GenerateInvoiceAction
{
    public function __construct(
        private readonly ProrationCalculator $proration,
        private readonly OverageCalculator $overage,
    ) {}

    public function execute(Subscription $subscription): Invoice
    {
        return DB::transaction(function () use ($subscription) {
            /** @var Subscription $locked */
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $periodStart = CarbonImmutable::parse($locked->current_period_start);
            $periodEnd = CarbonImmutable::parse($locked->current_period_end);

            $existing = Invoice::where('subscription_id', $locked->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->whereDate('period_end', $periodEnd->toDateString())
                ->first();

            if ($existing) {
                return $existing;
            }

            $segments = $locked->segments()
                ->with('plan')
                ->whereDate('starts_on', '<', $periodEnd->toDateString())
                ->where(function ($query) use ($periodStart) {
                    $query->whereNull('ends_on')->orWhereDate('ends_on', '>', $periodStart->toDateString());
                })
                ->orderBy('starts_on')
                ->get();

            $lineItems = [];
            $subtotalCents = 0;
            $currency = $locked->currentPlan?->currency ?? 'INR';

            foreach ($segments as $segment) {
                $segmentStart = CarbonImmutable::parse($segment->starts_on);
                $segmentEnd = $segment->ends_on ? CarbonImmutable::parse($segment->ends_on) : null;

                $prorationResult = $this->proration->calculate(
                    cycleStart: $periodStart,
                    cycleEnd: $periodEnd,
                    segmentStart: $segmentStart,
                    segmentEnd: $segmentEnd,
                    basePriceCents: $segment->base_price_cents_snapshot,
                    includedUnits: $segment->included_units_snapshot,
                );

                // A zero-day segment (e.g. two plan changes on the same day)
                // contributes nothing - it never overlapped the cycle for a
                // full day, so there is nothing to prorate or bill.
                if ($prorationResult->segmentDays === 0) {
                    continue;
                }

                $overlapStart = $segmentStart->greaterThan($periodStart) ? $segmentStart : $periodStart;
                $overlapEnd = ($segmentEnd && $segmentEnd->lessThan($periodEnd)) ? $segmentEnd : $periodEnd;

                $usageUnits = (int) UsageDailyAggregate::where('customer_id', $locked->customer_id)
                    ->whereDate('usage_date', '>=', $overlapStart->toDateString())
                    ->whereDate('usage_date', '<', $overlapEnd->toDateString())
                    ->sum('units_total');

                $overageResult = $this->overage->calculate(
                    usageUnits: $usageUnits,
                    includedUnits: $prorationResult->proratedIncludedUnits,
                    overageRateMicros: $segment->overage_rate_micros_snapshot,
                );

                $lineTotalCents = $prorationResult->proratedBaseCents + $overageResult->overageAmountCents;
                $subtotalCents += $lineTotalCents;
                $currency = $segment->currency_snapshot;

                $lineItems[] = [
                    'segment_id' => $segment->id,
                    'plan_id' => $segment->plan_id,
                    'plan_name_snapshot' => $segment->plan?->name ?? '',
                    'segment_starts_on' => $segmentStart->toDateString(),
                    'segment_ends_on' => $segmentEnd?->toDateString(),
                    'day_fraction' => $prorationResult->dayFraction,
                    'base_price_cents_snapshot' => $segment->base_price_cents_snapshot,
                    'prorated_base_cents' => $prorationResult->proratedBaseCents,
                    'included_units_snapshot' => $segment->included_units_snapshot,
                    'prorated_included_units' => $prorationResult->proratedIncludedUnits,
                    'usage_units' => $usageUnits,
                    'overage_units' => $overageResult->overageUnits,
                    'overage_rate_micros_snapshot' => $segment->overage_rate_micros_snapshot,
                    'overage_amount_cents' => $overageResult->overageAmountCents,
                    'line_total_cents' => $lineTotalCents,
                ];
            }

            $invoice = Invoice::create([
                'subscription_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'merchant_id' => $locked->merchant_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => 'finalized',
                'subtotal_cents' => $subtotalCents,
                'total_cents' => $subtotalCents,
                'currency' => $currency,
                'generated_at' => now(),
            ]);

            foreach ($lineItems as $lineItem) {
                $invoice->lineItems()->create($lineItem);
            }

            $locked->update([
                'current_period_start' => $periodEnd->toDateString(),
                'current_period_end' => $periodEnd->addMonthNoOverflow()->toDateString(),
            ]);

            return $invoice->load('lineItems');
        });
    }
}
