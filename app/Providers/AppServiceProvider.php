<?php

namespace App\Providers;

use App\Models\ApiKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->registerApiKeyGuard();
        $this->registerRateLimiters();
    }

    /**
     * A native Laravel mechanism (Auth::viaRequest) built for exactly this
     * "bearer token in a header" case, so no auth package is needed for a
     * system with no login/session concept - each request just carries a
     * merchant's API key.
     */
    private function registerApiKeyGuard(): void
    {
        Auth::viaRequest('api-key', function (Request $request) {
            $plaintext = $request->header('X-API-Key') ?? $request->bearerToken();

            if (! $plaintext) {
                return null;
            }

            $apiKey = ApiKey::where('key_hash', ApiKey::hashKey($plaintext))->first();

            $apiKey?->forceFill(['last_used_at' => now()])->saveQuietly();

            return $apiKey;
        });
    }

    /**
     * 120 req/min per API key (not per IP - a merchant's integration may
     * legitimately call from many IPs, or share one with others behind a
     * NAT, so IP-based limiting would be either too strict or too loose).
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('usage', function (Request $request) {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute((int) config('billing.usage_rate_limit_per_minute'))->by($key);
        });
    }
}
