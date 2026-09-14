<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\Plan;

class PlanPolicy
{
    public function view(ApiKey $apiKey, Plan $plan): bool
    {
        return $apiKey->merchant_id === $plan->merchant_id;
    }

    public function update(ApiKey $apiKey, Plan $plan): bool
    {
        return $apiKey->merchant_id === $plan->merchant_id;
    }
}
