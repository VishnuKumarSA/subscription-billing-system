<?php

namespace Tests\Unit;

use App\Billing\ProrationCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ProrationCalculatorTest extends TestCase
{
    private ProrationCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ProrationCalculator;
    }

    public function test_full_month_no_proration(): void
    {
        $result = $this->calculator->calculate(
            cycleStart: CarbonImmutable::parse('2026-09-01'),
            cycleEnd: CarbonImmutable::parse('2026-10-01'),
            segmentStart: CarbonImmutable::parse('2026-09-01'),
            segmentEnd: null,
            basePriceCents: 300000,
            includedUnits: 50000,
        );

        $this->assertSame(30, $result->cycleDays);
        $this->assertSame(30, $result->segmentDays);
        $this->assertSame(1.0, $result->dayFraction);
        $this->assertSame(300000, $result->proratedBaseCents);
        $this->assertSame(50000, $result->proratedIncludedUnits);
    }

    public function test_first_day_start_is_equivalent_to_full_month(): void
    {
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-09-01'),
            null,
            300000,
            50000,
        );

        $this->assertSame(1.0, $result->dayFraction);
    }

    public function test_mid_cycle_start_halfway_through_prorates_by_half(): void
    {
        // 30-day September cycle, subscription starts on the 16th ->
        // 15 remaining days out of 30 -> exactly half.
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-09-16'),
            null,
            300000,
            50000,
        );

        $this->assertSame(15, $result->segmentDays);
        $this->assertSame(0.5, $result->dayFraction);
        $this->assertSame(150000, $result->proratedBaseCents);
        $this->assertSame(25000, $result->proratedIncludedUnits);
    }

    public function test_start_on_last_day_of_cycle_bills_a_single_day(): void
    {
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-09-30'),
            null,
            300000,
            50000,
        );

        $this->assertSame(1, $result->segmentDays);
        $this->assertEqualsWithDelta(1 / 30, $result->dayFraction, 0.0001);
        $this->assertSame(10000, $result->proratedBaseCents); // 300000 / 30
    }

    public function test_zero_day_segment_prorates_to_nothing(): void
    {
        // Two plan changes on the same day close a segment the instant it opens.
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-09-10'),
            CarbonImmutable::parse('2026-09-10'),
            300000,
            50000,
        );

        $this->assertSame(0, $result->segmentDays);
        $this->assertSame(0.0, $result->dayFraction);
        $this->assertSame(0, $result->proratedBaseCents);
        $this->assertSame(0, $result->proratedIncludedUnits);
    }

    public function test_segment_is_clamped_to_the_cycle_boundaries(): void
    {
        // Segment starts before the cycle and ends after it - only the
        // overlap with the cycle should be billed.
        $result = $this->calculator->calculate(
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-11-01'),
            300000,
            50000,
        );

        $this->assertSame(30, $result->segmentDays);
        $this->assertSame(1.0, $result->dayFraction);
    }

    public function test_cycle_length_varies_correctly_across_month_boundaries(): void
    {
        $leapFebruary = $this->calculator->calculate(
            CarbonImmutable::parse('2024-02-01'),
            CarbonImmutable::parse('2024-03-01'),
            CarbonImmutable::parse('2024-02-01'),
            null,
            300000,
            50000,
        );
        $this->assertSame(29, $leapFebruary->cycleDays);

        $nonLeapFebruary = $this->calculator->calculate(
            CarbonImmutable::parse('2025-02-01'),
            CarbonImmutable::parse('2025-03-01'),
            CarbonImmutable::parse('2025-02-01'),
            null,
            300000,
            50000,
        );
        $this->assertSame(28, $nonLeapFebruary->cycleDays);
    }
}
