<?php

namespace App\DTOs;

final class UsageEventData
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $merchantId,
        public readonly string $usageDate,
        public readonly int $units,
        public readonly string $idempotencyKey,
    ) {}
}
