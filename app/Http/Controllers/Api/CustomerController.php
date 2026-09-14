<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::where('merchant_id', $request->user()->merchant_id)
            ->orderBy('name')
            ->get();

        return CustomerResource::collection($customers);
    }

    public function store(StoreCustomerRequest $request)
    {
        $customer = Customer::create([
            ...$request->validated(),
            'merchant_id' => $request->user()->merchant_id,
        ]);

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function show(Request $request, Customer $customer)
    {
        $this->authorize('view', $customer);

        return new CustomerResource($customer);
    }
}
