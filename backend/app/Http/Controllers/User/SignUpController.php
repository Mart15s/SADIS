<?php

namespace App\Http\Controllers\User;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\GardenOwner;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SignUpController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:8'],
            'name' => ['required', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
        ]);

        $payload = DB::transaction(function () use ($validated) {
            $user = User::create([
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => UserRole::Owner,
            ]);

            $profile = Profile::create([
                'user_id' => $user->id,
                'name' => $validated['name'],
                'surname' => $validated['surname'],
                'last_login' => now(),
            ]);

            GardenOwner::create([
                'id' => $user->id,
                'user_id' => $user->id,
                'id_user' => $user->id,
                'fk_profile_id' => $profile->id,
            ]);

            $token = $user->createToken('auth-token')->plainTextToken;

            return [$user, $profile, $token];
        });

        [$user, $profile, $token] = $payload;

        return response()->json([
            'token' => $token,
            'user' => $user,
            'profile' => $profile,
        ], 201);
    }
}
