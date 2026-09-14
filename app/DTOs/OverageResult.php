<?php

namespace App\DTOs;

/**
 * The output of OverageCalculator for a single subscription-plan-segment.
 */
final class OverageResult
{
    public function __construct(
        public readonly int $overageUnits,
        public readonly int $overageAmountCents,
    ) {}
}
