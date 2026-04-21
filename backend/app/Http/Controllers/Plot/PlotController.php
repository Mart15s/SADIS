<?php

namespace App\Http\Controllers\Plot;

use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Models\Plot;
use App\Services\AccessService;
use App\Services\PlotSnapshotService;
use App\Support\NormalizedGeometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlotController extends Controller
{
    use AuthorizesPlotAccess;

    public function index(Request $request, AccessService $accessService): JsonResponse
    {
        $owner = $this->resolveGardenOwner($request);
        $accessiblePlotIds = $accessService->accessiblePlotIds($owner);

        $plots = Plot::query()
            ->whereIn('id', $accessiblePlotIds === [] ? [0] : $accessiblePlotIds)
            ->withCount(['plantZones', 'plants'])
            ->get();

        return response()->json(
            $plots->map(function (Plot $plot) use ($owner, $accessService) {
                return array_merge($plot->toArray(), [
                    'access_role' => $accessService->getUserRoleForPlot($owner, $plot),
                ]);
            })->values()
        );
    }

    public function store(Request $request, PlotSnapshotService $plotSnapshotService): JsonResponse
    {
        $owner = $request->user()->gardenOwner;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'plot_size' => ['required', 'numeric', 'min:0.01'],
            'creation_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'share' => ['sometimes', 'boolean'],
            'geometry' => ['sometimes', 'nullable', 'array', NormalizedGeometry::validationRule()],
        ]);

        $plot = Plot::create(array_merge($validated, [
            'garden_owner_id' => $owner->id,
        ]));

        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plot_created', $owner);

        return response()->json($plot->fresh(), 201);
    }

    public function show(Request $request, Plot $plot, AccessService $accessService): JsonResponse
    {
        $this->ensureUserCanViewPlot($request, $plot, $accessService);

        return response()->json(
            $plot->load(['plantZones', 'plants'])
        );
    }

    public function update(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse
    {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'plot_size' => ['sometimes', 'numeric', 'min:0.01'],
            'creation_date' => ['sometimes', 'date'],
            'description' => ['nullable', 'string'],
            'share' => ['sometimes', 'boolean'],
            'geometry' => ['sometimes', 'nullable', 'array', NormalizedGeometry::validationRule()],
        ]);

        $plot->update($validated);
        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plot_updated', $request->user()->gardenOwner);

        return response()->json($plot->fresh());
    }

    public function destroy(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse
    {
        $this->ensureUserOwnsPlot($request, $plot, $accessService);
        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plot_deleted', $request->user()->gardenOwner);
        $plot->delete();

        return response()->json(status: 204);
    }
}
