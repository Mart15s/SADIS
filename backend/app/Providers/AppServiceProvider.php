<?php

namespace App\Providers;

use App\Contracts\OtpProvider;
use App\Services\Auth\DevelopmentOtpProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OtpProvider::class, function () {
            $provider = config('otp.provider');
            if ($provider !== 'development') {
                throw new RuntimeException("OTP provider [{$provider}] is not installed. Configure a production OtpProvider implementation.");
            }

            return new DevelopmentOtpProvider;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('registration', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(15)->by($request->ip()),
        ]);
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(20)->by(($request->input('phone') ?: 'unknown').'|'.$request->ip()),
        ]);
    }
}
