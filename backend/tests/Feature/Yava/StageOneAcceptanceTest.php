<?php

namespace Tests\Feature\Yava;

use App\Enums\UserRole;
use App\Models\Farm;
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
            'farm_id' => $farmId, 'field_id' => $fieldId, 'crop_season_id' => $seasonId, 'title' => 'Inspect irrigation',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/tasks/{$taskId}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $inventoryId = $this->postJson('/api/v1/inventories', [
            'farm_id' => $farmId, 'name' => 'Neem oil', 'quantity' => 20, 'unit' => 'litre',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory-movements', [
            'inventory_id' => $inventoryId, 'type' => 'consumption', 'quantity' => 3,
        ])->assertCreated()->assertJsonPath('data.balance_after', '17.000');

        $linkId = $this->postJson("/api/v1/farms/{$farmId}/communities/{$communityId}", [
            'analytics_scopes' => ['crop_summary'], 'farm_access_permissions' => [],
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        Sanctum::actingAs($communityAdmin);
        $this->postJson("/api/v1/farm-community-links/{$linkId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'active');
        $resourceId = $this->postJson('/api/v1/resources', [
            'community_id' => $communityId, 'name' => 'Two-wheel tractor', 'requires_approval' => true,
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($owner);
        $reservationId = $this->postJson('/api/v1/reservations', [
            'resource_id' => $resourceId, 'farm_id' => $farmId,
            'starts_at' => '2026-09-01T04:00:00Z', 'ends_at' => '2026-09-01T08:00:00Z',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        Sanctum::actingAs($communityAdmin);
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

    private function user(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => UserRole::Owner, 'status' => 'active']);
        Profile::query()->create(['user_id' => $user->id, 'name' => 'Yava', 'surname' => 'Tester']);
        return $user;
    }
}
