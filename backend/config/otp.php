<?php

return [
    'provider' => env('OTP_PROVIDER', 'development'),
    'expires_seconds' => (int) env('OTP_EXPIRES_SECONDS', 300),
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'development_code' => env('OTP_DEVELOPMENT_CODE'),
];
