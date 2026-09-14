<?php

namespace App\DTOs;

/**
 * Everything needed to persist one invoice_line_items row - assembled by
 * GenerateInvoiceAction from a segment's ProrationResult + OverageResult
 * before being written, so the persistence step is a single straight
 * mapping with no further calculation happening at write time.
 */
final class InvoiceLineItemData
{
    public function __construct(
        public readonly ?int $segmentId,
        public readonly int $planId,
        public readonly string $planNameSnapshot,
        public readonly string $segmentStartsOn,
        public readonly ?string $segmentEndsOn,
        public readonly float $dayFraction,
        public readonly int $basePriceCentsSnapshot,
        public readonly int $proratedBaseCents,
        public readonly int $includedUnitsSnapshot,
        public readonly int $proratedIncludedUnits,
        public readonly int $usageUnits,
        public readonly int $overageUnits,
        public readonly int $overageRateMicrosSnapshot,
        public readonly int $overageAmountCents,
        public readonly int $lineTotalCents,
    ) {}
}
