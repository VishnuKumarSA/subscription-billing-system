<?php

namespace App\Http\Controllers\Api;

use App\Actions\Usage\RecordUsageEventAction;
use App\DTOs\UsageEventData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUsageEventRequest;
use App\Http\Resources\UsageEventResource;

/**
 * Kept deliberately thin: validate, build a DTO, hand off to the action.
 * All idempotency/concurrency handling lives in RecordUsageEventAction -
 * see its docblock for the strategy.
 */
class UsageEventController extends Controller
{
    public function store(StoreUsageEventRequest $request, RecordUsageEventAction $action)
    {
        $validated = $request->validated();

        $event = $action->execute(new UsageEventData(
            customerId: $validated['customer_id'],
            merchantId: $request->user()->merchant_id,
            usageDate: $validated['usage_date'],
            units: $validated['units'],
            idempotencyKey: $validated['idempotency_key'],
        ));

        return (new UsageEventResource($event))
            ->response()
            ->setStatusCode(201);
    }
}
