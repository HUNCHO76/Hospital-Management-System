<?php

namespace App\Providers;

use App\Models\NhifClaim;
use App\Models\NhifPackagePrice;
use App\Models\User;
use App\Policies\NhifClaimPolicy;
use App\Policies\NhifPackagePricePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability) {
            // 1. Explicit user-specific overrides take highest precedence:
            $overrides = $user->getCachedPermissionOverrides();
            if (isset($overrides[$ability])) {
                if ($overrides[$ability] === \App\Models\UserPermissionOverride::EFFECT_DENY) {
                    return false;
                }
                if ($overrides[$ability] === \App\Models\UserPermissionOverride::EFFECT_ALLOW) {
                    return true;
                }
            }

            // 2. Super-admin default bypass:
            return $user->hasRole('super-admin') ? true : null;
        });

        Gate::policy(NhifClaim::class, NhifClaimPolicy::class);
        Gate::policy(NhifPackagePrice::class, NhifPackagePricePolicy::class);

        Password::defaults(function () {
            $rule = Password::min(8)
                ->letters()
                ->numbers()
                ->symbols();

            return app()->isProduction()
                ? $rule->uncompromised()
                : $rule;
        });

        $this->configureRateLimiters();
    }

    /**
     * Register the API rate limiters. Authenticated traffic is keyed per user
     * (falling back to IP); the auth limiter is stricter and keyed by IP to
     * blunt brute-force and credential-stuffing attempts.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.standard', 60))
                ->by($request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.auth', 5))
                ->by('auth:'.$request->ip());
        });

        RateLimiter::for('nhif-verification', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.nhif_verification', 15))
                ->by($request->user()?->id ? 'nhif:user:'.$request->user()->id : 'nhif:ip:'.$request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Too many NHIF verification requests. Please wait a moment before trying again.',
                    ], 429, $headers);
                });
        });
    }
}
