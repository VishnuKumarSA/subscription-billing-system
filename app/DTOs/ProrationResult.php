<?php

namespace App\DTOs;

/**
 * The output of ProrationCalculator for a single subscription-plan-segment
 * overlapping an invoice period.
 */
final class ProrationResult
{
    public function __construct(
        public readonly int $segmentDays,
        public readonly int $cycleDays,
        public readonly float $dayFraction,
        public readonly int $proratedBaseCents,
        public readonly int $proratedIncludedUnits,
    ) {}
}
