<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
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
        // FR-013a/b: базовий rate limit на технічний Sanctum-токен,
        // 429 + Retry-After формується стандартним ThrottleRequests middleware.
        // Реєструється тут (а не у withRouting()->then()), оскільки той
        // колбек НЕ виконується, коли маршрути закешовані (route:cache) —
        // Laravel просто завантажує кеш, минаючи колбек реєстрації маршрутів.
        RateLimiter::for('rag-api', function (Request $request) {
            return Limit::perMinute(config('rag.rate_limit_per_minute'))
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });
    }
}
