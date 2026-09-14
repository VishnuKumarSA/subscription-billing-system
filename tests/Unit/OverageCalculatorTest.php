<?php

namespace Tests\Unit;

use App\Billing\OverageCalculator;
use PHPUnit\Framework\TestCase;

class OverageCalculatorTest extends TestCase
{
    private OverageCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new OverageCalculator;
    }

    public function test_no_overage_when_usage_is_below_allowance(): void
    {
        $result = $this->calculator->calculate(usageUnits: 40000, includedUnits: 50000, overageRateMicros: 50000);

        $this->assertSame(0, $result->overageUnits);
        $this->assertSame(0, $result->overageAmountCents);
    }

    public function test_no_overage_when_usage_exactly_equals_allowance(): void
    {
        $result = $this->calculator->calculate(usageUnits: 50000, includedUnits: 50000, overageRateMicros: 50000);

        $this->assertSame(0, $result->overageUnits);
        $this->assertSame(0, $result->overageAmountCents);
    }

    public function test_zero_usage_produces_zero_overage(): void
    {
        $result = $this->calculator->calculate(usageUnits: 0, includedUnits: 50000, overageRateMicros: 50000);

        $this->assertSame(0, $result->overageUnits);
        $this->assertSame(0, $result->overageAmountCents);
    }

    /**
     * The exact example from the assignment brief: base 3000, included
     * 50000, overage rate 0.05/unit, usage 60000 -> overage 10000 x 0.05
     * = 500 (currency units) = 50000 cents.
     */
    public function test_matches_the_brief_worked_example(): void
    {
        $result = $this->calculator->calculate(usageUnits: 60000, includedUnits: 50000, overageRateMicros: 50000);

        $this->assertSame(10000, $result->overageUnits);
        $this->assertSame(50000, $result->overageAmountCents);
    }

    public function test_sub_cent_rates_do_not_lose_precision_at_high_volume(): void
    {
        // ₹0.0001/unit (100 micros), 1,000,000 units of overage -> ₹100.00 exactly.
        $result = $this->calculator->calculate(usageUnits: 1_000_000, includedUnits: 0, overageRateMicros: 100);

        $this->assertSame(1_000_000, $result->overageUnits);
        $this->assertSame(10_000, $result->overageAmountCents); // 10,000 cents = ₹100
    }
}
