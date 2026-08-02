<?php

namespace App\Http\Controllers\Yava;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Farm;
use App\Models\FarmCommunityLink;
use App\Models\FarmMemberPermission;
use App\Models\FarmMembership;
use App\Services\Yava\FarmService;
use App\Services\Yava\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FarmController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->role?->value === 'admin') {
            return response()->json(['data' => Farm::query()->orderBy('name')->get()]);
        }
        $direct = Farm::query()->whereHas('memberships', fn ($q) => $q->where('user_id', $request->user()->id)->where('status', 'active'))
            ->orderBy('name')->get()->map(fn (Farm $farm) => $farm->toArray() + ['access_level' => 'member']);
        $linked = FarmCommunityLink::query()->with('farm:id,name,area_square_metres,state_code,district,timezone')
            ->where('status', 'active')->whereJsonContains('farm_access_permissions', 'view_farm')
            ->whereHas('community.memberships', fn ($q) => $q->where('user_id', $request->user()->id)->where('status', 'active'))
            ->get()->reject(fn ($link) => $direct->contains('id', $link->farm_id))
            ->map(fn ($link) => [
                'id' => $link->farm->id, 'name' => $link->farm->name,
                'area_square_metres' => $link->farm->area_square_metres,
                'state_code' => $link->farm->state_code, 'district' => $link->farm->district,
                'timezone' => $link->farm->timezone, 'access_level' => 'community_link',
            ]);

        return response()->json(['data' => $direct->concat($linked)->values()]);
    }

    public function store(Request $request, FarmService $service)
    {
        return response()->json(['data' => $service->createFarm($request->user(), $request->validate($this->rules()))], 201);
    }

    public function show(Request $request, Farm $farm, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $farm, 'view_farm');

        $canManageMembers = $permissions->hasFarmPermission($request->user(), $farm, 'manage_members');
        $farm->load(['fields', 'memberships.user.profile', 'memberships.permissions', 'communities']);
        $payload = $farm->toArray();
        $payload['memberships'] = $farm->memberships
            ->map(fn (FarmMembership $membership) => $this->memberPayload($membership, $canManageMembers))
            ->values();

        return response()->json(['data' => $payload]);
    }

    public function update(Request $request, Farm $farm, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $farm, 'manage_members');
        $farm->update($request->validate($this->rules(true)));

        return response()->json(['data' => $farm->fresh()]);
    }

    public function destroy(Request $request, Farm $farm, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $farm, 'manage_members');
        $ownerCount = FarmMembership::query()->where('farm_id', $farm->id)->where('role', 'owner')->where('status', 'active')->count();
        if ($ownerCount < 1) {
            throw ValidationException::withMessages(['farm' => ['A farm must retain an active owner.']]);
        }
        $farm->delete();

        return response()->json(null, 204);
    }

    public function members(Request $request, Farm $farm, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $farm, 'view_farm');

        $canManageMembers = $permissions->hasFarmPermission($request->user(), $farm, 'manage_members');
        $memberships = FarmMembership::query()->with(['user.profile', 'permissions'])->where('farm_id', $farm->id)->get()
            ->map(fn (FarmMembership $membership) => $this->memberPayload($membership, $canManageMembers));

        return response()->json(['data' => $memberships]);
    }

    public function addMember(Request $request, Farm $farm, PermissionService $permissions)
    {
        $permissions->authorizeFarm($request->user(), $farm, 'manage_members');
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['required', 'in:owner,admin,manager,worker,viewer'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', PermissionService::FARM_PERMISSIONS)],
        ]);
        $membership = DB::transaction(function () use ($farm, $data, $request): FarmMembership {
            $membership = FarmMembership::query()->updateOrCreate(
                ['farm_id' => $farm->id, 'user_id' => $data['user_id']],
                ['role' => $data['role'], 'status' => 'active', 'invited_by_user_id' => $request->user()->id, 'joined_at' => now(), 'revoked_at' => null]
            );
            foreach ($data['permissions'] ?? [] as $permission) {
                FarmMemberPermission::query()->updateOrCreate(['farm_membership_id' => $membership->id, 'permission' => $permission], ['allowed' => true]);
            }

            return $membership;
        });

        return response()->json(['data' => $membership->load('permissions')], 201);
    }

    public function updateMember(Request $request, Farm $farm, FarmMembership $membership, PermissionService $permissions)
    {
        abort_unless($membership->farm_id === $farm->id, 404);
        $permissions->authorizeFarm($request->user(), $farm, 'manage_members');
        $data = $request->validate([
            'role' => ['sometimes', 'in:owner,admin,manager,worker,viewer'],
            'status' => ['sometimes', 'in:active,revoked'],
            'permissions' => ['sometimes', 'array'], 'permissions.*' => ['string', 'in:'.implode(',', PermissionService::FARM_PERMISSIONS)],
        ]);
        $membership = DB::transaction(function () use ($membership, $farm, $data): FarmMembership {
            Farm::query()->lockForUpdate()->findOrFail($farm->id);
            $locked = FarmMembership::query()->lockForUpdate()->findOrFail($membership->id);
            $removesOwner = $locked->role === 'owner' && $locked->status === 'active'
                && (($data['role'] ?? 'owner') !== 'owner' || ($data['status'] ?? 'active') !== 'active');
            if ($removesOwner && FarmMembership::query()->where('farm_id', $farm->id)->where('role', 'owner')->where('status', 'active')->count() === 1) {
                throw ValidationException::withMessages(['membership' => ['Transfer farm ownership before removing or demoting the sole owner.']]);
            }
            $locked->update(array_filter([
                'role' => $data['role'] ?? null, 'status' => $data['status'] ?? null,
                'revoked_at' => ($data['status'] ?? null) === 'revoked' ? now() : null,
            ], fn ($value) => $value !== null));
            if (array_key_exists('permissions', $data)) {
                $locked->permissions()->delete();
                foreach ($data['permissions'] as $permission) {
                    FarmMemberPermission::query()->create(['farm_membership_id' => $locked->id, 'permission' => $permission, 'allowed' => true]);
                }
            }

            return $locked->fresh('permissions');
        }, 3);

        return response()->json(['data' => $membership]);
    }

    public function linkCommunity(Request $request, Farm $farm, Community $community, PermissionService $permissions, FarmService $service)
    {
        $permissions->authorizeFarm($request->user(), $farm, 'manage_members');
        $data = $request->validate([
            'analytics_scopes' => ['sometimes', 'array'],
            'analytics_scopes.*' => ['string', 'in:crop_summary,harvest_summary,task_summary'],
            'farm_access_permissions' => ['sometimes', 'array'],
            'farm_access_permissions.*' => ['string', 'in:'.implode(',', PermissionService::FARM_PERMISSIONS)],
        ]);

        return response()->json(['data' => $service->linkFarm($request->user(), $farm, $community, $data)], 201);
    }

    public function communityLinks(Request $request, PermissionService $permissions)
    {
        $data = $request->validate([
            'farm_id' => ['nullable', 'integer', 'exists:farms,id'],
            'community_id' => ['nullable', 'integer', 'exists:communities,id'],
        ]);
        if ((isset($data['farm_id']) ? 1 : 0) + (isset($data['community_id']) ? 1 : 0) !== 1) {
            throw ValidationException::withMessages(['scope' => ['Choose exactly one farm or community.']]);
        }

        $query = FarmCommunityLink::query()->with(['farm:id,name', 'community:id,name']);
        if (isset($data['farm_id'])) {
            $permissions->authorizeFarm($request->user(), (int) $data['farm_id'], 'manage_members');
            $query->where('farm_id', $data['farm_id']);
        } else {
            $permissions->authorizeCommunity($request->user(), (int) $data['community_id'], 'manage_members');
            $query->where('community_id', $data['community_id']);
        }

        $links = $query->orderByDesc('requested_at')->orderByDesc('id')->get()->map(fn (FarmCommunityLink $link) => [
            'id' => $link->id,
            'status' => $link->status,
            'farm' => $link->farm?->only(['id', 'name']),
            'community' => $link->community?->only(['id', 'name']),
            'analytics_scopes' => $link->analytics_scopes ?? [],
            'farm_access_permissions' => $link->farm_access_permissions ?? [],
            'requested_at' => $link->requested_at,
            'approved_at' => $link->approved_at,
            'revoked_at' => $link->revoked_at,
        ]);

        return response()->json(['data' => $links]);
    }

    public function decideCommunityLink(Request $request, FarmCommunityLink $link, string $decision, PermissionService $permissions, FarmService $service)
    {
        $permissions->authorizeCommunity($request->user(), $link->community_id, 'manage_members');
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);

        return response()->json(['data' => $service->decideLink($request->user(), $link, $decision)]);
    }

    public function revokeCommunity(Request $request, Farm $farm, FarmCommunityLink $link, PermissionService $permissions, FarmService $service)
    {
        abort_unless($link->farm_id === $farm->id, 404);
        $canFarm = $permissions->hasFarmPermission($request->user(), $farm, 'manage_members');
        $canCommunity = $permissions->hasCommunityPermission($request->user(), $link->community_id, 'manage_members');
        abort_unless($canFarm || $canCommunity, 403, 'Either an authorized farm manager or Community Admin may revoke this link.');
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;
        $service->revokeLink($request->user(), $link, $reason);

        return response()->json(null, 204);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'], 'description' => ['nullable', 'string'],
            'area_square_metres' => ['sometimes', 'numeric', 'min:0'], 'timezone' => ['sometimes', 'timezone:all'],
            'country_code' => ['sometimes', 'string', 'size:2'], 'state_code' => ['nullable', 'string', 'max:10'],
            'district' => ['nullable', 'string', 'max:150'], 'taluk' => ['nullable', 'string', 'max:150'],
            'locality' => ['nullable', 'string', 'max:150'], 'postal_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function memberPayload(FarmMembership $membership, bool $includePrivateContact): array
    {
        $user = $membership->user;
        $payload = $membership->withoutRelations()->toArray();
        $payload['user'] = array_filter([
            'id' => $user?->id,
            'name' => $user?->profile?->name,
            'surname' => $user?->profile?->surname,
            'email' => $includePrivateContact ? $user?->email : null,
            'phone' => $includePrivateContact ? $user?->phone : null,
        ], static fn ($value) => $value !== null);
        if ($includePrivateContact) {
            $payload['permissions'] = $membership->permissions->toArray();
        }

        return $payload;
    }
}
