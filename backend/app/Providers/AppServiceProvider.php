<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('App\\Support\\AssetIntelligenceSchema', 'App\\Support\\AssetIntelligenceSchema');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Escaneo masivo/individual: operacion cara (dispara jobs por cada
        // sitio del inventario). Limitada por usuario, no por IP, para que
        // una sola cuenta no pueda saturar la cola relanzando el boton.
        RateLimiter::for('mass-scan', function ($request) {
            return Limit::perMinute(6)->by('mass-scan:'.$request->user()?->id);
        });

        // Exportacion a PDF: genera el reporte completo del inventario en
        // cada llamada (consulta + renderizado), tambien costosa.
        RateLimiter::for('exports', function ($request) {
            return Limit::perMinute(10)->by('exports:'.$request->user()?->id);
        });
    }
}
