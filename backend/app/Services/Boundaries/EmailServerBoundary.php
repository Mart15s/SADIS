<?php

namespace App\Services\Boundaries;

use App\Mail\PasswordResetLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class EmailServerBoundary
{
    public function sendPasswordResetLink(User $user, string $token): void
    {
        $this->ensureDeliverableMailerConfigured();

        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $query = http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]);

        Mail::to($user->email)->send(
            new PasswordResetLinkMail("{$frontendUrl}/reset-password?{$query}")
        );
    }

    private function ensureDeliverableMailerConfigured(): void
    {
        $mailer = (string) config('mail.default', 'log');

        if (app()->environment('testing') || ! in_array($mailer, ['log', 'array'], true)) {
            return;
        }

        Log::error('Password reset email cannot be delivered because a non-delivering mailer is configured.', [
            'mail_mailer' => $mailer,
            'app_env' => config('app.env'),
        ]);

        throw new RuntimeException('Email server is not configured for real password reset delivery.');
    }
}
