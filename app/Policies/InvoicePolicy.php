<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\Invoice;

class InvoicePolicy
{
    public function view(ApiKey $apiKey, Invoice $invoice): bool
    {
        return $apiKey->merchant_id === $invoice->merchant_id;
    }
}
