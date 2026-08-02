<?php

namespace App\Services\Yava;

use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\Farm;
use App\Models\FarmCommunityLink;
use App\Models\FarmCommunityLinkEvent;
use App\Models\FarmMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FarmService
{
    public function createCommunity(User $user, array $data): Community
    {
        return DB::transaction(function () use ($user, $data): Community {
            $community = Community::query()->create($data + [
                'slug' => $this->uniqueSlug(Community::class, $data['name']),
                'created_by_user_id' => $user->id,
            ]);
            CommunityMembership::query()->create([
                'community_id' => $community->id, 'user_id' => $user->id,
                'role' => 'admin', 'status' => 'active', 'approved_by_user_id' => $user->id, 'joined_at' => now(),
            ]);

            return $community;
        });
    }

    public function createFarm(User $user, array $data): Farm
    {
        return DB::transaction(function () use ($user, $data): Farm {
            $farm = Farm::query()->create($data + [
                'slug' => $this->uniqueSlug(Farm::class, $data['name']),
                'created_by_user_id' => $user->id,
            ]);
            FarmMembership::query()->create([
                'farm_id' => $farm->id, 'user_id' => $user->id,
                'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
            ]);

            return $farm;
        });
    }

    public function linkFarm(User $user, Farm $farm, Community $community, array $data): FarmCommunityLink
    {
        return DB::transaction(function () use ($user, $farm, $community, $data): FarmCommunityLink {
            $link = FarmCommunityLink::query()->updateOrCreate(
                ['farm_id' => $farm->id, 'community_id' => $community->id],
                [
                    'status' => 'pending',
                    'linked_by_user_id' => $user->id,
                    'approved_by_user_id' => null,
                    'analytics_scopes' => $data['analytics_scopes'] ?? [],
                    'farm_access_permissions' => $data['farm_access_permissions'] ?? [],
                    'requested_at' => now(),
                    'approved_at' => null,
                    'revoked_at' => null,
                ]
            );
            FarmCommunityLinkEvent::query()->create([
                'farm_community_link_id' => $link->id, 'actor_user_id' => $user->id,
                'event' => 'linked', 'to_status' => $link->status,
                'context' => ['analytics_scopes' => $link->analytics_scopes, 'farm_access_permissions' => $link->farm_access_permissions],
            ]);

            return $link;
        });
    }

    public function decideLink(User $user, FarmCommunityLink $link, string $decision): FarmCommunityLink
    {
        return DB::transaction(function () use ($user, $link, $decision): FarmCommunityLink {
            $locked = FarmCommunityLink::query()->lockForUpdate()->findOrFail($link->id);
            abort_unless($locked->status === 'pending', 422, 'This farm-community request has already been decided.');
            $status = $decision === 'approve' ? 'active' : 'rejected';
            $locked->update([
                'status' => $status, 'approved_by_user_id' => $user->id,
                'approved_at' => $status === 'active' ? now() : null,
            ]);
            FarmCommunityLinkEvent::query()->create([
                'farm_community_link_id' => $locked->id, 'actor_user_id' => $user->id,
                'event' => $decision === 'approve' ? 'approved' : 'rejected',
                'from_status' => 'pending', 'to_status' => $status,
            ]);

            return $locked->fresh();
        }, 3);
    }

    public function revokeLink(User $user, FarmCommunityLink $link, ?string $reason): void
    {
        DB::transaction(function () use ($user, $link, $reason): void {
            $locked = FarmCommunityLink::query()->lockForUpdate()->findOrFail($link->id);
            $previous = $locked->status;
            $locked->update(['status' => 'revoked', 'revoked_at' => now(), 'revocation_reason' => $reason]);
            FarmCommunityLinkEvent::query()->create([
                'farm_community_link_id' => $locked->id, 'actor_user_id' => $user->id,
                'event' => 'revoked', 'from_status' => $previous, 'to_status' => 'revoked',
                'context' => ['reason' => $reason],
            ]);
        });
    }

    private function uniqueSlug(string $model, string $name): string
    {
        $base = Str::slug($name) ?: 'yava';
        $slug = $base;
        for ($suffix = 2; $model::withTrashed()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
