<?php

namespace App\Providers;

use App\Support\MfaSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

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
        $this->loadViewsFrom(base_path('vendor/kernelbridge/licensing-client-laravel/resources/views'), 'kernelbridge-licensing');
        $this->publishes([
            base_path('vendor/kernelbridge/licensing-client-laravel/public') => public_path('vendor/kernelbridge-licensing'),
        ], 'kernelbridge-licensing-assets');

        foreach ([TwoFactorAuthenticationConfirmed::class, ValidTwoFactorAuthenticationCodeProvided::class] as $event) {
            Event::listen($event, function ($event): void {
                MfaSession::markVerified(request(), $event->user);
            });
        }
    }
}
