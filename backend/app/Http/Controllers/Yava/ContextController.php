<?php

namespace App\Http\Controllers\Yava;

use App\Http\Controllers\Controller;
use App\Models\CommunityMembership;
use App\Models\FarmCommunityLink;
use App\Models\FarmMembership;
use App\Services\Yava\PermissionService;
use Illuminate\Http\Request;

class ContextController extends Controller
{
    public function index(Request $request, PermissionService $permissions)
    {
        $user = $request->user();

        $farms = FarmMembership::query()->with(['farm', 'permissions'])->whereHas('farm')
            ->where('user_id', $user->id)->where('status', 'active')->get()
            ->map(fn ($membership) => [
                'id' => $membership->farm_id,
                'type' => 'farm',
                'name' => $membership->farm->name,
                'role' => $membership->role,
                'timezone' => $membership->farm->timezone,
                'permissions' => $permissions->farmPermissionsForMembership($user, $membership),
            ]);
        $communities = CommunityMembership::query()->with('community')->whereHas('community')
            ->where('user_id', $user->id)->where('status', 'active')->get()
            ->map(fn ($membership) => [
                'id' => $membership->community_id,
                'type' => 'community',
                'name' => $membership->community->name,
                'role' => $membership->role,
                'timezone' => $membership->community->timezone,
                'permissions' => $permissions->communityPermissionsForMembership($user, $membership),
            ]);

        $directFarmIds = $farms->pluck('id');
        $linkedFarms = FarmCommunityLink::query()
            ->with('farm')
            ->whereHas('farm')
            ->where('status', 'active')
            ->whereIn('community_id', $communities->pluck('id'))
            ->when($directFarmIds->isNotEmpty(), fn ($query) => $query->whereNotIn('farm_id', $directFarmIds))
            ->get()
            ->groupBy('farm_id')
            ->map(function ($links) use ($permissions, $user): ?array {
                $farm = $links->first()->farm;
                $granted = collect(PermissionService::FARM_PERMISSIONS)
                    ->filter(fn (string $permission) => $permissions->hasFarmPermission($user, $farm, $permission))
                    ->values()
                    ->all();

                if ($granted === []) {
                    return null;
                }

                return [
                    'id' => $farm->id,
                    'type' => 'farm',
                    'name' => $farm->name,
                    'role' => 'community_link',
                    'timezone' => $farm->timezone,
                    'permissions' => $granted,
                ];
            })
            ->filter();

        return response()->json(['data' => $farms->concat($linkedFarms)->concat($communities)->values()]);
    }
}
