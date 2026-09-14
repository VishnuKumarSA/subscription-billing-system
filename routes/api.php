<?php

use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MerchantDashboardController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\UsageEventController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function () {

    // High-throughput, idempotent usage ingestion. Rate limited separately
    // from every other route (120/min per API key) - see config/billing.php
    // and AppServiceProvider::registerRateLimiters().
    Route::post('/usage', [UsageEventController::class, 'store'])->middleware('throttle:usage');

    Route::apiResource('plans', PlanController::class)->only(['index', 'store', 'show', 'update']);

    Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show']);

    Route::post('/subscriptions', [SubscriptionController::class, 'store']);
    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show']);
    Route::post('/subscriptions/{subscription}/change-plan', [SubscriptionController::class, 'changePlan']);
    Route::get('/subscriptions/{subscription}/invoices', [InvoiceController::class, 'index']);

    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);

    Route::get('/merchants/{merchant}/dashboard', [MerchantDashboardController::class, 'show']);
});
