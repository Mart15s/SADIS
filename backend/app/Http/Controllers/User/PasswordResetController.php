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
use Throwable;

class PasswordResetController extends Controller
{
    private const RESET_LINK_SENT_MESSAGE = 'If the email address is registered, a password reset link has been sent.';

    public function __construct(private readonly EmailServerBoundary $emailServerBoundary) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']], [
            'email.required' => 'Enter your email address.', 'email.email' => 'Enter a valid email address.',
        ]);
        $key = 'pwreset|'.Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $retry = RateLimiter::availableIn($key);

            return response()->json(['message' => "Too many password reset requests. Try again in {$retry} seconds.", 'retry_after' => $retry], 429);
        }
        RateLimiter::hit($key, 600);
        if ($user = User::query()->where('email', $data['email'])->first()) {
            try {
                $this->emailServerBoundary->sendPasswordResetLink($user, Password::broker()->createToken($user));
            } catch (Throwable $exception) {
                report($exception);

                return response()->json(['message' => 'The reset email could not be sent. Check the email service configuration.'], 503);
            }
        }

        return response()->json(['message' => self::RESET_LINK_SENT_MESSAGE]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'reset_code' => ['required_without:token', 'nullable', 'string'],
            'token' => ['required_without:reset_code', 'nullable', 'string'], 'password' => ['required', 'confirmed', 'min:8'],
        ], [
            'email.required' => 'Enter your email address.', 'email.email' => 'Enter a valid email address.',
            'reset_code.required_without' => 'Provide the password reset code.', 'token.required_without' => 'Provide the password reset token.',
            'password.required' => 'Enter a new password.', 'password.confirmed' => 'The password confirmation does not match.',
            'password.min' => 'The password must be at least 8 characters.',
        ]);
        $status = Password::broker()->reset([
            'email' => $data['email'], 'password' => $data['password'],
            'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token'] ?? $data['reset_code'],
        ], function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'reset_code' => null])->save();
            $user->tokens()->delete();
        });
        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'The password reset code is invalid or has expired.'], 422);
        }

        return response()->json(['message' => 'Your password has been updated successfully.']);
    }
}
