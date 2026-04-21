<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\GardenOwner;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_account_data(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'role' => UserRole::Owner,
        ]);
        $profile = Profile::query()->create([
            'user_id' => $user->id,
            'name' => 'Old',
            'surname' => 'Name',
        ]);

        GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/me', [
            'email' => 'new@example.com',
            'name' => 'New',
            'surname' => 'Owner',
        ])->assertOk()
            ->assertJsonPath('email', 'new@example.com')
            ->assertJsonPath('profile.name', 'New')
            ->assertJsonPath('profile.surname', 'Owner');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'new@example.com',
        ]);

        $this->assertDatabaseHas('profiles', [
            'id' => $profile->id,
            'user_id' => $user->id,
            'name' => 'New',
            'surname' => 'Owner',
        ]);
    }

    public function test_account_update_validates_unique_email(): void
    {
        $user = User::factory()->create([
            'email' => 'first@example.com',
            'role' => UserRole::Owner,
        ]);
        $profile = Profile::query()->create([
            'user_id' => $user->id,
            'name' => 'First',
            'surname' => 'Owner',
        ]);
        GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        User::factory()->create([
            'email' => 'taken@example.com',
            'role' => UserRole::Owner,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/me', [
            'email' => 'taken@example.com',
            'name' => 'First',
            'surname' => 'Owner',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
