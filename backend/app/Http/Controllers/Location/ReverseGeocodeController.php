<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Services\Integrations\ReverseGeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReverseGeocodeController extends Controller
{
    public function show(Request $request, ReverseGeocodingService $reverseGeocodingService): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $result = $reverseGeocodingService->resolveCity(
            (float) $validated['lat'],
            (float) $validated['lng'],
        );

        return response()->json([
            'data' => $result ?? [
                'city' => null,
                'display_name' => null,
                'provider' => 'nominatim',
            ],
        ]);
    }
}
