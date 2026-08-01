<?php

namespace App\Http\Controllers\User;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\GardenOwner;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SignUpController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:8'],
            'name' => ['required', 'string', 'max:255'], 'surname' => ['required', 'string', 'max:255'],
            'locale' => ['sometimes', 'in:en,hi'],
        ], [
            'email.required' => 'Enter your email address.', 'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email address is already in use.', 'password.required' => 'Enter a password.',
            'password.confirmed' => 'The password confirmation does not match.',
            'password.min' => 'The password must be at least 8 characters.',
            'name.required' => 'Enter your first name.', 'surname.required' => 'Enter your surname.',
        ]);
        [$user, $profile] = DB::transaction(function () use ($validated): array {
            $user = User::query()->create([
                'email' => $validated['email'], 'password' => $validated['password'],
                'role' => UserRole::Owner, 'locale' => $validated['locale'] ?? 'en',
            ]);
            $profile = Profile::query()->create([
                'user_id' => $user->id, 'name' => $validated['name'], 'surname' => $validated['surname'], 'last_login' => now(),
            ]);
            GardenOwner::query()->create([
                'id' => $user->id, 'user_id' => $user->id, 'id_user' => $user->id, 'fk_profile_id' => $profile->id,
            ]);
            return [$user, $profile];
        });
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }
        $response = ['user' => $user, 'profile' => $profile];
        if (config('auth_api.emit_legacy_token')) {
            $response['token'] = $user->createToken('legacy-api-token')->plainTextToken;
        }
        return response()->json($response, 201);
    }
}
