<?php

namespace App\Services\Auth;

use App\Contracts\OtpProvider;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        return DB::transaction(function () use ($challengeId, $code, $ipAddress): OtpChallenge {
            $challenge = OtpChallenge::query()->lockForUpdate()->findOrFail($challengeId);
            if ($challenge->verified_at) {
                throw ValidationException::withMessages(['code' => ['This code has already been used.']]);
            }
            if ($challenge->expires_at->isPast()) {
                $this->audit($challenge, $challenge->user_id ? User::find($challenge->user_id) : null, 'expired', $ipAddress);
                throw ValidationException::withMessages(['code' => ['This code has expired.']]);
            }
            if ($challenge->attempts >= $challenge->max_attempts) {
                throw ValidationException::withMessages(['code' => ['Too many verification attempts. Request a new code.']]);
            }
            $challenge->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                $this->audit($challenge, $challenge->user_id ? User::find($challenge->user_id) : null, 'failed', $ipAddress);
                throw ValidationException::withMessages(['code' => ['The verification code is invalid.']]);
            }
            $challenge->update(['verified_at' => now()]);
            if ($challenge->user_id && in_array($challenge->purpose, ['verify_phone', 'login'], true)) {
                User::query()->whereKey($challenge->user_id)->update([
                    'phone' => $challenge->phone,
                    'phone_verified_at' => now(),
                ]);
            }
            $this->audit($challenge, $challenge->user_id ? User::find($challenge->user_id) : null, 'verified', $ipAddress);

            return $challenge->fresh();
        }, 3);
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
