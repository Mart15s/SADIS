<?php

namespace App\Services\Auth;

use App\Contracts\OtpProvider;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private readonly OtpProvider $provider) {}

    public function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            throw ValidationException::withMessages(['phone' => ['Enter a valid international phone number.']]);
        }

        return '+'.$digits;
    }

    public function send(string $phone, string $purpose, ?User $user, ?string $ipAddress): array
    {
        $phone = $this->normalizePhone($phone);
        $recent = OtpChallenge::query()->where('phone', $phone)->where('purpose', $purpose)
            ->whereNull('verified_at')->latest('created_at')->first();
        if ($recent?->resend_available_at?->isFuture()) {
            throw ValidationException::withMessages(['phone' => ['Please wait before requesting another code.']]);
        }

        $code = (string) (config('otp.development_code') ?: random_int(100000, 999999));
        $challenge = OtpChallenge::query()->create([
            'user_id' => $user?->id, 'phone' => $phone, 'purpose' => $purpose,
            'code_hash' => Hash::make($code), 'max_attempts' => config('otp.max_attempts', 5),
            'expires_at' => now()->addSeconds(config('otp.expires_seconds', 300)),
            'resend_available_at' => now()->addSeconds(config('otp.resend_cooldown_seconds', 60)),
        ]);
        $this->provider->send($phone, $code, $purpose);
        $this->audit($challenge, $user, 'sent', $ipAddress);

        return [
            'challenge' => $challenge,
            'debug_code' => app()->environment(['local', 'testing']) ? $code : null,
        ];
    }

    public function verify(string $challengeId, string $code, ?string $ipAddress): OtpChallenge
    {
        $result = DB::transaction(function () use ($challengeId, $code, $ipAddress): array {
            $challenge = OtpChallenge::query()->lockForUpdate()->findOrFail($challengeId);
            $user = $challenge->user_id ? User::find($challenge->user_id) : null;
            if ($challenge->verified_at || $challenge->invalidated_at) {
                return ['error' => 'This code is no longer active.'];
            }
            if ($challenge->expires_at->isPast()) {
                $challenge->update(['invalidated_at' => now()]);
                $this->audit($challenge, $user, 'expired', $ipAddress);

                return ['error' => 'This code has expired.'];
            }
            if ($challenge->attempts >= $challenge->max_attempts) {
                $challenge->update(['invalidated_at' => now()]);
                $this->audit($challenge, $user, 'locked_out', $ipAddress);

                return ['error' => 'Too many verification attempts. Request a new code.'];
            }
            $challenge->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                $this->audit($challenge, $user, 'failed', $ipAddress);

                return ['error' => 'The verification code is invalid.'];
            }
            if ($user && ! $user->isActive()) {
                $challenge->update(['invalidated_at' => now()]);
                $this->audit($challenge, $user, 'rejected_inactive', $ipAddress);

                return ['error' => 'This account cannot be authenticated.'];
            }
            $challenge->update(['verified_at' => now()]);
            if ($user && in_array($challenge->purpose, ['verify_phone', 'login'], true)) {
                User::query()->whereKey($challenge->user_id)->update([
                    'phone' => $challenge->phone,
                    'phone_verified_at' => now(),
                ]);
            }
            $this->audit($challenge, $user, 'verified', $ipAddress);

            return ['challenge' => $challenge->fresh()];
        }, 3);

        if (isset($result['error'])) {
            throw ValidationException::withMessages(['code' => [$result['error']]]);
        }

        return $result['challenge'];
    }

    private function audit(OtpChallenge $challenge, ?User $user, string $event, ?string $ipAddress): void
    {
        DB::table('otp_audit_logs')->insert([
            'otp_challenge_id' => $challenge->id, 'user_id' => $user?->id,
            'phone' => $challenge->phone, 'event' => $event,
            'ip_address' => $ipAddress, 'context' => json_encode(['purpose' => $challenge->purpose]),
            'created_at' => now(),
        ]);
    }
}
