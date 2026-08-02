<?php

namespace Tests\Feature\Yava;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\CommunityInvitation;
use App\Models\CommunityJoinRequest;
use App\Models\CommunityMembership;
use App\Models\Crop;
use App\Models\CropHarvest;
use App\Models\CropSeason;
use App\Models\Farm;
use App\Models\FarmCommunityLink;
use App\Models\FarmMembership;
use App\Models\Profile;
use App\Models\ResourceReservation;
use App\Models\SharedResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageOneBackendHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_resumes_then_provisions_an_independent_workspace_once(): void
    {
        $user = $this->user('onboarding@example.test');
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/onboarding', [
            'current_step' => 'mode',
            'completed_steps' => ['profile'],
            'draft' => ['first_name' => 'Asha', 'last_name' => 'Patel'],
        ])->assertOk()->assertJsonPath('data.draft.first_name', 'Asha')
            ->assertJsonPath('data.provisioned', null);
        $this->assertDatabaseCount('farms', 0);

        $completion = [
            'current_step' => 'review',
            'completed_steps' => ['profile', 'mode', 'farm', 'field'],
            'completed' => true,
            'draft' => [
                'mode' => 'independent', 'farm_action' => 'create', 'farm_name' => 'Onboarding Farm',
                'farm_area_square_metres' => 4000, 'state_code' => 'KA', 'district' => 'Mysuru',
                'locality' => 'Nanjangud', 'timezone' => 'Asia/Kolkata', 'field_name' => 'First Field',
                'field_area_square_metres' => 3000, 'soil_type' => 'loam', 'crop_name' => 'Millet',
                'crop_category' => 'cereal', 'season_name' => 'First Season', 'starts_on' => '2026-06-01',
                'expected_ends_on' => '2026-10-01', 'locale' => 'en-IN', 'area_unit' => 'hectare',
            ],
        ];
        $response = $this->putJson('/api/v1/onboarding', $completion)->assertOk()
            ->assertJsonPath('data.current_step', 'completed')
            ->assertJsonPath('data.provisioned.preferred_context.type', 'farm')
            ->assertJsonPath('data.provisioned.preferred_context.name', 'Onboarding Farm');
        $farmId = $response->json('data.provisioned.farm_id');
        $seasonId = $response->json('data.provisioned.crop_season_id');

        $this->assertDatabaseHas('profiles', ['user_id' => $user->id, 'name' => 'Asha', 'surname' => 'Patel']);
        $this->assertDatabaseHas('farm_memberships', ['farm_id' => $farmId, 'user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('crop_seasons', ['id' => $seasonId, 'field_zone_id' => null, 'status' => 'active']);
        $this->assertDatabaseHas('crop_rotation_entries', ['crop_season_id' => $seasonId]);
        $this->assertDatabaseHas('planning_history', ['farm_id' => $farmId, 'event' => 'onboarding_completed']);
        $this->getJson('/api/v1/contexts')->assertOk()->assertJsonPath('data.0.id', $farmId);

        $this->putJson('/api/v1/onboarding', $completion)->assertOk()
            ->assertJsonPath('data.provisioned.farm_id', $farmId)
            ->assertJsonPath('data.provisioned.crop_season_id', $seasonId);
        $this->assertDatabaseCount('farms', 1);
        $this->assertDatabaseCount('fields', 1);
        $this->assertDatabaseCount('crop_seasons', 1);
    }

    public function test_onboarding_can_accept_an_invitation_and_use_an_existing_farm(): void
    {
        $user = $this->user('invited-onboarding@example.test');
        $admin = $this->user('community-admin@example.test');
        $farm = Farm::query()->create(['name' => 'Existing Farm', 'slug' => 'existing-farm', 'timezone' => 'Asia/Kolkata']);
        FarmMembership::query()->create(['farm_id' => $farm->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $community = Community::query()->create(['name' => 'Existing Circle', 'slug' => 'existing-circle', 'timezone' => 'Asia/Kolkata']);
        CommunityMembership::query()->create(['community_id' => $community->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        $code = 'ONBOARD-INVITE';
        CommunityInvitation::query()->create([
            'community_id' => $community->id, 'invited_by_user_id' => $admin->id, 'email' => $user->email,
            'role' => 'member', 'code_hash' => hash('sha256', $code), 'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/onboarding', [
            'current_step' => 'review', 'completed' => true,
            'draft' => $this->onboardingDraft([
                'mode' => 'community', 'farm_action' => 'existing', 'farm_id' => $farm->id,
                'farm_name' => null, 'community_action' => 'invitation', 'invitation_code' => $code,
            ]),
        ])->assertOk()->assertJsonPath('data.provisioned.community_id', $community->id);

        $this->assertDatabaseHas('community_memberships', [
            'community_id' => $community->id, 'user_id' => $user->id, 'role' => 'member', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('farm_community_links', [
            'farm_id' => $farm->id, 'community_id' => $community->id, 'status' => 'pending',
        ]);
    }

    public function test_membership_alternate_write_paths_preserve_sole_owner_and_active_admin_roles(): void
    {
        $owner = $this->user('sole-owner@example.test');
        Sanctum::actingAs($owner);
        $farmId = $this->postJson('/api/v1/farms', ['name' => 'Sole Farm'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/farms/{$farmId}/members", [
            'user_id' => $owner->id, 'role' => 'viewer',
        ])->assertUnprocessable()->assertJsonValidationErrors('membership');
        $this->assertDatabaseHas('farm_memberships', ['farm_id' => $farmId, 'user_id' => $owner->id, 'role' => 'owner']);

        $communityId = $this->postJson('/api/v1/communities', ['name' => 'Sole Circle'])->assertCreated()->json('data.id');
        $invitation = $this->postJson("/api/v1/communities/{$communityId}/invitations", [
            'email' => $owner->email, 'role' => 'member',
        ])->assertCreated();
        $this->postJson('/api/v1/invitations/'.$invitation->json('invitation_code').'/accept')->assertOk();
        $this->assertDatabaseHas('community_memberships', [
            'community_id' => $communityId, 'user_id' => $owner->id, 'role' => 'admin', 'status' => 'active',
        ]);
        $this->postJson("/api/v1/communities/{$communityId}/join-requests")->assertUnprocessable();

        $join = CommunityJoinRequest::query()->create([
            'community_id' => $communityId, 'user_id' => $owner->id, 'status' => 'pending',
        ]);
        $this->postJson("/api/v1/communities/{$communityId}/join-requests/{$join->id}/approve")->assertOk();
        $this->assertDatabaseHas('community_memberships', [
            'community_id' => $communityId, 'user_id' => $owner->id, 'role' => 'admin', 'status' => 'active',
        ]);
    }

    public function test_linked_farm_users_can_use_resources_without_seeing_other_reservations(): void
    {
        $farmOwner = $this->user('linked-owner@example.test');
        $communityAdmin = $this->user('resource-admin@example.test');
        $otherMember = $this->user('other-member@example.test');
        $farm = Farm::query()->create(['name' => 'Linked Farm', 'slug' => 'linked-farm', 'timezone' => 'Asia/Kolkata']);
        $otherFarm = Farm::query()->create(['name' => 'Unlinked Farm', 'slug' => 'unlinked-farm', 'timezone' => 'Asia/Kolkata']);
        FarmMembership::query()->create(['farm_id' => $farm->id, 'user_id' => $farmOwner->id, 'role' => 'owner', 'status' => 'active']);
        FarmMembership::query()->create(['farm_id' => $otherFarm->id, 'user_id' => $farmOwner->id, 'role' => 'owner', 'status' => 'active']);
        $community = Community::query()->create(['name' => 'Resource Circle', 'slug' => 'resource-circle', 'timezone' => 'Asia/Kolkata']);
        CommunityMembership::query()->create(['community_id' => $community->id, 'user_id' => $communityAdmin->id, 'role' => 'admin', 'status' => 'active']);
        CommunityMembership::query()->create(['community_id' => $community->id, 'user_id' => $otherMember->id, 'role' => 'member', 'status' => 'active']);
        FarmCommunityLink::query()->create(['farm_id' => $farm->id, 'community_id' => $community->id, 'status' => 'active']);
        $resource = SharedResource::query()->create([
            'community_id' => $community->id, 'name' => 'Tractor', 'status' => 'available',
            'timezone' => 'Asia/Kolkata', 'requires_approval' => true,
        ]);
        ResourceReservation::query()->create([
            'shared_resource_id' => $resource->id, 'requested_by_user_id' => $otherMember->id,
            'status' => 'pending', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
        ]);

        Sanctum::actingAs($farmOwner);
        $this->getJson("/api/v1/resources/{$resource->id}")->assertOk()->assertJsonPath('data.name', 'Tractor');
        $reservationId = $this->postJson('/api/v1/reservations', [
            'resource_id' => $resource->id, 'farm_id' => $farm->id,
            'starts_at' => '2030-01-01T08:00', 'ends_at' => '2030-01-01T09:00',
        ])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/reservations?resource_id={$resource->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reservationId);
        $this->postJson('/api/v1/reservations', [
            'resource_id' => $resource->id, 'farm_id' => $otherFarm->id,
            'starts_at' => '2030-01-02T08:00', 'ends_at' => '2030-01-02T09:00',
        ])->assertUnprocessable();
    }

    public function test_deleted_contexts_are_filtered_and_resources_with_history_must_be_retired(): void
    {
        $user = $this->user('deletion@example.test');
        Sanctum::actingAs($user);
        $farmId = $this->postJson('/api/v1/farms', ['name' => 'Disposable Farm'])->assertCreated()->json('data.id');
        $communityId = $this->postJson('/api/v1/communities', ['name' => 'Disposable Circle'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/farms/{$farmId}")->assertNoContent();
        $this->deleteJson("/api/v1/communities/{$communityId}")->assertNoContent();
        $this->getJson('/api/v1/contexts')->assertOk()->assertJsonCount(0, 'data');

        $community = Community::query()->create(['name' => 'History Circle', 'slug' => 'history-circle']);
        CommunityMembership::query()->create(['community_id' => $community->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);
        $resource = SharedResource::query()->create(['community_id' => $community->id, 'name' => 'Harvester']);
        ResourceReservation::query()->create([
            'shared_resource_id' => $resource->id, 'requested_by_user_id' => $user->id,
            'status' => 'completed', 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(),
        ]);
        $this->deleteJson("/api/v1/resources/{$resource->id}")->assertUnprocessable();
        $this->assertDatabaseHas('shared_resources', ['id' => $resource->id, 'deleted_at' => null]);
    }

    public function test_crop_season_updates_enforce_related_identifiers_and_sync_rotation(): void
    {
        $owner = $this->user('crop-owner@example.test');
        Sanctum::actingAs($owner);
        $farmId = $this->postJson('/api/v1/farms', ['name' => 'Rotation Farm'])->json('data.id');
        $fieldA = $this->postJson('/api/v1/fields', ['farm_id' => $farmId, 'name' => 'A'])->json('data.id');
        $fieldB = $this->postJson('/api/v1/fields', ['farm_id' => $farmId, 'name' => 'B'])->json('data.id');
        $zoneA = $this->postJson("/api/v1/fields/{$fieldA}/zones", ['name' => 'A1'])->json('data.id');
        $zoneB = $this->postJson("/api/v1/fields/{$fieldB}/zones", ['name' => 'B1'])->json('data.id');
        $cropA = $this->postJson('/api/v1/crops', ['farm_id' => $farmId, 'name' => 'Millet'])->json('data.id');
        $cropB = $this->postJson('/api/v1/crops', ['farm_id' => $farmId, 'name' => 'Bean'])->json('data.id');
        $varietyA = $this->postJson("/api/v1/crops/{$cropA}/varieties", ['name' => 'M1'])->json('data.id');
        $varietyB = $this->postJson("/api/v1/crops/{$cropB}/varieties", ['name' => 'B1'])->json('data.id');
        $seasonId = $this->postJson('/api/v1/crop-seasons', [
            'farm_id' => $farmId, 'field_id' => $fieldA, 'field_zone_id' => $zoneA,
            'crop_id' => $cropA, 'crop_variety_id' => $varietyA, 'starts_on' => '2026-01-01',
        ])->json('data.id');

        $this->patchJson("/api/v1/crop-seasons/{$seasonId}", ['field_id' => $fieldB])
            ->assertUnprocessable()->assertJsonValidationErrors('field_zone_id');
        $this->patchJson("/api/v1/crop-seasons/{$seasonId}", ['crop_id' => $cropB])
            ->assertUnprocessable()->assertJsonValidationErrors('crop_variety_id');
        $this->patchJson("/api/v1/crop-seasons/{$seasonId}", [
            'field_id' => $fieldB, 'field_zone_id' => $zoneB, 'crop_id' => $cropB,
            'crop_variety_id' => $varietyB, 'starts_on' => '2027-02-01',
        ])->assertOk();
        $this->assertDatabaseHas('crop_rotation_entries', [
            'crop_season_id' => $seasonId, 'field_id' => $fieldB, 'field_zone_id' => $zoneB,
            'crop_id' => $cropB, 'season_year' => 2027,
        ]);
    }

    public function test_reservation_times_use_resource_timezone_unless_the_input_has_an_offset(): void
    {
        $admin = $this->user('timezone@example.test');
        $community = Community::query()->create(['name' => 'Timezone Circle', 'slug' => 'timezone-circle', 'timezone' => 'Asia/Kolkata']);
        CommunityMembership::query()->create(['community_id' => $community->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        $resource = SharedResource::query()->create([
            'community_id' => $community->id, 'name' => 'Timezone Tractor', 'timezone' => 'Asia/Kolkata',
            'status' => 'available', 'requires_approval' => true,
        ]);
        Sanctum::actingAs($admin);

        $localId = $this->postJson('/api/v1/reservations', [
            'resource_id' => $resource->id, 'starts_at' => '2030-01-01T08:00', 'ends_at' => '2030-01-01T09:00',
        ])->assertCreated()->json('data.id');
        $offsetId = $this->postJson('/api/v1/reservations', [
            'resource_id' => $resource->id, 'starts_at' => '2030-01-02T08:00:00+02:00', 'ends_at' => '2030-01-02T09:00:00+02:00',
        ])->assertCreated()->json('data.id');

        $this->assertTrue(ResourceReservation::findOrFail($localId)->starts_at->equalTo(CarbonImmutable::parse('2030-01-01T02:30:00Z')));
        $this->assertTrue(ResourceReservation::findOrFail($offsetId)->starts_at->equalTo(CarbonImmutable::parse('2030-01-02T06:00:00Z')));
    }

    public function test_discovery_is_available_to_nonmembers_without_private_location_or_owner_data(): void
    {
        $community = Community::query()->create([
            'name' => 'Discoverable Circle', 'slug' => 'discoverable-circle', 'description' => 'Public summary',
            'state_code' => 'KA', 'district' => 'Mysuru', 'locality' => 'Nanjangud', 'address' => 'Private road',
            'latitude' => 12.3, 'longitude' => 76.6, 'created_by_user_id' => $this->user('creator@example.test')->id,
        ]);
        $discoverer = $this->user('discoverer@example.test');
        CommunityJoinRequest::query()->create([
            'community_id' => $community->id, 'user_id' => $discoverer->id, 'status' => 'pending',
        ]);
        $joined = Community::query()->create(['name' => 'Joined Circle', 'slug' => 'joined-circle']);
        CommunityMembership::query()->create([
            'community_id' => $joined->id, 'user_id' => $discoverer->id, 'role' => 'member', 'status' => 'active',
        ]);
        Sanctum::actingAs($discoverer);

        $response = $this->getJson('/api/v1/communities/discover?search=Discoverable')->assertOk()
            ->assertJsonPath('data.0.name', 'Discoverable Circle')
            ->assertJsonPath('data.0.join_request_status', 'pending');
        $payload = $response->json('data.0');
        $this->assertArrayNotHasKey('address', $payload);
        $this->assertArrayNotHasKey('latitude', $payload);
        $this->assertArrayNotHasKey('longitude', $payload);
        $this->assertArrayNotHasKey('created_by_user_id', $payload);
        $this->getJson('/api/v1/communities/discover')->assertOk()
            ->assertJsonMissing(['name' => 'Joined Circle']);
    }

    public function test_explicit_community_link_permissions_add_a_farm_context_and_protect_planning_history(): void
    {
        $farmOwner = $this->user('context-owner@example.test');
        $communityMember = $this->user('context-member@example.test');
        $outsider = $this->user('context-outsider@example.test');
        $farm = Farm::query()->create(['name' => 'Shared Context Farm', 'slug' => 'shared-context-farm']);
        FarmMembership::query()->create([
            'farm_id' => $farm->id, 'user_id' => $farmOwner->id, 'role' => 'owner', 'status' => 'active',
        ]);
        $community = Community::query()->create(['name' => 'Context Circle', 'slug' => 'context-circle']);
        CommunityMembership::query()->create([
            'community_id' => $community->id, 'user_id' => $communityMember->id, 'role' => 'member', 'status' => 'active',
        ]);
        FarmCommunityLink::query()->create([
            'farm_id' => $farm->id, 'community_id' => $community->id, 'status' => 'active',
            'farm_access_permissions' => ['view_farm'],
        ]);
        $fieldId = $farm->fields()->create(['name' => 'History Field'])->id;
        DB::table('planning_history')->insert([
            'farm_id' => $farm->id, 'field_id' => $fieldId, 'event' => 'field_updated',
            'after' => json_encode(['name' => 'History Field']), 'created_at' => now(),
        ]);

        Sanctum::actingAs($communityMember);
        $this->getJson('/api/v1/contexts')->assertOk()
            ->assertJsonFragment([
                'id' => $farm->id, 'type' => 'farm', 'role' => 'community_link',
                'permissions' => ['view_farm'],
            ]);
        $this->getJson("/api/v1/planning-history?farm_id={$farm->id}")->assertOk()
            ->assertJsonPath('data.0.event', 'field_updated')
            ->assertJsonPath('data.0.field_name', 'History Field')
            ->assertJsonPath('data.0.after.name', 'History Field');

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/planning-history?farm_id={$farm->id}")->assertForbidden();
    }

    public function test_permission_denials_ledgers_marker_scope_and_mixed_units_are_enforced(): void
    {
        $owner = $this->user('hardening-owner@example.test');
        $manager = $this->user('hardening-manager@example.test');
        Sanctum::actingAs($owner);
        $farmId = $this->postJson('/api/v1/farms', ['name' => 'Hardened Farm'])->json('data.id');
        $membershipId = $this->postJson("/api/v1/farms/{$farmId}/members", [
            'user_id' => $manager->id, 'role' => 'manager',
            'permissions' => [['permission' => 'manage_tasks', 'allowed' => false]],
        ])->assertCreated()->json('data.id');
        Sanctum::actingAs($manager);
        $permissions = collect($this->getJson('/api/v1/contexts')->json('data'))->first()['permissions'];
        $this->assertNotContains('manage_tasks', $permissions);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/farms/{$farmId}/members/{$membershipId}", ['permissions' => []])->assertOk();
        $this->assertDatabaseMissing('farm_member_permissions', ['farm_membership_id' => $membershipId]);
        $stockId = $this->postJson('/api/v1/inventories', [
            'farm_id' => $farmId, 'name' => 'Seed', 'quantity' => 10, 'unit' => 'kg',
        ])->json('data.id');
        $this->patchJson("/api/v1/inventories/{$stockId}", ['quantity' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->patchJson("/api/v1/inventories/{$stockId}", ['quantity' => 10, 'category' => 'Seed'])
            ->assertOk()->assertJsonPath('data.category', 'Seed');

        $fieldA = $this->postJson('/api/v1/fields', ['farm_id' => $farmId, 'name' => 'Marker A'])->json('data.id');
        $fieldB = $this->postJson('/api/v1/fields', ['farm_id' => $farmId, 'name' => 'Marker B'])->json('data.id');
        $otherZone = $this->postJson("/api/v1/fields/{$fieldB}/zones", ['name' => 'Other'])->json('data.id');
        $this->putJson("/api/v1/fields/{$fieldA}/workspace", [
            'client_revision' => 0, 'zones' => [],
            'markers' => [['type' => 'note', 'field_zone_id' => $otherZone, 'position' => ['x' => 1, 'y' => 1]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('markers');

        $crop = Crop::query()->create(['farm_id' => $farmId, 'name' => 'Mixed Crop', 'created_by_user_id' => $owner->id]);
        $season = CropSeason::query()->create([
            'farm_id' => $farmId, 'field_id' => $fieldA, 'crop_id' => $crop->id, 'starts_on' => '2026-01-01',
        ]);
        CropHarvest::query()->create(['crop_season_id' => $season->id, 'quantity' => 100, 'unit' => 'kg', 'harvested_on' => '2026-05-01']);
        CropHarvest::query()->create(['crop_season_id' => $season->id, 'quantity' => 2, 'unit' => 'tonne', 'harvested_on' => '2026-05-02']);
        $this->getJson("/api/v1/farms/{$farmId}/analytics")->assertOk()
            ->assertJsonPath('data.harvest_quantities.kg', 100)
            ->assertJsonPath('data.harvest_quantities.tonne', 2)
            ->assertJsonMissingPath('data.harvest_quantity');
    }

    public function test_account_deactivation_cannot_orphan_a_community(): void
    {
        $systemAdmin = $this->user('system-admin@example.test', UserRole::Admin);
        $communityAdmin = $this->user('sole-community-admin@example.test');
        $community = Community::query()->create(['name' => 'Protected Circle', 'slug' => 'protected-circle']);
        CommunityMembership::query()->create([
            'community_id' => $community->id, 'user_id' => $communityAdmin->id, 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($systemAdmin);

        $this->deleteJson("/api/admin/users/{$communityAdmin->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->assertSame('active', $communityAdmin->fresh()->status);
    }

    private function onboardingDraft(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Invited', 'last_name' => 'Farmer', 'mode' => 'independent',
            'farm_action' => 'create', 'farm_name' => 'New Farm', 'farm_area_square_metres' => 1000,
            'state_code' => 'KA', 'district' => 'Mysuru', 'locality' => 'Nanjangud',
            'timezone' => 'Asia/Kolkata', 'community_action' => 'none', 'field_name' => 'First Field',
            'field_area_square_metres' => 900, 'soil_type' => 'loam', 'crop_name' => 'Millet',
            'crop_category' => 'cereal', 'season_name' => 'First Season', 'starts_on' => '2026-06-01',
            'expected_ends_on' => '2026-10-01', 'locale' => 'en-IN', 'area_unit' => 'hectare',
        ], $overrides);
    }

    private function user(string $email, UserRole $role = UserRole::Owner): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => $role, 'status' => 'active']);
        Profile::query()->create(['user_id' => $user->id, 'name' => 'Yava', 'surname' => 'Tester']);

        return $user;
    }
}
