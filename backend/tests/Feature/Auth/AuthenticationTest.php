<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Mail\PasswordResetLinkMail;
use App\Models\GardenOwner;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_user_can_request_and_use_password_reset_link(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => 'password123',
            'reset_code' => null,
        ]);

        config(['app.frontend_url' => 'http://frontend.test']);

        $forgotResponse = $this->postJson('/api/forgot-password', [
            'email' => 'reset@example.com',
        ]);

        $forgotResponse->assertOk()
            ->assertJsonPath('message', 'If the email address exists, a password reset link has been sent.');

        $resetUrl = null;
        Mail::assertSent(PasswordResetLinkMail::class, function (PasswordResetLinkMail $mail) use (&$resetUrl) {
            $resetUrl = $mail->resetUrl;

            return str_starts_with($mail->resetUrl, 'http://frontend.test/reset-password?')
                && str_contains($mail->resetUrl, 'token=')
                && str_contains($mail->resetUrl, 'email=reset%40example.com');
        });

        $query = [];
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        $this->assertNotEmpty($query['token'] ?? null);
        $this->assertSame('reset@example.com', $query['email'] ?? null);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'reset@example.com',
        ]);

        $resetResponse = $this->postJson('/api/reset-password', [
            'email' => 'reset@example.com',
            'reset_code' => $query['token'],
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $resetResponse->assertOk()
            ->assertJsonPath('message', 'Password updated successfully.');

        $user->refresh();
        $this->assertNull($user->reset_code);
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'reset@example.com',
        ]);

        $this->postJson('/api/reset-password', [
            'email' => 'reset@example.com',
            'reset_code' => $query['token'],
            'password' => 'anotherpassword123',
            'password_confirmation' => 'anotherpassword123',
        ])->assertUnprocessable();

        $this->postJson('/api/login', [
            'email' => 'reset@example.com',
            'password' => 'newpassword123',
        ])->assertOk();
    }

    public function test_password_reset_request_does_not_reveal_unknown_email(): void
    {
        Mail::fake();

        User::factory()->create([
            'email' => 'known@example.com',
        ]);

        $knownResponse = $this->postJson('/api/forgot-password', [
            'email' => 'known@example.com',
        ]);

        $unknownResponse = $this->postJson('/api/forgot-password', [
            'email' => 'unknown@example.com',
        ]);

        $knownResponse->assertOk()
            ->assertJsonPath('message', 'If the email address exists, a password reset link has been sent.');

        $unknownResponse->assertOk()
            ->assertJsonPath('message', 'If the email address exists, a password reset link has been sent.');

        Mail::assertSent(PasswordResetLinkMail::class, 1);
    }

    public function test_password_cannot_be_reset_with_invalid_or_expired_token(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'expired-reset@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/reset-password', [
            'email' => 'expired-reset@example.com',
            'reset_code' => 'not-a-valid-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided password reset token is invalid or expired.');

        $this->postJson('/api/forgot-password', [
            'email' => 'expired-reset@example.com',
        ])->assertOk();

        $resetUrl = null;
        Mail::assertSent(PasswordResetLinkMail::class, function (PasswordResetLinkMail $mail) use (&$resetUrl) {
            $resetUrl = $mail->resetUrl;

            return true;
        });

        $query = [];
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        DB::table('password_reset_tokens')
            ->where('email', 'expired-reset@example.com')
            ->update(['created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1)]);

        $this->postJson('/api/reset-password', [
            'email' => 'expired-reset@example.com',
            'reset_code' => $query['token'],
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided password reset token is invalid or expired.');

        $this->postJson('/api/login', [
            'email' => 'expired-reset@example.com',
            'password' => 'password123',
        ])->assertOk();

        $user->refresh();
        $this->assertNull($user->reset_code);
    }
}
