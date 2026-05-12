<?php

namespace App\Providers;

use App\Http\Middleware\RefreshCorsConfigFromEnv;
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
        // config:cache bos CORS ile build edildiyse bile runtime'da izin listesi dolsun (HandleCors).
        RefreshCorsConfigFromEnv::syncCorsConfigFromEnv();
    }
}
