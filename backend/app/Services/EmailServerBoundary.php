<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Mail;

class EmailServerBoundary
{
    public function sendPasswordResetCode(User $user): void
    {
        Mail::raw(
            "Your SAD System password reset code is: {$user->reset_code}",
            static fn ($message) => $message
                ->to($user->email)
                ->subject('SAD System Password Reset Code')
        );
    }
}
