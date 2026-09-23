<?php

namespace App\Providers;

use App\Http\Middleware\RefreshCorsConfigFromEnv;
use App\Services\PermissionResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bir HTTP/queue isi boyunca ayni yetki snapshot'ini yeniden sorgulamamak icin
        // resolver bagimliligini request/job scope'unda tek instance olarak paylas.
        $this->app->scoped(PermissionResolver::class);
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
