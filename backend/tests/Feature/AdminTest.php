<?php

namespace Tests\Feature;

use App\Enums\InventoryItemType;
use App\Enums\UserRole;
use App\Models\GardenOwner;
use App\Models\HasInventory;
use App\Models\HasPlot;
use App\Models\InventoryItem;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users(): void
    {
        [$admin] = $this->createUserWithOwner('admin@example.com', UserRole::Admin, 'Admin', 'User');
        [$managedUser] = $this->createUserWithOwner('user@example.com', UserRole::Owner, 'Jonas', 'Jonaitis');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $managedUser->id)
            ->assertJsonPath('data.1.email', 'user@example.com')
            ->assertJsonPath('data.1.role', 'owner')
            ->assertJsonPath('data.1.name', 'Jonas')
            ->assertJsonPath('data.1.surname', 'Jonaitis');
    }

    public function test_non_admin_cannot_access_admin_endpoints(): void
    {
        [$user] = $this->createUserWithOwner('user@example.com');

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_view_user_details(): void
    {
        [$admin] = $this->createUserWithOwner('admin@example.com', UserRole::Admin);
        [$user, $owner, $profile] = $this->createUserWithOwner('user@example.com', UserRole::Owner, 'Petras', 'Petraitis');

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'user@example.com')
            ->assertJsonPath('data.profile.id', $profile->id)
            ->assertJsonPath('data.profile.name', 'Petras')
            ->assertJsonPath('data.profile.surname', 'Petraitis')
            ->assertJsonPath('data.garden_owner.id_user', $owner->id_user)
            ->assertJsonPath('data.garden_owner.fk_profile_id', $owner->fk_profile_id);
    }

    public function test_admin_can_search_and_filter_users(): void
    {
        [$admin] = $this->createUserWithOwner('admin@example.com', UserRole::Admin, 'Admin', 'User');
        $this->createUserWithOwner('owner@example.com', UserRole::Owner, 'Jonas', 'Jonaitis');
        $this->createUserWithOwner('second-admin@example.com', UserRole::Admin, 'Ieva', 'Adminiene');

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users?search=Jonas&role=owner')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'owner@example.com')
            ->assertJsonPath('data.0.role', 'owner');
    }

    public function test_admin_can_update_user_role(): void
    {
        [$admin] = $this->createUserWithOwner('admin@example.com', UserRole::Admin);
        [$user] = $this->createUserWithOwner('user@example.com');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/role", [
            'role' => 'admin',
        ])->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_invalid_role_fails_validation(): void
    {
        [$admin] = $this->createUserWithOwner('admin@example.com', UserRole::Admin);
        [$user] = $this->createUserWithOwner('user@example.com');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/role", [
            'role' => 'super-admin',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_admin_can_delete_user_and_cleanup_orphaned_data(): void
    {
        [$admin] = $this->createUserWithOwner('admin@example.com', UserRole::Admin);
        [$user, $owner, $profile] = $this->createUserWithOwner('user@example.com', UserRole::Owner, 'Vardenis', 'Pavardenis');
        [, $sharedOwner] = $this->createUserWithOwner('shared@example.com', UserRole::Owner, 'Kitas', 'Naudotojas');

        $exclusivePlot = Plot::query()->create([
            'name' => 'Naikinamas sklypas',
            'city' => 'Vilnius',
            'plot_size' => 25,
            'creation_date' => '2026-03-26',
            'description' => 'Priklauso tik salinamam naudotojui',
            'share' => false,
        ]);

        $sharedPlot = Plot::query()->create([
            'name' => 'Bendras sklypas',
            'city' => 'Kaunas',
            'plot_size' => 50,
            'creation_date' => '2026-03-26',
            'description' => 'Turi du savininkus',
            'share' => true,
        ]);

        HasPlot::query()->create([
            'fk_plot_id' => $exclusivePlot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        HasPlot::query()->create([
            'fk_plot_id' => $sharedPlot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        HasPlot::query()->create([
            'fk_plot_id' => $sharedPlot->id,
            'fk_owner_id' => $sharedOwner->id_user,
            'fk_profile_id' => $sharedOwner->fk_profile_id,
        ]);

        $exclusiveItem = InventoryItem::query()->create([
            'name' => 'Asmenines trasos',
            'quantity' => 10,
            'type' => InventoryItemType::Material,
        ]);

        $sharedItem = InventoryItem::query()->create([
            'name' => 'Bendras kastuvas',
            'quantity' => 1,
            'type' => InventoryItemType::Tool,
        ]);

        HasInventory::query()->create([
            'fk_inventory_item_id' => $exclusiveItem->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        HasInventory::query()->create([
            'fk_inventory_item_id' => $sharedItem->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        HasInventory::query()->create([
            'fk_inventory_item_id' => $sharedItem->id,
            'fk_owner_id' => $sharedOwner->id_user,
            'fk_profile_id' => $sharedOwner->fk_profile_id,
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('message', "Naudotojas s\u{117}kmingai pa\u{161}alintas");

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);

        $this->assertDatabaseMissing('profiles', [
            'id' => $profile->id,
        ]);

        $this->assertDatabaseMissing('garden_owners', [
            'id_user' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        $this->assertDatabaseMissing('plots', [
            'id' => $exclusivePlot->id,
        ]);

        $this->assertDatabaseHas('plots', [
            'id' => $sharedPlot->id,
        ]);

        $this->assertDatabaseMissing('inventory_items', [
            'id' => $exclusiveItem->id,
        ]);

        $this->assertDatabaseHas('inventory_items', [
            'id' => $sharedItem->id,
        ]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        [$admin] = $this->createUserWithOwner('admin@example.com', UserRole::Admin);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user']);
    }

    public function test_role_update_persists_in_database(): void
    {
        [$admin] = $this->createUserWithOwner('admin@example.com', UserRole::Admin);
        [$user] = $this->createUserWithOwner('user@example.com');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/role", [
            'role' => 'admin',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'admin',
        ]);
    }

    public function test_admin_cannot_be_downgraded_accidentally(): void
    {
        [$admin] = $this->createUserWithOwner('admin@example.com', UserRole::Admin);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$admin->id}/role", [
            'role' => 'owner',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role' => 'admin',
        ]);
    }

    private function createUserWithOwner(
        string $email,
        UserRole $role = UserRole::Owner,
        string $name = 'Vardenis',
        string $surname = 'Pavardenis'
    ): array {
        $user = User::factory()->create([
            'email' => $email,
            'role' => $role,
        ]);

        $profile = Profile::query()->create([
            'name' => $name,
            'surname' => $surname,
        ]);

        $owner = GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        return [$user, $owner, $profile];
    }
}
