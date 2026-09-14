<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\DashboardAggregationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MerchantDashboardController extends Controller
{
    /**
     * Short-TTL cache on top of an already-cheap set of rollup-table
     * queries. No active invalidation: the dashboard is inherently
     * eventually-consistent (it depends on the nightly aggregation job),
     * so a few minutes of cache lag on top of that is an accepted
     * trade-off, not something worth engineering around.
     */
    public function show(Request $request, Merchant $merchant, DashboardAggregationService $service)
    {
        $this->authorize('view', $merchant);

        $cacheKey = "dashboard:merchant:{$merchant->id}:".now()->format('Y-m');
        $ttl = now()->addMinutes((int) config('billing.dashboard_cache_ttl_minutes'));

        $data = Cache::remember($cacheKey, $ttl, fn () => $service->build($merchant));

        return response()->json($data);
    }
}
