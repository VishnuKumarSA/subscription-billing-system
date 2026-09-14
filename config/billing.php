<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Usage endpoint rate limit
    |--------------------------------------------------------------------------
    | Requests per minute, per API key, allowed against POST /api/usage.
    */
    'usage_rate_limit_per_minute' => (int) env('USAGE_RATE_LIMIT_PER_MINUTE', 120),

    /*
    |--------------------------------------------------------------------------
    | Plan pricing cache TTL
    |--------------------------------------------------------------------------
    | Safety-net TTL for the plan pricing cache. The primary invalidation
    | mechanism is explicit (Plan model observer clears the key on save),
    | so this only matters for anything that bypasses Eloquent.
    */
    'plan_pricing_cache_ttl_minutes' => (int) env('PLAN_PRICING_CACHE_TTL_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Dashboard cache TTL
    |--------------------------------------------------------------------------
    | The dashboard is inherently eventually-consistent (it depends on the
    | nightly aggregation job), so a short cache on top is an accepted
    | trade-off rather than something requiring active invalidation.
    */
    'dashboard_cache_ttl_minutes' => (int) env('DASHBOARD_CACHE_TTL_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Aggregation job chunk size
    |--------------------------------------------------------------------------
    | Number of customers per chunk when the nightly aggregation command
    | walks the customer roster (chunkById). Each dispatched job then runs
    | one indexed per-customer SUM query - see README "Queue architecture".
    */
    'aggregation_chunk_size' => (int) env('AGGREGATION_CHUNK_SIZE', 5000),

    /*
    |--------------------------------------------------------------------------
    | Invoice generation chunk size
    |--------------------------------------------------------------------------
    | Number of subscriptions per chunk when the billing scheduler walks
    | due subscriptions.
    */
    'invoice_chunk_size' => (int) env('INVOICE_CHUNK_SIZE', 5000),

];
