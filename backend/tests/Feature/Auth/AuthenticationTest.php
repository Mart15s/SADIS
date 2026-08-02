<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Mail\PasswordResetLinkMail;
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

    public function test_registration_preserves_email_password_flow_without_emitting_legacy_token(): void
    {
        config(['auth_api.emit_legacy_token' => false]);
        $this->postJson('/api/register', [
            'email' => 'farmer@example.com', 'password' => 'password123',
            'password_confirmation' => 'password123', 'name' => 'Asha', 'surname' => 'Patel',
        ])->assertCreated()->assertJsonPath('user.email', 'farmer@example.com')->assertJsonMissingPath('token');

        $this->assertDatabaseHas('users', ['email' => 'farmer@example.com', 'role' => UserRole::Owner->value]);
        $this->assertDatabaseCount('garden_owners', 1);
    }

    public function test_login_me_and_logout_keep_the_shell_auth_contract(): void
    {
        $user = $this->user('login@example.com', 'password123');
        $this->withHeader('Origin', 'http://localhost');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('user.email', $user->email)->assertJsonMissingPath('token');

        $this->getJson('/api/me')->assertOk()->assertJsonPath('email', $user->email);
        $this->postJson('/api/logout')->assertOk()->assertJsonPath('message', 'Signed out successfully.');
        $this->assertGuest('web');
    }

    public function test_logout_revokes_a_real_personal_access_token(): void
    {
        $user = $this->user('token-logout@example.com');
        $token = $user->createToken('logout-test')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')
            ->assertOk()->assertJsonPath('message', 'Signed out successfully.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_guest_and_deactivated_users_cannot_restore_authenticated_state(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->get('/api/me')->assertUnauthorized()->assertHeader('content-type', 'application/json');
        $user = $this->user('inactive@example.com');
        $user->update(['status' => 'deactivated', 'deactivated_at' => now()]);
        Sanctum::actingAs($user);
        $this->getJson('/api/me')->assertForbidden()->assertJsonPath('message', 'This account is inactive.');
    }

    public function test_password_login_rejects_every_non_active_account_state(): void
    {
        foreach (['deactivated', 'archived', 'disabled', 'suspended', 'pending_review'] as $index => $status) {
            $user = $this->user("inactive-{$index}@example.com");
            $user->update(['status' => $status, 'deactivated_at' => now()]);
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
                ->assertUnprocessable()->assertJsonMissingPath('token')->assertJsonMissingPath('user');
            $this->assertDatabaseCount('personal_access_tokens', 0);
        }

        $timestampOnly = $this->user('timestamp-only@example.com');
        $timestampOnly->update(['deactivated_at' => now()]);
        $this->postJson('/api/login', ['email' => $timestampOnly->email, 'password' => 'password123'])
            ->assertUnprocessable()->assertJsonMissingPath('token')->assertJsonMissingPath('user');
        $this->assertGuest('web');
    }

    public function test_password_reset_is_private_single_use_and_keeps_login_working(): void
    {
        Mail::fake();
        $user = $this->user('reset@example.com', 'password123');
        config(['app.frontend_url' => 'http://frontend.test']);
        $message = 'If the email address is registered, a password reset link has been sent.';
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('message', $message);
        $this->postJson('/api/forgot-password', ['email' => 'unknown@example.com'])->assertOk()->assertJsonPath('message', $message);
        $url = null;
        Mail::assertSent(PasswordResetLinkMail::class, function ($mail) use (&$url): bool {
            $url = $mail->resetUrl;

            return true;
        });
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $payload = [
            'email' => $user->email, 'reset_code' => $query['token'],
            'password' => 'newpassword123', 'password_confirmation' => 'newpassword123',
        ];
        $this->postJson('/api/reset-password', $payload)->assertOk()->assertJsonPath('message', 'Your password has been updated successfully.');
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'newpassword123'])->assertOk();
        Mail::assertSent(PasswordResetLinkMail::class, 1);
    }

    private function user(string $email, string $password = 'password123'): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => $password, 'status' => 'active']);
        $profile = Profile::query()->create(['user_id' => $user->id, 'name' => 'Yava', 'surname' => 'Farmer']);
        GardenOwner::query()->create(['id' => $user->id, 'user_id' => $user->id, 'id_user' => $user->id, 'fk_profile_id' => $profile->id]);

        return $user;
    }
}
