<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\Subscription;

class SubscriptionPolicy
{
    public function view(ApiKey $apiKey, Subscription $subscription): bool
    {
        return $apiKey->merchant_id === $subscription->merchant_id;
    }

    public function update(ApiKey $apiKey, Subscription $subscription): bool
    {
        return $apiKey->merchant_id === $subscription->merchant_id;
    }
}
