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
            $override = $membership->permissions->firstWhere('permission', $permission);
            if ($override) {
                return (bool) $override->allowed;
            }

            return match ($membership->role) {
                'owner', 'admin' => true,
                'manager' => in_array($permission, ['view_farm', 'manage_fields', 'manage_crops', 'manage_tasks', 'manage_inventory', 'view_analytics'], true),
                'worker' => in_array($permission, ['view_farm', 'manage_tasks'], true),
                default => $permission === 'view_farm',
            };
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
        $role = CommunityMembership::query()
            ->where('community_id', $communityId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');

        if (! $role) {
            return false;
        }

        return match ($permission) {
            'view' => true,
            'manage_resources' => in_array($role, ['admin', 'resource_manager'], true),
            'manage_tasks', 'manage_inventory' => in_array($role, ['admin', 'coordinator'], true),
            default => $role === 'admin',
        };
    }
}
