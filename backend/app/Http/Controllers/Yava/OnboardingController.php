<?php

namespace App\Http\Controllers\Yava;

use App\Http\Controllers\Controller;
use App\Models\OnboardingProgress;
use App\Services\Yava\OnboardingService;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function show(Request $request)
    {
        $progress = OnboardingProgress::query()->firstOrCreate(['user_id' => $request->user()->id], [
            'current_step' => 'profile', 'completed_steps' => [], 'draft' => [],
        ]);

        $payload = $progress->toArray();
        $payload['provisioned'] = data_get($progress->draft, 'provisioned');

        return response()->json(['data' => $payload]);
    }

    public function update(Request $request, OnboardingService $service)
    {
        $data = $request->validate([
            'current_step' => ['required', 'string', 'max:100'], 'completed_steps' => ['sometimes', 'array'],
            'completed_steps.*' => ['string', 'max:100'], 'draft' => ['sometimes', 'array'], 'completed' => ['sometimes', 'boolean'],
        ]);

        $result = $service->save(
            $request->user(),
            $data['current_step'],
            $data['completed_steps'] ?? null,
            $data['draft'] ?? null,
            (bool) ($data['completed'] ?? false),
        );

        return response()->json(['data' => $result]);
    }
}
