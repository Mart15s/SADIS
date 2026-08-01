<?php

namespace App\Http\Controllers\Yava;

use App\Http\Controllers\Controller;
use App\Models\OnboardingProgress;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function show(Request $request)
    {
        $progress = OnboardingProgress::query()->firstOrCreate(['user_id' => $request->user()->id], [
            'current_step' => 'profile', 'completed_steps' => [], 'draft' => [],
        ]);

        return response()->json(['data' => $progress]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'current_step' => ['required', 'string', 'max:100'], 'completed_steps' => ['sometimes', 'array'],
            'completed_steps.*' => ['string', 'max:100'], 'draft' => ['sometimes', 'array'], 'completed' => ['sometimes', 'boolean'],
        ]);
        $progress = OnboardingProgress::query()->firstOrCreate(['user_id' => $request->user()->id]);
        $progress->update([
            'current_step' => $data['current_step'],
            'completed_steps' => $data['completed_steps'] ?? $progress->completed_steps ?? [],
            'draft' => $data['draft'] ?? $progress->draft ?? [],
            'completed_at' => ! empty($data['completed']) ? now() : $progress->completed_at,
        ]);

        return response()->json(['data' => $progress->fresh()]);
    }
}
