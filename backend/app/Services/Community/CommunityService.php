<?php

namespace App\Services\Community;

use App\Models\CommunityPost;
use App\Models\GardenOwner;
use App\Models\Plot;
use App\Models\Profile;
use App\Services\Plot\AccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CommunityService
{
    public function __construct(
        private readonly AccessService $accessService,
    ) {
    }

    /**
     * @return Collection<int, CommunityPost>
     */
    public function listPublicFeed(): Collection
    {
        return $this->baseQuery()
            ->where('share', true)
            ->where(function (Builder $query) {
                $query
                    ->whereNull('plot_id')
                    ->orWhereHas('plot', fn (Builder $plotQuery) => $plotQuery->where('share', true));
            })
            ->get();
    }

    /**
     * @return Collection<int, CommunityPost>
     */
    public function listFeed(GardenOwner $owner): Collection
    {
        $accessiblePlotIds = $this->accessService->accessiblePlotIds($owner);

        return $this->baseQuery()
            ->where(function (Builder $query) use ($owner, $accessiblePlotIds) {
                $query
                    ->where('share', true)
                    ->orWhere(function (Builder $ownedPostsQuery) use ($owner) {
                        $ownedPostsQuery
                            ->where('fk_owner_id', $owner->id_user)
                            ->where('fk_profile_id', $owner->fk_profile_id);
                    })
                    ->orWhere(function (Builder $accessiblePlotsQuery) use ($accessiblePlotIds) {
                        if ($accessiblePlotIds === []) {
                            $accessiblePlotsQuery->whereRaw('0 = 1');

                            return;
                        }

                        $accessiblePlotsQuery->whereIn('fk_plot_id', $accessiblePlotIds);
                    });
            })
            ->get();
    }

    /**
     * @return Collection<int, CommunityPost>
     */
    public function listByPlot(GardenOwner $owner, Plot $plot): Collection
    {
        $this->ensureOwnerCanAccessPlot(
            $owner,
            $plot,
            'You do not have permission to view community posts for this plot.'
        );

        return $this->baseQuery()
            ->where('fk_plot_id', $plot->id)
            ->get();
    }

    public function createPost(GardenOwner $owner, Profile $profile, array $data): CommunityPost
    {
        $plot = $this->resolveAccessiblePlot(
            $owner,
            $data['fk_plot_id'] ?? null,
            'You do not have permission to create a post for this plot.'
        );

        $post = DB::transaction(function () use ($owner, $profile, $data, $plot) {
            return CommunityPost::query()->create([
                'garden_owner_id' => $owner->id,
                'name' => $data['name'],
                'text' => $data['text'],
                'share' => $data['share'],
                'created_at' => now(),
                'fk_owner_id' => $owner->id_user,
                'fk_profile_id' => $profile->id,
                'plot_id' => $plot?->id,
                'fk_plot_id' => $plot?->id,
            ]);
        });

        return $post->load(['profile', 'plot']);
    }

    public function updatePost(GardenOwner $owner, CommunityPost $post, array $data): CommunityPost
    {
        $this->ensurePostOwnership($owner, $post, 'Only the post author can edit this post.');

        if (array_key_exists('fk_plot_id', $data)) {
            $plot = $this->resolveAccessiblePlot(
                $owner,
                $data['fk_plot_id'],
                'You do not have permission to assign this post to the selected plot.'
            );

            $data['fk_plot_id'] = $plot?->id;
            $data['plot_id'] = $plot?->id;
        }

        $post->fill($data);
        $post->save();

        return $post->fresh(['profile', 'plot']);
    }

    public function deletePost(GardenOwner $owner, CommunityPost $post): void
    {
        $this->ensurePostOwnership($owner, $post, 'Only the post author can delete this post.');

        $post->delete();
    }

    private function baseQuery(): Builder
    {
        return CommunityPost::query()
            ->with([
                'profile',
                'plot:id,name,plot_size,geometry',
                'plot.plantZones:id,name,plot_id,fk_plot_id,geometry',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function ensureOwnerCanAccessPlot(GardenOwner $owner, Plot $plot, string $message): void
    {
        if (! $this->accessService->userHasAccess($owner, $plot)) {
            throw new AuthorizationException($message);
        }
    }

    private function ensurePostOwnership(GardenOwner $owner, CommunityPost $post, string $message): void
    {
        if ($post->fk_owner_id !== $owner->id_user || $post->fk_profile_id !== $owner->fk_profile_id) {
            throw new AuthorizationException($message);
        }
    }

    private function resolveAccessiblePlot(GardenOwner $owner, mixed $plotId, string $message): ?Plot
    {
        if ($plotId === null) {
            return null;
        }

        $plot = Plot::query()->findOrFail($plotId);

        $this->ensureOwnerCanAccessPlot($owner, $plot, $message);

        return $plot;
    }
}
