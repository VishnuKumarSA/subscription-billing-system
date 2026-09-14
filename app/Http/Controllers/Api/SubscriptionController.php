<?php

namespace App\Http\Controllers\Api;

use App\Actions\Billing\ChangeSubscriptionPlanAction;
use App\Actions\Subscriptions\CreateSubscriptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeSubscriptionPlanRequest;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function store(StoreSubscriptionRequest $request, CreateSubscriptionAction $action)
    {
        $validated = $request->validated();

        $customer = Customer::findOrFail($validated['customer_id']);
        $plan = Plan::findOrFail($validated['plan_id']);
        $startDate = isset($validated['start_date'])
            ? CarbonImmutable::parse($validated['start_date'])
            : CarbonImmutable::now();

        $subscription = $action->execute($customer, $plan, $startDate);

        return (new SubscriptionResource($subscription->load(['currentPlan', 'segments'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Subscription $subscription)
    {
        $this->authorize('view', $subscription);

        return new SubscriptionResource($subscription->load(['currentPlan', 'segments.plan']));
    }

    public function changePlan(ChangeSubscriptionPlanRequest $request, Subscription $subscription, ChangeSubscriptionPlanAction $action)
    {
        $validated = $request->validated();

        $newPlan = Plan::findOrFail($validated['new_plan_id']);
        $effectiveDate = isset($validated['effective_date'])
            ? CarbonImmutable::parse($validated['effective_date'])
            : CarbonImmutable::now();

        $action->execute($subscription, $newPlan, $effectiveDate);

        return new SubscriptionResource($subscription->fresh(['currentPlan', 'segments.plan']));
    }
}
