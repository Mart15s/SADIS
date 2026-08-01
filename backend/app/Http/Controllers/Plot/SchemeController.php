<?php

namespace App\Http\Controllers\Plot;

use App\Enums\SoilType;
use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Services\Plot\AccessService;
use App\Services\Plot\PlotSnapshotService;
use App\Support\NormalizedGeometry;
use App\Support\ZoneColor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchemeController extends Controller
{
    use AuthorizesPlotAccess;

    public function index(Request $request, Plot $plot, AccessService $accessService): JsonResponse
    {
        $this->ensureUserCanViewPlot($request, $plot, $accessService);

        $validated = $request->validate([
            'include_archived' => ['sometimes', 'boolean'],
        ]);

        $zones = $plot->plantZones()
            ->when(! ($validated['include_archived'] ?? false), fn ($query) => $query->whereNull('archived_at'))
            ->with(['plants.catalogPlant', 'plants.harvestRecords', 'rotationHistory'])
            ->orderBy('id')
            ->get()
            ->map(fn (PlantZone $zone): array => $this->presentZone($zone));

        return response()->json($zones);
    }

    public function store(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'zone_size' => ['required', 'numeric', 'min:0.01'],
            'soil_type' => ['required', Rule::enum(SoilType::class)],
            'rotation_stage' => ['sometimes', 'integer', 'min:0'],
            'last_planting_date' => ['nullable', 'date'],
            'geometry' => ['sometimes', 'nullable', 'array', NormalizedGeometry::validationRule()],
            'color_hex' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $validated['plot_id'] = $plot->id;
        $validated['fk_plot_id'] = $plot->id;

        $plantZone = PlantZone::create($validated);
        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'zone_created', $request->user()->gardenOwner, [
            'plant_zone_id' => $plantZone->id,
        ]);

        return response()->json($this->presentZone($plantZone->fresh(['plants.catalogPlant', 'plants.harvestRecords', 'rotationHistory'])), 201);
    }

    public function update(
        Request $request,
        Plot $plot,
        PlantZone $plantZone,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $this->authorizeZoneEdit($request, $plot, $plantZone, $accessService);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'zone_size' => ['sometimes', 'numeric', 'min:0.01'],
            'soil_type' => ['sometimes', Rule::enum(SoilType::class)],
            'rotation_stage' => ['sometimes', 'integer', 'min:0'],
            'last_planting_date' => ['nullable', 'date'],
            'geometry' => ['sometimes', 'nullable', 'array', NormalizedGeometry::validationRule()],
            'color_hex' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $plantZone->update($validated);
        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'zone_updated', $request->user()->gardenOwner, [
            'plant_zone_id' => $plantZone->id,
        ]);

        return response()->json($this->presentZone($plantZone->fresh(['plants.catalogPlant', 'plants.harvestRecords', 'rotationHistory'])));
    }

    public function destroy(
        Request $request,
        Plot $plot,
        PlantZone $plantZone,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $this->authorizeZoneEdit($request, $plot, $plantZone, $accessService);

        $counts = $this->associationCounts($plantZone);

        if (array_sum($counts) > 0) {
            return response()->json([
                'message' => 'Zonos negalima negrįžtamai ištrinti, nes joje yra susietų įrašų.',
                'code' => 'zone_has_protected_history',
                'associations' => $counts,
                'available_actions' => ['archive', 'move_active_plantings'],
            ], 409);
        }

        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'zone_deleted', $request->user()->gardenOwner, [
            'plant_zone_id' => $plantZone->id,
        ]);
        $plantZone->delete();

        return response()->json(status: 204);
    }

    public function archive(
        Request $request,
        Plot $plot,
        PlantZone $plantZone,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $this->authorizeZoneEdit($request, $plot, $plantZone, $accessService);

        $plantZone->update(['archived_at' => $plantZone->archived_at ?? now()]);
        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'zone_archived', $request->user()->gardenOwner, [
            'plant_zone_id' => $plantZone->id,
            'associations' => $this->associationCounts($plantZone),
        ]);

        return response()->json($this->presentZone($plantZone->fresh(['plants.catalogPlant', 'plants.harvestRecords', 'rotationHistory'])));
    }

    private function authorizeZoneEdit(
        Request $request,
        Plot $plot,
        PlantZone $plantZone,
        AccessService $accessService
    ): void {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);
        abort_unless(($plantZone->plot_id ?? $plantZone->fk_plot_id) === $plot->id, 404);
    }

    private function presentZone(PlantZone $zone): array
    {
        $plants = $zone->plants;
        $activePlants = $plants->filter(fn ($plant): bool => ($plant->condition?->value ?? $plant->condition) !== 'dried' && $plant->harvest_date === null);
        $counts = $this->associationCounts($zone);

        return array_merge($zone->toArray(), $counts, [
            'active_planting_count' => $activePlants->count(),
            'historical_planting_count' => max(0, $plants->count() - $activePlants->count()),
            'principal_plants' => $activePlants
                ->pluck('name')
                ->filter()
                ->unique()
                ->take(3)
                ->values()
                ->all(),
            'suggested_color' => ZoneColor::suggestForPlot($zone->plot_id ?? $zone->fk_plot_id, $zone->id),
            'is_archived' => $zone->archived_at !== null,
        ]);
    }

    private function associationCounts(PlantZone $zone): array
    {
        $zone->loadMissing(['plants.harvestRecords', 'rotationHistory']);
        $activePlantings = $zone->plants->filter(
            fn ($plant): bool => ($plant->condition?->value ?? $plant->condition) !== 'dried' && $plant->harvest_date === null
        )->count();

        return [
            'active_planting_count' => $activePlantings,
            'historical_planting_count' => max(0, $zone->plants->count() - $activePlantings),
            'rotation_history_count' => $zone->rotationHistory->count(),
            'harvest_history_count' => $zone->plants->sum(fn ($plant): int => $plant->harvestRecords->count()),
        ];
    }
}
