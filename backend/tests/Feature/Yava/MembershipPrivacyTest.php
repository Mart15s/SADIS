<?php

namespace Tests\Feature\Yava;

use App\Models\FarmMemberPermission;
use App\Models\FarmMembership;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MembershipPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_farm_rosters_and_embedded_members_hide_contact_details_without_manage_members(): void
    {
        $owner = $this->user('owner@example.test', '+919876543210', 'Owner');
        $viewer = $this->user('viewer@example.test', '+919876543211', 'Viewer');
        Sanctum::actingAs($owner);
        $farmId = $this->postJson('/api/v1/farms', ['name' => 'Private Farm'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/farms/{$farmId}/members", [
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'permissions' => ['view_farm', 'manage_fields', 'manage_crops', 'manage_tasks', 'manage_inventory', 'view_analytics'],
        ])->assertCreated();

        Sanctum::actingAs($viewer);
        foreach (["/api/v1/farms/{$farmId}/members", "/api/v1/farms/{$farmId}"] as $endpoint) {
            $response = $this->getJson($endpoint)->assertOk();
            $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($owner->email, $json);
            $this->assertStringNotContainsString($viewer->email, $json);
            $this->assertStringNotContainsString((string) $owner->phone, $json);
            $this->assertStringNotContainsString((string) $viewer->phone, $json);
            $this->assertStringContainsString('Owner', $json);
            $this->assertStringContainsString('Viewer', $json);
        }

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/farms/{$farmId}/members")
            ->assertOk()->assertJsonPath('data.1.user.email', $viewer->email)
            ->assertJsonPath('data.1.user.phone', $viewer->phone)
            ->assertJsonFragment(['permission' => 'view_farm']);
        $this->getJson("/api/v1/farms/{$farmId}")
            ->assertOk()->assertJsonPath('data.memberships.1.user.email', $viewer->email);
    }

    public function test_community_rosters_are_public_safe_but_management_payloads_remain_admin_only(): void
    {
        $admin = $this->user('admin@example.test', '+919876543220', 'Admin');
        $member = $this->user('member@example.test', '+919876543221', 'Member');
        Sanctum::actingAs($admin);
        $communityId = $this->postJson('/api/v1/communities', ['name' => 'Privacy Circle'])->assertCreated()->json('data.id');
        $invitation = $this->postJson("/api/v1/communities/{$communityId}/invitations", [
            'email' => $member->email, 'role' => 'member',
        ])->assertCreated();

        Sanctum::actingAs($member);
        $this->postJson('/api/v1/invitations/'.$invitation->json('invitation_code').'/accept')->assertOk();
        foreach (["/api/v1/communities/{$communityId}/members", "/api/v1/communities/{$communityId}"] as $endpoint) {
            $response = $this->getJson($endpoint)->assertOk();
            $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($admin->email, $json);
            $this->assertStringNotContainsString($member->email, $json);
            $this->assertStringNotContainsString((string) $admin->phone, $json);
            $this->assertStringNotContainsString((string) $member->phone, $json);
            $this->assertStringContainsString('Admin', $json);
            $this->assertStringContainsString('Member', $json);
        }
        $this->getJson("/api/v1/communities/{$communityId}/invitations")->assertForbidden();
        $this->getJson("/api/v1/communities/{$communityId}/join-requests")->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/communities/{$communityId}/members")
            ->assertOk()->assertJsonPath('data.1.user.email', $member->email)
            ->assertJsonPath('data.1.user.phone', $member->phone);
        $this->getJson("/api/v1/communities/{$communityId}")
            ->assertOk()->assertJsonPath('data.memberships.1.user.email', $member->email);
    }

    public function test_contexts_expose_resolved_capabilities_and_honour_explicit_farm_overrides(): void
    {
        $owner = $this->user('context-owner@example.test', '+919876543230', 'Owner');
        $viewer = $this->user('context-viewer@example.test', '+919876543231', 'Viewer');
        Sanctum::actingAs($owner);
        $farmId = $this->postJson('/api/v1/farms', ['name' => 'Capability Farm'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/farms/{$farmId}/members", [
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'permissions' => ['manage_members'],
        ])->assertCreated();

        Sanctum::actingAs($viewer);
        $permissions = collect($this->getJson('/api/v1/contexts')->assertOk()->json('data'))
            ->first(fn (array $context) => $context['type'] === 'farm' && (int) $context['id'] === (int) $farmId)['permissions'];
        $this->assertEqualsCanonicalizing(['view_farm', 'manage_members'], $permissions);

        $membership = FarmMembership::query()
            ->where('farm_id', $farmId)->where('user_id', $viewer->id)->firstOrFail();
        FarmMemberPermission::query()->updateOrCreate(
            ['farm_membership_id' => $membership->id, 'permission' => 'manage_members'],
            ['allowed' => false],
        );

        $permissions = collect($this->getJson('/api/v1/contexts')->assertOk()->json('data'))
            ->first(fn (array $context) => $context['type'] === 'farm' && (int) $context['id'] === (int) $farmId)['permissions'];
        $this->assertSame(['view_farm'], $permissions);
    }

    private function user(string $email, string $phone, string $name): User
    {
        $user = User::factory()->create(['email' => $email, 'phone' => $phone]);
        Profile::query()->create(['user_id' => $user->id, 'name' => $name, 'surname' => 'Farmer']);

        return $user;
    }
}
