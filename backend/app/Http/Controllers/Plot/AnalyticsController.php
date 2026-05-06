<?php

namespace App\Http\Controllers\Plot;

use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Plot\GenerateAnalyticsRequest;
use App\Http\Resources\Plot\AnalyticsResource;
use App\Models\Plot;
use App\Services\Plot\AccessService;
use App\Services\Plot\AnalyticsService;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    use AuthorizesPlotAccess;

    public function show(
        GenerateAnalyticsRequest $request,
        Plot $plot,
        AnalyticsService $analyticsService,
        AccessService $accessService
    ): JsonResponse {
        $owner = $this->ensureUserCanViewPlot($request, $plot, $accessService);

        return response()->json(
            AnalyticsResource::make($analyticsService->analyzePlot($plot, $owner, $request->analysisTypes()))
        );
    }

    public function store(
        GenerateAnalyticsRequest $request,
        Plot $plot,
        AnalyticsService $analyticsService,
        AccessService $accessService
    ): JsonResponse {
        $owner = $this->ensureUserCanViewPlot($request, $plot, $accessService);

        return response()->json(
            AnalyticsResource::make($analyticsService->analyzePlot($plot, $owner, $request->analysisTypes()))
        );
    }
}
