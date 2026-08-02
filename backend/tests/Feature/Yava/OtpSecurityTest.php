<?php

namespace Tests\Feature\Yava;

use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OtpSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'otp.provider' => 'development',
            'otp.development_code' => '246810',
            'otp.resend_cooldown_seconds' => 0,
            'otp.expires_seconds' => 300,
            'otp.max_attempts' => 2,
            'auth_api.emit_legacy_token' => true,
        ]);
    }

    public function test_active_user_can_log_in_with_the_development_otp_provider(): void
    {
        $user = $this->user('+919876543210');
        $challengeId = $this->requestCode('9876543210');

        $this->withHeader('Origin', 'http://localhost')->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId, 'code' => '246810',
        ])->assertOk()->assertJsonPath('data.verified', true)->assertJsonPath('data.user.id', $user->id)
            ->assertJsonMissingPath('data.token');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseHas('otp_audit_logs', ['otp_challenge_id' => $challengeId, 'event' => 'verified']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('inactiveStatuses')]
    public function test_every_inactive_status_is_rejected_without_authentication_artifacts(string $status): void
    {
        $phone = '+91987654'.str_pad((string) (100 + strlen($status)), 4, '0', STR_PAD_LEFT);
        $user = $this->user($phone, $status);
        $challengeId = $this->requestCode($phone);

        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId, 'code' => '246810',
        ])->assertUnprocessable()->assertJsonValidationErrors('code')
            ->assertJsonMissingPath('data.user')->assertJsonMissingPath('token');

        $this->assertGuest('web');
        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('otp_audit_logs', [
            'otp_challenge_id' => $challengeId, 'user_id' => $user->id, 'event' => 'rejected_inactive',
        ]);
        $challenge = OtpChallenge::findOrFail($challengeId);
        $this->assertNull($challenge->verified_at);
        $this->assertNotNull($challenge->invalidated_at);

        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId, 'code' => '246810',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('otp_audit_logs', 2); // sent + one rejection; reuse cannot add authentication artifacts.
    }

    public static function inactiveStatuses(): array
    {
        return [
            'deactivated' => ['deactivated'],
            'archived' => ['archived'],
            'disabled' => ['disabled'],
            'suspended' => ['suspended'],
            'unknown state fails closed' => ['pending_review'],
        ];
    }

    public function test_deactivation_timestamp_also_fails_closed_even_if_status_was_not_updated(): void
    {
        $user = $this->user('+919876543299');
        $user->update(['deactivated_at' => now()]);
        $challengeId = $this->requestCode($user->phone);

        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId, 'code' => '246810',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertGuest('web');
        $this->assertDatabaseHas('otp_audit_logs', ['otp_challenge_id' => $challengeId, 'event' => 'rejected_inactive']);
    }

    public function test_expired_invalid_and_exceeded_attempts_are_persisted_and_audited(): void
    {
        $expiredUser = $this->user('+919876543211');
        $expiredId = $this->requestCode($expiredUser->phone);
        $this->travel(301)->seconds();
        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $expiredId, 'code' => '246810'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->travelBack();
        $this->assertDatabaseHas('otp_audit_logs', ['otp_challenge_id' => $expiredId, 'event' => 'expired']);
        $this->assertNotNull(OtpChallenge::findOrFail($expiredId)->invalidated_at);

        $attemptUser = $this->user('+919876543212');
        $attemptId = $this->requestCode($attemptUser->phone);
        foreach (['000000', '111111'] as $invalidCode) {
            $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $attemptId, 'code' => $invalidCode])
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->assertSame(2, OtpChallenge::findOrFail($attemptId)->attempts);
        $this->assertSame(2, DB::table('otp_audit_logs')->where('otp_challenge_id', $attemptId)->where('event', 'failed')->count());

        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $attemptId, 'code' => '246810'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseHas('otp_audit_logs', ['otp_challenge_id' => $attemptId, 'event' => 'locked_out']);
        $this->assertNotNull(OtpChallenge::findOrFail($attemptId)->invalidated_at);
        $this->assertNull($attemptUser->fresh()->phone_verified_at);
        $this->assertGuest('web');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function user(string $phone, string $status = 'active'): User
    {
        return User::factory()->create([
            'phone' => $phone, 'phone_verified_at' => null, 'status' => $status,
            'deactivated_at' => $status === 'active' ? null : now(),
        ]);
    }

    private function requestCode(string $phone): string
    {
        return $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone, 'purpose' => 'login'])
            ->assertAccepted()->assertJsonPath('data.debug_code', '246810')->json('data.challenge_id');
    }
}
