<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(\App\Modules\System\RuntimeTelemetry::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['events']->listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, function ($event) {
            try {
                $module = \App\Modules\System\RuntimeTelemetry::httpModule(
                    $event->request->route()?->getActionName() ?? '', $event->request->path(), $event->response->getStatusCode());
                if (!$module) return;
                $status = $event->response->getStatusCode();
                $elapsed = max(0, (microtime(true) - (float) $event->request->server('REQUEST_TIME_FLOAT', microtime(true))) * 1000);
                $this->app->terminating(function () use ($module, $status, $elapsed) {
                    try { $this->app->make(\App\Modules\System\RuntimeTelemetry::class)->record($module, $status, $elapsed); } catch (\Throwable $e) {}
                });
            } catch (\Throwable $e) {}
        });
        $this->app['events']->listen(\Illuminate\Console\Events\CommandStarting::class, function ($event) {
            try { $this->app->make(\App\Modules\System\RuntimeTelemetry::class)->commandStarted((string) $event->command); } catch (\Throwable $e) {}
        });
        $this->app['events']->listen(\Illuminate\Console\Events\CommandFinished::class, function ($event) {
            try { $this->app->make(\App\Modules\System\RuntimeTelemetry::class)->commandFinished((string) $event->command, (int) $event->exitCode); } catch (\Throwable $e) {}
        });
        $this->app['events']->listen(\Illuminate\Mail\Events\MessageSending::class, function () {
            if (!config('outbound.enabled', true)) {
                return false;
            }
        });
        $this->app['view']->addNamespace('theme', public_path() . '/theme');
    }
}
