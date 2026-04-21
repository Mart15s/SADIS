<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\AuthenticatedUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('profile');

        return response()->json(
            AuthenticatedUserResource::make($user)->resolve()
        );
    }
}
