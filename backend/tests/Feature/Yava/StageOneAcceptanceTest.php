<?php

namespace Tests\Feature\Yava;

use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StageOneAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_one_domain_authorization_privacy_and_reliable_operations(): void
    {
        $owner = $this->user('owner@yava.test');
        $communityAdmin = $this->user('community@yava.test');
        $outsider = $this->user('outsider@yava.test');

        Sanctum::actingAs($owner);
        $communityId = $this->postJson('/api/v1/communities', ['name' => 'Harvest Circle'])
            ->assertCreated()->json('data.id');
        $invitation = $this->postJson("/api/v1/communities/{$communityId}/invitations", [
            'email' => $communityAdmin->email, 'role' => 'admin',
        ])->assertCreated();

        Sanctum::actingAs($communityAdmin);
        $this->postJson('/api/v1/invitations/'.$invitation->json('invitation_code').'/accept')->assertOk();

        Sanctum::actingAs($owner);
        $farmId = $this->postJson('/api/v1/farms', ['name' => 'Sunrise Farm', 'area_square_metres' => 10000])
            ->assertCreated()->json('data.id');
        $fieldId = $this->postJson('/api/v1/fields', ['farm_id' => $farmId, 'name' => 'North Field', 'area_square_metres' => 6000])
            ->assertCreated()->json('data.id');
        $zoneId = $this->postJson("/api/v1/fields/{$fieldId}/zones", ['name' => 'Block A', 'area_square_metres' => 3000])
            ->assertCreated()->json('data.id');
        $cropId = $this->postJson('/api/v1/crops', ['farm_id' => $farmId, 'name' => 'Finger Millet'])
            ->assertCreated()->json('data.id');
        $seasonId = $this->postJson('/api/v1/crop-seasons', [
            'farm_id' => $farmId, 'field_id' => $fieldId, 'field_zone_id' => $zoneId,
            'crop_id' => $cropId, 'starts_on' => '2026-06-01', 'status' => 'active',
        ])->assertCreated()->json('data.id');
        $taskId = $this->postJson('/api/v1/tasks', [
            'farm_id' => $farmId, 'field_id' => $fieldId, 'crop_season_id' => $seasonId,
            'title' => 'Inspect irrigation', 'task_type' => 'field_inspection',
            'materials' => 'Pressure gauge and replacement seals',
            'weather_warning' => 'Avoid the inspection during lightning.',
        ])->assertCreated()->assertJsonPath('data.task_type', 'field_inspection')->json('data.id');
        $this->postJson("/api/v1/tasks/{$taskId}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $inventoryId = $this->postJson('/api/v1/inventories', [
            'farm_id' => $farmId, 'name' => 'Neem oil', 'quantity' => 20, 'unit' => 'litre',
        ])->assertCreated()->json('data.id');
        $otherFarmId = $this->postJson('/api/v1/farms', ['name' => 'Other Farm'])->assertCreated()->json('data.id');
        $otherFieldId = $this->postJson('/api/v1/fields', [
            'farm_id' => $otherFarmId, 'name' => 'Other Field', 'area_square_metres' => 1000,
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory-movements', [
            'inventory_id' => $inventoryId, 'type' => 'issue', 'quantity' => 1, 'field_id' => $otherFieldId,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/inventory-movements', [
            'inventory_id' => $inventoryId, 'type' => 'consumption', 'quantity' => 3,
            'field_id' => $fieldId, 'crop_season_id' => $seasonId,
        ])->assertCreated()->assertJsonPath('data.balance_after', '17.000');
        $this->assertDatabaseHas('inventory_movements', [
            'stock_item_id' => $inventoryId, 'field_id' => $fieldId, 'crop_season_id' => $seasonId,
        ]);

        $linkId = $this->postJson("/api/v1/farms/{$farmId}/communities/{$communityId}", [
            'analytics_scopes' => ['crop_summary'], 'farm_access_permissions' => [],
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->getJson("/api/v1/farm-community-links?farm_id={$farmId}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $linkId)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.farm.name', 'Sunrise Farm')
            ->assertJsonPath('data.0.community.name', 'Harvest Circle');

        Sanctum::actingAs($communityAdmin);
        $this->getJson("/api/v1/farm-community-links?community_id={$communityId}")
            ->assertOk()->assertJsonPath('data.0.id', $linkId);
        $this->postJson("/api/v1/farm-community-links/{$linkId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'active');
        $resourceId = $this->postJson('/api/v1/resources', [
            'community_id' => $communityId, 'name' => 'Two-wheel tractor', 'requires_approval' => true,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/resources?farm_id={$farmId}")
            ->assertOk()->assertJsonPath('data.0.id', $resourceId)
            ->assertJsonPath('data.0.community_id', $communityId);
        $this->postJson('/api/v1/tasks', [
            'farm_id' => $farmId, 'field_id' => $fieldId, 'title' => 'Prepare seed bed',
            'task_type' => 'cultivation', 'shared_resource_id' => $resourceId,
        ])->assertCreated()->assertJsonPath('data.shared_resource_id', $resourceId);
        $reservationId = $this->postJson('/api/v1/reservations', [
            'resource_id' => $resourceId, 'farm_id' => $farmId, 'field_id' => $fieldId,
            'starts_at' => '2026-09-01T04:00:00Z', 'ends_at' => '2026-09-01T08:00:00Z',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.field_id', $fieldId)->json('data.id');
        $this->postJson('/api/v1/reservations', [
            'resource_id' => $resourceId, 'farm_id' => $farmId, 'field_id' => $otherFieldId,
            'starts_at' => '2026-09-02T04:00:00Z', 'ends_at' => '2026-09-02T08:00:00Z',
        ])->assertUnprocessable();

        Sanctum::actingAs($communityAdmin);
        $this->getJson("/api/v1/reservations?community_id={$communityId}")
            ->assertOk()->assertJsonPath('data.0.id', $reservationId)
            ->assertJsonPath('data.0.status', 'pending');
        $this->postJson("/api/v1/reservations/{$reservationId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        Sanctum::actingAs($owner);
        $conflictId = $this->postJson('/api/v1/reservations', [
            'resource_id' => $resourceId, 'starts_at' => '2026-09-01T07:00:00Z', 'ends_at' => '2026-09-01T09:00:00Z',
        ])->assertCreated()->json('data.id');
        $backToBackId = $this->postJson('/api/v1/reservations', [
            'resource_id' => $resourceId, 'starts_at' => '2026-09-01T08:00:00Z', 'ends_at' => '2026-09-01T10:00:00Z',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($communityAdmin);
        $this->postJson("/api/v1/reservations/{$conflictId}/approve")->assertUnprocessable();
        $this->postJson("/api/v1/reservations/{$backToBackId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/v1/communities/{$communityId}/analytics")
            ->assertOk()->assertJsonPath('data.farms.0.name', 'Sunrise Farm')
            ->assertJsonMissingPath('data.farms.0.inventory_items')
            ->assertJsonMissingPath('data.farms.0.private_tasks');

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/fields/{$fieldId}")->assertForbidden();
        $this->getJson("/api/v1/resources?farm_id={$farmId}")->assertForbidden();
        $this->getJson("/api/v1/farm-community-links?farm_id={$farmId}")->assertForbidden();
        $this->getJson('/api/v1/farm-community-links')->assertUnprocessable();

        Sanctum::actingAs($communityAdmin);
        $this->deleteJson("/api/v1/farms/{$farmId}/community-links/{$linkId}")->assertNoContent();
        $this->assertDatabaseHas('farms', ['id' => $farmId, 'deleted_at' => null]);
        $this->assertDatabaseHas('farm_community_links', ['id' => $linkId, 'status' => 'revoked']);
    }

    public function test_otp_development_provider_verifies_without_a_universal_production_code(): void
    {
        config(['otp.provider' => 'development', 'otp.development_code' => '246810', 'otp.resend_cooldown_seconds' => 1]);
        $user = $this->user('phone@yava.test');
        $user->update(['phone' => '+919876543210']);

        $request = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876543210'])
            ->assertAccepted()->assertJsonPath('data.debug_code', '246810');
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $request->json('data.challenge_id'), 'code' => '246810',
        ])->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotNull($user->fresh()->phone_verified_at);
    }

    public function test_sole_farm_owner_and_sole_community_admin_cannot_be_removed_or_demoted(): void
    {
        $owner = $this->user('sole@yava.test');
        Sanctum::actingAs($owner);
        $communityId = $this->postJson('/api/v1/communities', ['name' => 'Sole Admin Community'])->json('data.id');
        $farmId = $this->postJson('/api/v1/farms', ['name' => 'Sole Owner Farm'])->json('data.id');

        $communityMembership = $this->getJson("/api/v1/communities/{$communityId}/members")->json('data.0.id');
        $farmMembership = $this->getJson("/api/v1/farms/{$farmId}/members")->json('data.0.id');
        $this->patchJson("/api/v1/communities/{$communityId}/members/{$communityMembership}", ['role' => 'member'])->assertUnprocessable();
        $this->patchJson("/api/v1/farms/{$farmId}/members/{$farmMembership}", ['role' => 'admin'])->assertUnprocessable();
    }

    public function test_community_task_summary_is_aggregate_only_and_requires_the_explicit_scope(): void
    {
        $owner = $this->user('task-owner@yava.test');
        $communityAdmin = $this->user('task-community@yava.test');
        Sanctum::actingAs($communityAdmin);
        $communityId = $this->postJson('/api/v1/communities', ['name' => 'Task Summary Circle'])->assertCreated()->json('data.id');

        Sanctum::actingAs($owner);
        $farmId = $this->postJson('/api/v1/farms', ['name' => 'Task Summary Farm'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/tasks', [
            'farm_id' => $farmId, 'title' => 'Private pending task', 'description' => 'Do not expose this note.',
        ])->assertCreated();
        $completedTask = $this->postJson('/api/v1/tasks', [
            'farm_id' => $farmId, 'title' => 'Private completed task', 'assigned_to_user_id' => $owner->id,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/tasks/{$completedTask}/complete")->assertOk();
        $linkId = $this->postJson("/api/v1/farms/{$farmId}/communities/{$communityId}", [
            'analytics_scopes' => ['task_summary'], 'farm_access_permissions' => [],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($communityAdmin);
        $this->postJson("/api/v1/farm-community-links/{$linkId}/approve")->assertOk();
        $response = $this->getJson("/api/v1/communities/{$communityId}/analytics")
            ->assertOk()
            ->assertJsonPath('data.farms.0.task_summary.total', 2)
            ->assertJsonPath('data.farms.0.task_summary.open', 1)
            ->assertJsonPath('data.farms.0.task_summary.completed', 1)
            ->assertJsonPath('data.farms.0.task_summary.cancelled', 0)
            ->assertJsonPath('data.farms.0.task_summary.by_status.completed', 1)
            ->assertJsonPath('data.farms.0.task_summary.by_status.pending', 1);
        $payload = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private pending task', $payload);
        $this->assertStringNotContainsString('Do not expose this note.', $payload);
        $this->assertStringNotContainsString($owner->email, $payload);
        $this->assertStringNotContainsString((string) $owner->id.'@', $payload);
        $response->assertJsonMissingPath('data.farms.0.active_crop_seasons')
            ->assertJsonMissingPath('data.farms.0.harvest_quantity');
    }

    private function user(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::Owner, 'status' => 'active']);
        Profile::query()->create(['user_id' => $user->id, 'name' => 'Yava', 'surname' => 'Tester']);

        return $user;
    }
}
