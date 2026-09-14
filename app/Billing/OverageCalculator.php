<?php

namespace App\Billing;

use App\DTOs\OverageResult;

/**
 * Pure overage math. Overage rate is stored in micros of the currency unit
 * (1,000,000 micros = 1 currency unit = 100 cents), so:
 *
 *   amount_cents = overage_units * rate_micros * 100 / 1_000_000
 *                = overage_units * rate_micros / 10_000
 *
 * Rounding happens exactly once, on the final amount - never on an
 * intermediate per-unit rate. All arithmetic stays in PHP integers until
 * that final round(); realistic overage_units (~1e9) x rate_micros (~1e7)
 * stays well within PHP's 64-bit integer range, so no bcmath is needed.
 */
final class OverageCalculator
{
    private const MICROS_TO_CENTS_DIVISOR = 10_000;

    public function calculate(int $usageUnits, int $includedUnits, int $overageRateMicros): OverageResult
    {
        $overageUnits = max(0, $usageUnits - $includedUnits);
        $overageAmountCents = (int) round(($overageUnits * $overageRateMicros) / self::MICROS_TO_CENTS_DIVISOR);

        return new OverageResult(
            overageUnits: $overageUnits,
            overageAmountCents: $overageAmountCents,
        );
    }
}
