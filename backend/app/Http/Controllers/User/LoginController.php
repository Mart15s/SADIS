<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 60;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ],
            [
                'email.required' => 'Įveskite el. pašto adresą.',
                'email.email' => 'Įveskite tinkamą el. pašto adresą.',
                'password.required' => 'Įveskite slaptažodį.',
            ],
        );

        $throttleKey = $this->throttleKey($request, $validated['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $retryAfter = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Per daug prisijungimo bandymų. Bandykite dar kartą po {$retryAfter} sek.",
                'retry_after' => $retryAfter,
            ], 429);
        }

        $user = \App\Models\User::query()
            ->with('profile')
            ->where('email', $validated['email'])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            return response()->json([
                'message' => 'Pateikti prisijungimo duomenys neteisingi.',
            ], 422);
        }

        RateLimiter::clear($throttleKey);

        $user->profile?->update([
            'last_login' => now(),
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
            'profile' => $user->profile,
        ]);
    }

    private function throttleKey(Request $request, string $email): string
    {
        return Str::lower($email).'|'.$request->ip();
    }
}
