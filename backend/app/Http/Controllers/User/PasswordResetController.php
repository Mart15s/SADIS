<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Boundaries\EmailServerBoundary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    private const FORGOT_MAX_ATTEMPTS = 3;
    private const FORGOT_DECAY_SECONDS = 600;
    private const RESET_LINK_SENT_MESSAGE = 'If the email address exists, a password reset link has been sent.';

    public function __construct(
        private readonly EmailServerBoundary $emailServerBoundary
    ) {
    }

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $throttleKey = 'pwreset|'.Str::lower($validated['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::FORGOT_MAX_ATTEMPTS)) {
            $retryAfter = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Too many password reset requests. Try again in {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ], 429);
        }

        RateLimiter::hit($throttleKey, self::FORGOT_DECAY_SECONDS);

        $user = User::query()->where('email', $validated['email'])->first();

        if ($user) {
            $token = Password::broker()->createToken($user);
            $this->emailServerBoundary->sendPasswordResetLink($user, $token);
        }

        return response()->json([
            'message' => self::RESET_LINK_SENT_MESSAGE,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'reset_code' => ['required_without:token', 'nullable', 'string'],
            'token' => ['required_without:reset_code', 'nullable', 'string'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::broker()->reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'] ?? $validated['reset_code'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'reset_code' => null,
                ])->save();

                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'The provided password reset token is invalid or expired.',
            ], 422);
        }

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
