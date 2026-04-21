<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\GardenOwner;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receives_api_token(): void
    {
        $response = $this->postJson('/api/register', [
            'email' => 'gardener@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'name' => 'Jonas',
            'surname' => 'Jonaitis',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'email', 'role'],
                'profile' => ['id', 'name', 'surname'],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'gardener@example.com',
            'role' => UserRole::Owner->value,
        ]);

        $this->assertDatabaseHas('profiles', [
            'name' => 'Jonas',
            'surname' => 'Jonaitis',
        ]);

        $this->assertDatabaseCount('garden_owners', 1);
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $profile = Profile::create([
            'name' => 'Petras',
            'surname' => 'Petraitis',
        ]);

        GardenOwner::create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('user.email', 'login@example.com')
            ->assertJsonPath('profile.id', $profile->id);

        Sanctum::actingAs($user);

        $logoutResponse = $this->postJson('/api/logout');

        $logoutResponse->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');
    }

    public function test_authenticated_user_can_restore_session_via_me_endpoint(): void
    {
        $user = User::factory()->create([
            'email' => 'restore@example.com',
        ]);

        $profile = Profile::create([
            'name' => 'Ona',
            'surname' => 'Onyte',
        ]);

        GardenOwner::create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJson([
                'id' => $user->id,
                'email' => 'restore@example.com',
                'role' => UserRole::Owner->value,
                'profile' => [
                    'name' => 'Ona',
                    'surname' => 'Onyte',
                ],
            ]);
    }

    public function test_guest_receives_401_from_me_endpoint(): void
    {
        $this->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_user_can_request_and_use_password_reset_code(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => 'password123',
            'reset_code' => null,
        ]);

        $forgotResponse = $this->postJson('/api/forgot-password', [
            'email' => 'reset@example.com',
        ]);

        $forgotResponse->assertOk()
            ->assertJsonPath('message', 'Password reset code sent successfully.');

        $user->refresh();
        $this->assertNotNull($user->reset_code);
        $resetResponse = $this->postJson('/api/reset-password', [
            'email' => 'reset@example.com',
            'reset_code' => $user->reset_code,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $resetResponse->assertOk()
            ->assertJsonPath('message', 'Password updated successfully.');

        $user->refresh();
        $this->assertNull($user->reset_code);
    }
}
