<?php

namespace App\Http\Controllers\Yava;

use App\Http\Controllers\Controller;
use App\Models\CommunityMembership;
use App\Models\FarmMembership;
use App\Services\Yava\PermissionService;
use Illuminate\Http\Request;

class ContextController extends Controller
{
    public function index(Request $request, PermissionService $permissions)
    {
        $user = $request->user();

        $farms = FarmMembership::query()->with(['farm', 'permissions'])->where('user_id', $user->id)->where('status', 'active')->get()
            ->map(fn ($membership) => [
                'id' => $membership->farm_id,
                'type' => 'farm',
                'name' => $membership->farm->name,
                'role' => $membership->role,
                'timezone' => $membership->farm->timezone,
                'permissions' => $permissions->farmPermissionsForMembership($user, $membership),
            ]);
        $communities = CommunityMembership::query()->with('community')->where('user_id', $user->id)->where('status', 'active')->get()
            ->map(fn ($membership) => [
                'id' => $membership->community_id,
                'type' => 'community',
                'name' => $membership->community->name,
                'role' => $membership->role,
                'timezone' => $membership->community->timezone,
                'permissions' => $permissions->communityPermissionsForMembership($user, $membership),
            ]);

        return response()->json(['data' => $farms->concat($communities)->values()]);
    }
}
