<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\Customer;

class CustomerPolicy
{
    public function view(ApiKey $apiKey, Customer $customer): bool
    {
        return $apiKey->merchant_id === $customer->merchant_id;
    }
}
