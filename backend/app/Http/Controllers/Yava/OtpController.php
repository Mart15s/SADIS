<?php

namespace App\Http\Controllers\Yava;

use App\Http\Controllers\Controller;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OtpController extends Controller
{
    public function request(Request $request, OtpService $service)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32'], 'purpose' => ['sometimes', 'in:login,verify_phone']]);
        $phone = $service->normalizePhone($data['phone']);
        $purpose = $data['purpose'] ?? 'login';
        $user = $request->user() ?: User::query()->where('phone', $phone)->first();
        // Do not reveal whether an account exists. A login challenge without a
        // user remains non-authenticating but has the same outward response.
        $result = $service->send($phone, $purpose, $user, $request->ip());

        return response()->json(['data' => [
            'challenge_id' => $result['challenge']->id,
            'phone' => $phone,
            'expires_at' => $result['challenge']->expires_at,
            'resend_available_at' => $result['challenge']->resend_available_at,
            'debug_code' => $result['debug_code'],
        ]], 202);
    }

    public function verify(Request $request, OtpService $service)
    {
        $data = $request->validate([
            'challenge_id' => ['nullable', 'uuid', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'required_without:challenge_id'],
            'code' => ['required', 'digits:6'], 'purpose' => ['sometimes', 'in:login,verify_phone'],
        ]);
        $challengeId = $data['challenge_id'] ?? OtpChallenge::query()
            ->where('phone', $service->normalizePhone($data['phone']))
            ->where('purpose', $data['purpose'] ?? 'login')->whereNull('verified_at')->latest()->value('id');
        abort_unless($challengeId, 422, 'No active OTP challenge was found.');
        $challenge = $service->verify($challengeId, $data['code'], $request->ip());
        $user = $challenge->user_id ? User::with('profile')->find($challenge->user_id) : null;
        // OtpService rejects every non-active account before it can mark the
        // challenge verified or return control to an authentication boundary.
        if ($user && $user->isActive() && $challenge->purpose === 'login' && $request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return response()->json(['data' => ['verified' => true, 'user' => $user, 'profile' => $user?->profile]]);
    }
}
