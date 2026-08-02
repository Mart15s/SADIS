<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;

class LogoutController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        $accessToken = $request->user()->currentAccessToken();
        if ($accessToken && ! $accessToken instanceof TransientToken) {
            $accessToken->delete();
        }
        if ($request->hasSession()) {
            auth('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out successfully.']);
    }
}
