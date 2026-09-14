<?php

namespace App\DTOs;

/**
 * A frozen snapshot of a plan's price. This is the one shape used both for
 * the plan-pricing cache payload (App\Support\PlanPricingResolver) and for
 * what gets written onto a subscription_plan_segments row - so "current
 * pricing" and "pricing that was frozen onto a segment" are the same DTO,
 * just captured at different moments.
 */
final class PlanPricingData
{
    public function __construct(
        public readonly int $planId,
        public readonly string $planName,
        public readonly int $basePriceCents,
        public readonly int $includedUnits,
        public readonly int $overageRateMicros,
        public readonly string $currency,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_id' => $this->planId,
            'plan_name' => $this->planName,
            'base_price_cents' => $this->basePriceCents,
            'included_units' => $this->includedUnits,
            'overage_rate_micros' => $this->overageRateMicros,
            'currency' => $this->currency,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            planId: (int) $data['plan_id'],
            planName: (string) $data['plan_name'],
            basePriceCents: (int) $data['base_price_cents'],
            includedUnits: (int) $data['included_units'],
            overageRateMicros: (int) $data['overage_rate_micros'],
            currency: (string) $data['currency'],
        );
    }
}
