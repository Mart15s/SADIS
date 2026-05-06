<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use App\Services\Admin\AdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request, AdminService $service)
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'in:owner,admin'],
        ]);

        return UserResource::collection($service->listUsers($validated));
    }

    public function show(User $user, AdminService $service): UserResource
    {
        return UserResource::make($service->getUser((int) $user->getKey()));
    }

    public function updateRole(
        UpdateUserRoleRequest $request,
        User $user,
        AdminService $service
    ): UserResource {
        return UserResource::make(
            $service->updateUserRole($user, $request->validated('role'))
        );
    }

    public function destroy(User $user, AdminService $service): JsonResponse
    {
        $service->deleteUser($user);

        return response()->json([
            'message' => "Naudotojas s\u{117}kmingai pa\u{161}alintas",
        ]);
    }
}
