<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Catch lazy-loading / silently discarded attributes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('auth', function (Request $request) {
            return [
                Limit::perMinute(10)->by('auth-ip:'.$request->ip()),
                Limit::perMinute(5)->by('auth-email:'.mb_strtolower((string) $request->input('email'))),
            ];
        });

        // Passcodes are short; keep brute force impractical.
        RateLimiter::for('vault', fn (Request $request) => Limit::perMinute(5)->by('vault:'.$request->user()?->getKey()));

        RateLimiter::for('cart', fn (Request $request) => Limit::perMinute(60)->by(
            'cart:'.($request->user('sanctum')?->getKey() ?? $request->ip())
        ));
    }
}
