<?php

namespace App\Services\Yava;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\Farm;
use App\Models\FarmCommunityLink;
use App\Models\FarmMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class PermissionService
{
    public const FARM_PERMISSIONS = [
        'view_farm', 'manage_fields', 'manage_crops', 'manage_tasks',
        'manage_inventory', 'view_analytics', 'manage_members',
    ];

    public const COMMUNITY_PERMISSIONS = [
        'view', 'manage_resources', 'manage_tasks', 'manage_inventory', 'manage_members',
    ];

    public function authorizeFarm(User $user, Farm|int $farm, string $permission): void
    {
        if (! $this->hasFarmPermission($user, $farm, $permission)) {
            throw new AuthorizationException('You do not have permission to perform this action on the farm.');
        }
    }

    public function hasFarmPermission(User $user, Farm|int $farm, string $permission): bool
    {
        if (! in_array($permission, self::FARM_PERMISSIONS, true)) {
            return false;
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        $farmId = $farm instanceof Farm ? $farm->id : $farm;
        $membership = FarmMembership::query()
            ->with('permissions')
            ->where('farm_id', $farmId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($membership) {
            return in_array($permission, $this->farmPermissionsForMembership($user, $membership), true);
        }

        // A community role alone grants nothing. Only an active link's explicit
        // farm_access_permissions can bridge an active community membership.
        return FarmCommunityLink::query()
            ->where('farm_id', $farmId)
            ->where('status', 'active')
            ->get()
            ->contains(function (FarmCommunityLink $link) use ($user, $permission): bool {
                $isMember = CommunityMembership::query()
                    ->where('community_id', $link->community_id)
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->exists();

                return $isMember && in_array($permission, $link->farm_access_permissions ?? [], true);
            });
    }

    public function authorizeCommunity(User $user, Community|int $community, string $permission = 'view'): void
    {
        if (! $this->hasCommunityPermission($user, $community, $permission)) {
            throw new AuthorizationException('You do not have permission to perform this action in the community.');
        }
    }

    public function hasCommunityPermission(User $user, Community|int $community, string $permission = 'view'): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        $communityId = $community instanceof Community ? $community->id : $community;
        $membership = CommunityMembership::query()
            ->where('community_id', $communityId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return false;
        }

        return in_array($permission, $this->communityPermissionsForMembership($user, $membership), true);
    }

    /** @return list<string> */
    public function farmPermissionsForMembership(User $user, FarmMembership $membership): array
    {
        if ($user->role === UserRole::Admin) {
            return self::FARM_PERMISSIONS;
        }

        if ($membership->user_id !== $user->id || $membership->status !== 'active') {
            return [];
        }

        $membership->loadMissing('permissions');
        $defaults = match ($membership->role) {
            'owner', 'admin' => self::FARM_PERMISSIONS,
            'manager' => ['view_farm', 'manage_fields', 'manage_crops', 'manage_tasks', 'manage_inventory', 'view_analytics'],
            'worker' => ['view_farm', 'manage_tasks'],
            default => ['view_farm'],
        };

        return collect(self::FARM_PERMISSIONS)
            ->filter(function (string $permission) use ($membership, $defaults): bool {
                $override = $membership->permissions->firstWhere('permission', $permission);

                return $override ? (bool) $override->allowed : in_array($permission, $defaults, true);
            })
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function communityPermissionsForMembership(User $user, CommunityMembership $membership): array
    {
        if ($user->role === UserRole::Admin) {
            return self::COMMUNITY_PERMISSIONS;
        }

        if ($membership->user_id !== $user->id || $membership->status !== 'active') {
            return [];
        }

        return match ($membership->role) {
            'admin' => self::COMMUNITY_PERMISSIONS,
            'coordinator' => ['view', 'manage_tasks', 'manage_inventory'],
            'resource_manager' => ['view', 'manage_resources'],
            default => ['view'],
        };
    }
}
