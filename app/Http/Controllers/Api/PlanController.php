<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $plans = Plan::where('merchant_id', $request->user()->merchant_id)
            ->orderBy('name')
            ->get();

        return PlanResource::collection($plans);
    }

    public function store(StorePlanRequest $request)
    {
        $plan = Plan::create([
            ...$request->validated(),
            'merchant_id' => $request->user()->merchant_id,
        ]);

        return (new PlanResource($plan))->response()->setStatusCode(201);
    }

    public function show(Request $request, Plan $plan)
    {
        $this->authorize('view', $plan);

        return new PlanResource($plan);
    }

    public function update(UpdatePlanRequest $request, Plan $plan)
    {
        $plan->update($request->validated());

        return new PlanResource($plan);
    }
}
