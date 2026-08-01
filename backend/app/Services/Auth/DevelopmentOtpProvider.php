<?php

namespace App\Services\Auth;

use App\Contracts\OtpProvider;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DevelopmentOtpProvider implements OtpProvider
{
    public function send(string $phone, string $code, string $purpose): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('OTP_PROVIDER=development is forbidden in production. Configure a real OTP provider.');
        }

        Log::debug('Development OTP generated.', ['phone_suffix' => substr($phone, -4), 'purpose' => $purpose]);
    }
}
