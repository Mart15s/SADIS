<?php

namespace App\Http\Controllers\Plot;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Plant\PlantResource;
use App\Models\Plot;
use App\Services\Plot\AccessService;
use App\Services\Plot\PlotSnapshotService;
use App\Services\Plot\PlotWorkspaceService;
use App\Support\NormalizedGeometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
{
    use AuthorizesPlotAccess;

    public function update(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        PlotWorkspaceService $plotWorkspaceService,
        PlotSnapshotService $plotSnapshotService,
    ): JsonResponse {
        $owner = $this->ensureUserCanEditPlot($request, $plot, $accessService);

        $validated = $request->validate([
            'plot' => ['required', 'array'],
            'plot.plot_size' => ['required', 'numeric', 'min:0.01'],
            'plot.geometry' => ['nullable', 'array', NormalizedGeometry::validationRule()],
            'zones' => ['present', 'array'],
            'zones.*.id' => ['nullable'],
            'zones.*.client_id' => ['nullable', 'string', 'max:80'],
            'zones.*.name' => ['required', 'string', 'max:255'],
            'zones.*.zone_size' => ['required', 'numeric', 'min:0.01'],
            'zones.*.soil_type' => ['required', Rule::enum(SoilType::class)],
            'zones.*.rotation_stage' => ['nullable', 'integer', 'min:0'],
            'zones.*.last_planting_date' => ['nullable', 'date'],
            'zones.*.geometry' => ['nullable', 'array', NormalizedGeometry::validationRule()],
            'plants' => ['present', 'array'],
            'plants.*.id' => ['nullable'],
            'plants.*.client_id' => ['nullable', 'string', 'max:80'],
            'plants.*.name' => ['required', 'string', 'max:255'],
            'plants.*.type' => ['nullable', Rule::enum(PlantType::class)],
            'plants.*.condition' => ['required', Rule::enum(ConditionType::class)],
            'plants.*.plant_date' => ['required', 'date'],
            'plants.*.disease' => ['sometimes', 'boolean'],
            'plants.*.disease_notes' => ['nullable', 'string', 'max:255'],
            'plants.*.fk_catalog_plant_id' => ['nullable', 'integer', 'exists:catalog_plants,id'],
            'plants.*.fk_plant_zone_id' => ['required'],
            'plants.*.perenual_species_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $plotWorkspaceService->commitDraft(
            $plot,
            $owner,
            $validated,
            $plotSnapshotService,
        );

        return response()->json([
            'plot' => $result['plot'],
            'zones' => $result['zones'],
            'plants' => PlantResource::collection($result['plants'])->resolve(),
            'history_entry' => $result['history_entry'],
            'changes' => $result['changes'],
        ]);
    }
}
