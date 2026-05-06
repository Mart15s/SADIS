<?php

namespace App\Services\Boundaries;

use App\Mail\PasswordResetLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class EmailServerBoundary
{
    public function sendPasswordResetLink(User $user, string $token): void
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $query = http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]);

        Mail::to($user->email)->send(
            new PasswordResetLinkMail("{$frontendUrl}/reset-password?{$query}")
        );
    }
}
