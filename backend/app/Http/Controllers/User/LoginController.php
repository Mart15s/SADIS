<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 60;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'], 'password' => ['required', 'string'],
        ], [
            'email.required' => 'Enter your email address.', 'email.email' => 'Enter a valid email address.',
            'password.required' => 'Enter your password.',
        ]);
        $key = Str::lower($validated['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $retryAfter = RateLimiter::availableIn($key);
            return response()->json(['message' => "Too many login attempts. Try again in {$retryAfter} seconds.", 'retry_after' => $retryAfter], 429);
        }
        $user = User::query()->with('profile')->where('email', $validated['email'])->first();
        if (! $user || $user->status === 'deactivated' || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }
        RateLimiter::clear($key);
        $user->profile?->update(['last_login' => now()]);
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }
        $response = ['user' => $user, 'profile' => $user->profile];
        if (config('auth_api.emit_legacy_token')) {
            $response['token'] = $user->createToken('legacy-api-token')->plainTextToken;
        }
        return response()->json($response);
    }
}
