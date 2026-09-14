<?php

namespace App\Billing;

use App\DTOs\ProrationResult;
use Carbon\CarbonImmutable;

/**
 * Pure, side-effect-free proration math. No I/O, no Eloquent - trivially
 * unit-testable and reused identically for a plain full-cycle invoice and
 * for each segment of a mid-cycle plan change.
 *
 * All dates are treated as whole days on a half-open interval
 * [start, end) - this matches usage_events/usage_daily_aggregates, which
 * are recorded per calendar day, not per timestamp. A plan change takes
 * effect at day granularity (an "effective date"), so there is never a
 * fractional day to reason about.
 */
final class ProrationCalculator
{
    /**
     * @param  CarbonImmutable  $cycleStart  Inclusive start of the full nominal billing cycle.
     * @param  CarbonImmutable  $cycleEnd  Exclusive end of the full nominal billing cycle.
     * @param  CarbonImmutable  $segmentStart  Inclusive start of the segment (subscription's/segment's start date).
     * @param  CarbonImmutable|null  $segmentEnd  Exclusive end of the segment, or null if still open (clamped to cycle end).
     */
    public function calculate(
        CarbonImmutable $cycleStart,
        CarbonImmutable $cycleEnd,
        CarbonImmutable $segmentStart,
        ?CarbonImmutable $segmentEnd,
        int $basePriceCents,
        int $includedUnits,
    ): ProrationResult {
        $cycleDays = max(0, $cycleStart->diffInDays($cycleEnd));

        $overlapStart = $segmentStart->greaterThan($cycleStart) ? $segmentStart : $cycleStart;
        $segmentEndClamped = $segmentEnd !== null && $segmentEnd->lessThan($cycleEnd) ? $segmentEnd : $cycleEnd;

        $segmentDays = $segmentEndClamped->greaterThan($overlapStart)
            ? $overlapStart->diffInDays($segmentEndClamped)
            : 0;

        $dayFraction = $cycleDays > 0 ? $segmentDays / $cycleDays : 0.0;

        return new ProrationResult(
            segmentDays: $segmentDays,
            cycleDays: $cycleDays,
            dayFraction: $dayFraction,
            proratedBaseCents: (int) round($basePriceCents * $dayFraction),
            proratedIncludedUnits: (int) round($includedUnits * $dayFraction),
        );
    }
}
