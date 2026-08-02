<?php

namespace App\Services\Yava;

use App\Models\Community;
use App\Models\CommunityInvitation;
use App\Models\CommunityMembership;
use App\Models\Crop;
use App\Models\CropSeason;
use App\Models\Farm;
use App\Models\FarmCommunityLink;
use App\Models\Field;
use App\Models\OnboardingProgress;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnboardingService
{
    public function __construct(
        private readonly FarmService $farms,
        private readonly PermissionService $permissions,
        private readonly OtpService $otp,
    ) {}

    public function save(
        User $user,
        string $currentStep,
        ?array $completedSteps,
        ?array $draft,
        bool $complete,
    ): array {
        return DB::transaction(function () use ($user, $currentStep, $completedSteps, $draft, $complete): array {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $progress = OnboardingProgress::query()->lockForUpdate()->firstOrCreate(
                ['user_id' => $user->id],
                ['current_step' => 'profile', 'completed_steps' => [], 'draft' => []],
            );

            if ($progress->completed_at) {
                return $this->payload($progress);
            }

            $mergedDraft = array_replace($progress->draft ?? [], $draft ?? []);
            $progress->update([
                'current_step' => $currentStep,
                'completed_steps' => $completedSteps ?? $progress->completed_steps ?? [],
                'draft' => $mergedDraft,
            ]);

            if (! $complete) {
                return $this->payload($progress->fresh());
            }

            $validated = $this->validateCompletion($mergedDraft);
            $provisioned = $this->provision($user, $validated);
            $progress->update([
                'current_step' => 'completed',
                'completed_steps' => array_values(array_unique(array_merge(
                    $completedSteps ?? $progress->completed_steps ?? [],
                    ['profile', 'mode', 'farm', 'community', 'field', 'season', 'preferences'],
                ))),
                'draft' => array_replace($mergedDraft, ['provisioned' => $provisioned]),
                'completed_at' => now(),
            ]);

            return $this->payload($progress->fresh(), $provisioned);
        }, 3);
    }

    private function validateCompletion(array $draft): array
    {
        $mode = $draft['mode'] ?? null;
        $farmAction = $draft['farm_action'] ?? null;
        $communityAction = $mode === 'independent' ? 'none' : ($draft['community_action'] ?? null);

        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'mode' => ['required', 'in:independent,community'],
            'farm_action' => ['required', 'in:create,existing'],
            'farm_id' => [$farmAction === 'existing' ? 'required' : 'nullable', 'integer', 'exists:farms,id'],
            'farm_name' => [$farmAction === 'create' ? 'required' : 'nullable', 'string', 'max:255'],
            'farm_area_square_metres' => ['nullable', 'numeric', 'min:0'],
            'state_code' => ['nullable', 'string', 'max:10'],
            'district' => ['nullable', 'string', 'max:150'],
            'locality' => ['nullable', 'string', 'max:150'],
            'timezone' => ['required', 'timezone:all'],
            'community_action' => [$mode === 'community' ? 'required' : 'nullable', 'in:create,existing,invitation,none'],
            'community_id' => [$mode === 'community' && $communityAction === 'existing' ? 'required' : 'nullable', 'integer', 'exists:communities,id'],
            'community_name' => [$mode === 'community' && $communityAction === 'create' ? 'required' : 'nullable', 'string', 'max:255'],
            'invitation_code' => [$mode === 'community' && $communityAction === 'invitation' ? 'required' : 'nullable', 'string', 'max:100'],
            'field_name' => ['required', 'string', 'max:255'],
            'field_area_square_metres' => ['nullable', 'numeric', 'min:0'],
            'soil_type' => ['nullable', 'string', 'max:100'],
            'crop_name' => ['required', 'string', 'max:255'],
            'crop_category' => ['nullable', 'string', 'max:100'],
            'season_name' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'expected_ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'locale' => ['nullable', 'string', 'max:10'],
            'area_unit' => ['nullable', 'in:hectare,acre,square_meter'],
        ];

        $validated = validator($draft, $rules)->validate();
        $validated['community_action'] = $communityAction;

        return $validated;
    }

    private function provision(User $user, array $draft): array
    {
        $profile = $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            ['name' => $draft['first_name'], 'surname' => $draft['last_name']],
        );
        $userUpdates = [];
        if (! empty($draft['phone'])) {
            $userUpdates['phone'] = $this->otp->normalizePhone($draft['phone']);
            if ($user->phone !== $userUpdates['phone']) {
                $userUpdates['phone_verified_at'] = null;
            }
        }
        if (! empty($draft['locale'])) {
            $userUpdates['locale'] = $draft['locale'];
        }
        if ($userUpdates !== []) {
            $user->update($userUpdates);
        }

        $farm = $this->resolveFarm($user, $draft);
        $community = $draft['mode'] === 'community'
            ? $this->resolveCommunity($user, $draft)
            : null;
        $link = $community ? $this->linkFarmToCommunity($user, $farm, $community) : null;

        $field = Field::query()->create([
            'farm_id' => $farm->id,
            'name' => $draft['field_name'],
            'area_square_metres' => $draft['field_area_square_metres'] ?? 0,
            'soil_type' => $draft['soil_type'] ?? null,
        ]);
        $crop = Crop::query()->create([
            'farm_id' => $farm->id,
            'name' => $draft['crop_name'],
            'category' => $draft['crop_category'] ?? null,
            'is_global' => false,
            'created_by_user_id' => $user->id,
        ]);
        $season = CropSeason::query()->create([
            'farm_id' => $farm->id,
            'field_id' => $field->id,
            'field_zone_id' => null,
            'crop_id' => $crop->id,
            'name' => $draft['season_name'] ?? null,
            'starts_on' => $draft['starts_on'],
            'expected_ends_on' => $draft['expected_ends_on'] ?? null,
            'planted_area_square_metres' => $draft['field_area_square_metres'] ?? null,
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);
        DB::table('crop_rotation_entries')->insert([
            'field_id' => $field->id,
            'field_zone_id' => null,
            'crop_season_id' => $season->id,
            'crop_id' => $crop->id,
            'season_year' => (int) $season->starts_on->format('Y'),
            'source' => 'crop_season',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('planning_history')->insert([
            'farm_id' => $farm->id,
            'field_id' => $field->id,
            'actor_user_id' => $user->id,
            'event' => 'onboarding_completed',
            'subject_type' => CropSeason::class,
            'subject_id' => $season->id,
            'after' => json_encode($season->toArray()),
            'created_at' => now(),
        ]);

        return [
            'profile_id' => $profile->id,
            'farm_id' => $farm->id,
            'community_id' => $community?->id,
            'farm_community_link_id' => $link?->id,
            'field_id' => $field->id,
            'crop_id' => $crop->id,
            'crop_season_id' => $season->id,
            'preferred_context' => [
                'type' => 'farm',
                'id' => $farm->id,
                'name' => $farm->name,
                'timezone' => $farm->timezone,
            ],
        ];
    }

    private function resolveFarm(User $user, array $draft): Farm
    {
        if ($draft['farm_action'] === 'existing') {
            $farm = Farm::query()->findOrFail($draft['farm_id']);
            $this->permissions->authorizeFarm($user, $farm, 'manage_fields');
            $this->permissions->authorizeFarm($user, $farm, 'manage_crops');

            return $farm;
        }

        return $this->farms->createFarm($user, array_filter([
            'name' => $draft['farm_name'],
            'area_square_metres' => $draft['farm_area_square_metres'] ?? 0,
            'timezone' => $draft['timezone'],
            'state_code' => $draft['state_code'] ?? null,
            'district' => $draft['district'] ?? null,
            'locality' => $draft['locality'] ?? null,
        ], static fn ($value) => $value !== null));
    }

    private function resolveCommunity(User $user, array $draft): Community
    {
        if ($draft['community_action'] === 'create') {
            return $this->farms->createCommunity($user, array_filter([
                'name' => $draft['community_name'],
                'timezone' => $draft['timezone'],
                'state_code' => $draft['state_code'] ?? null,
                'district' => $draft['district'] ?? null,
                'locality' => $draft['locality'] ?? null,
            ], static fn ($value) => $value !== null));
        }

        if ($draft['community_action'] === 'invitation') {
            return $this->acceptInvitation($user, $draft['invitation_code']);
        }

        $community = Community::query()->findOrFail($draft['community_id']);
        $isMember = CommunityMembership::query()
            ->where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
        if (! $isMember) {
            throw ValidationException::withMessages([
                'community_id' => ['Join this community before selecting it during onboarding.'],
            ]);
        }

        return $community;
    }

    private function acceptInvitation(User $user, string $code): Community
    {
        $invitation = CommunityInvitation::query()
            ->where('code_hash', hash('sha256', Str::upper($code)))
            ->lockForUpdate()
            ->firstOrFail();
        if ($invitation->status !== 'pending' || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages(['invitation_code' => ['This invitation is invalid or has expired.']]);
        }
        if ($invitation->email && strcasecmp($invitation->email, $user->email) !== 0) {
            throw ValidationException::withMessages(['invitation_code' => ['This invitation belongs to another account.']]);
        }
        if ($invitation->phone && (! $user->phone_verified_at || $this->otp->normalizePhone((string) $user->phone) !== $invitation->phone)) {
            throw ValidationException::withMessages(['invitation_code' => ['Verify the invited phone number before accepting this invitation.']]);
        }

        $membership = CommunityMembership::query()->firstOrNew([
            'community_id' => $invitation->community_id,
            'user_id' => $user->id,
        ]);
        if (! $membership->exists || $membership->status !== 'active') {
            $membership->fill([
                'role' => $invitation->role,
                'status' => 'active',
                'approved_by_user_id' => $invitation->invited_by_user_id,
                'joined_at' => now(),
                'revoked_at' => null,
            ])->save();
        }
        $invitation->update(['status' => 'accepted', 'accepted_at' => now()]);

        return Community::query()->findOrFail($invitation->community_id);
    }

    private function linkFarmToCommunity(User $user, Farm $farm, Community $community): FarmCommunityLink
    {
        $existing = FarmCommunityLink::query()
            ->where('farm_id', $farm->id)
            ->where('community_id', $community->id)
            ->first();
        if ($existing && in_array($existing->status, ['active', 'pending'], true)) {
            return $existing;
        }

        $link = $this->farms->linkFarm($user, $farm, $community, [
            'analytics_scopes' => [],
            'farm_access_permissions' => [],
        ]);

        if ($this->permissions->hasCommunityPermission($user, $community, 'manage_members')) {
            $link = $this->farms->decideLink($user, $link, 'approve');
        }

        return $link;
    }

    private function payload(OnboardingProgress $progress, ?array $provisioned = null): array
    {
        $payload = $progress->toArray();
        $payload['provisioned'] = $provisioned ?? Arr::get($progress->draft ?? [], 'provisioned');

        return $payload;
    }
}
