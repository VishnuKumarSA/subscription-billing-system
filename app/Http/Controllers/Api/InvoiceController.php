<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request, Subscription $subscription)
    {
        $this->authorize('view', $subscription);

        $invoices = $subscription->invoices()->orderByDesc('period_start')->get();

        return InvoiceResource::collection($invoices);
    }

    public function show(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice->load('lineItems'));
    }
}
