<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\Merchant;

class MerchantPolicy
{
    /**
     * An API key may only ever view the dashboard/data of its own merchant -
     * this is the tenant-isolation boundary for the /merchants/{id}/... routes.
     */
    public function view(ApiKey $apiKey, Merchant $merchant): bool
    {
        return $apiKey->merchant_id === $merchant->id;
    }
}
