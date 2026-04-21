<?php

namespace App\Http\Controllers\Api\Plot;

use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Models\Plot;
use App\Services\AccessService;
use App\Services\PlotSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    use AuthorizesPlotAccess;

    public function index(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $this->ensureUserCanViewPlot($request, $plot, $accessService);

        $limit = max(1, min(100, (int) $request->integer('limit', 50)));

        return response()->json([
            'data' => $plotSnapshotService->listForPlot($plot, $limit)->all(),
        ]);
    }
}
