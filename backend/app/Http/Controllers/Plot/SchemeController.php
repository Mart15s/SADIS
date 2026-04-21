<?php

namespace App\Http\Controllers\Plot;

use App\Enums\SoilType;
use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Services\AccessService;
use App\Services\PlotSnapshotService;
use App\Support\NormalizedGeometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchemeController extends Controller
{
    use AuthorizesPlotAccess;

    public function index(Request $request, Plot $plot, AccessService $accessService): JsonResponse
    {
        $this->ensureUserCanViewPlot($request, $plot, $accessService);

        return response()->json(
            $plot->plantZones()->get()
        );
    }

    public function store(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse
    {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'zone_size' => ['required', 'numeric', 'min:0.01'],
            'soil_type' => ['required', Rule::enum(SoilType::class)],
            'rotation_stage' => ['sometimes', 'integer', 'min:0'],
            'last_planting_date' => ['nullable', 'date'],
            'geometry' => ['sometimes', 'nullable', 'array', NormalizedGeometry::validationRule()],
        ]);

        $validated['plot_id'] = $plot->id;
        $validated['fk_plot_id'] = $plot->id;

        $plantZone = PlantZone::create($validated);
        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'zone_created', $request->user()->gardenOwner, [
            'plant_zone_id' => $plantZone->id,
        ]);

        return response()->json($plantZone, 201);
    }

    public function update(
        Request $request,
        Plot $plot,
        PlantZone $plantZone,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse
    {
        $this->authorizeZoneEdit($request, $plot, $plantZone, $accessService);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'zone_size' => ['sometimes', 'numeric', 'min:0.01'],
            'soil_type' => ['sometimes', Rule::enum(SoilType::class)],
            'rotation_stage' => ['sometimes', 'integer', 'min:0'],
            'last_planting_date' => ['nullable', 'date'],
            'geometry' => ['sometimes', 'nullable', 'array', NormalizedGeometry::validationRule()],
        ]);

        $plantZone->update($validated);
        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'zone_updated', $request->user()->gardenOwner, [
            'plant_zone_id' => $plantZone->id,
        ]);

        return response()->json($plantZone->fresh());
    }

    public function destroy(
        Request $request,
        Plot $plot,
        PlantZone $plantZone,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse
    {
        $this->authorizeZoneEdit($request, $plot, $plantZone, $accessService);
        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'zone_deleted', $request->user()->gardenOwner, [
            'plant_zone_id' => $plantZone->id,
        ]);
        $plantZone->delete();

        return response()->json(status: 204);
    }

    private function authorizeZoneEdit(
        Request $request,
        Plot $plot,
        PlantZone $plantZone,
        AccessService $accessService
    ): void
    {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);
        abort_unless(($plantZone->plot_id ?? $plantZone->fk_plot_id) === $plot->id, 404);
    }
}
