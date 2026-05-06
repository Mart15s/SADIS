<?php

namespace App\Http\Controllers\Plot;

use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Harvest\StoreHarvestRecordRequest;
use App\Http\Resources\Harvest\HarvestRecordResource;
use App\Models\Plot;
use App\Services\Plot\AccessService;
use App\Services\Plot\HarvestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HarvestController extends Controller
{
    use AuthorizesPlotAccess;

    public function index(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        HarvestService $harvestService
    ): JsonResponse {
        $owner = $this->ensureUserCanViewPlot($request, $plot, $accessService);
        $records = $harvestService->listForPlot($plot, $owner, $request->query('plant_id'));

        return response()->json([
            'data' => HarvestRecordResource::collection($records)->resolve(),
        ]);
    }

    public function store(
        StoreHarvestRecordRequest $request,
        Plot $plot,
        AccessService $accessService,
        HarvestService $harvestService
    ): JsonResponse {
        $owner = $this->ensureUserCanEditPlot($request, $plot, $accessService);
        $record = $harvestService->registerForPlot($plot, $owner, $request->validated());

        return response()->json([
            'message' => 'Derliaus irasas sekmingai issaugotas.',
            'data' => HarvestRecordResource::make($record)->resolve(),
        ], 201);
    }
}
