<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailServerBoundary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    private const FORGOT_MAX_ATTEMPTS = 3;
    private const FORGOT_DECAY_SECONDS = 600;

    public function __construct(
        private readonly EmailServerBoundary $emailServerBoundary
    ) {
    }

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
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

        $user = User::query()->where('email', $validated['email'])->firstOrFail();
        $user->update([
            'reset_code' => Str::upper(Str::random(6)),
        ]);

        $this->emailServerBoundary->sendPasswordResetCode($user);

        return response()->json([
            'message' => 'Password reset code sent successfully.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'reset_code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $user = User::query()
            ->where('email', $validated['email'])
            ->where('reset_code', $validated['reset_code'])
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'The provided reset code is invalid.',
            ], 422);
        }

        $user->update([
            'password' => $validated['password'],
            'reset_code' => null,
        ]);

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
