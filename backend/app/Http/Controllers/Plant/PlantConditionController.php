<?php

namespace App\Http\Controllers\Plant;

use App\Enums\ConditionType;
use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Plant\PlantConditionHistoryResource;
use App\Models\Plant;
use App\Models\Plot;
use App\Services\AccessService;
use App\Services\PlantConditionHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlantConditionController extends Controller
{
    use AuthorizesPlotAccess;

    public function store(
        Request $request,
        Plot $plot,
        Plant $plant,
        AccessService $accessService,
        PlantConditionHistoryService $plantConditionHistoryService
    ): JsonResponse
    {
        $this->authorizePlantEdit($request, $plot, $plant, $accessService);

        $validated = $request->validate([
            'measured_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'photo_url' => ['nullable', 'url'],
            'condition' => ['required', Rule::enum(ConditionType::class)],
            'disease' => ['nullable', 'boolean'],
        ]);

        $condition = $validated['condition'];
        $hasDisease = array_key_exists('disease', $validated)
            ? (bool) $validated['disease']
            : ($condition === ConditionType::Diseased->value);

        $history = $plantConditionHistoryService->record(
            $plant,
            $condition,
            $validated['measured_at'],
            $validated['notes'] ?? null,
            $validated['photo_url'] ?? null,
            $hasDisease,
        );

        return response()->json(
            PlantConditionHistoryResource::make($history)->resolve(),
            201
        );
    }

    public function index(
        Request $request,
        Plot $plot,
        Plant $plant,
        AccessService $accessService,
        PlantConditionHistoryService $plantConditionHistoryService
    ): JsonResponse {
        $this->authorizePlantView($request, $plot, $plant, $accessService);

        return response()->json(
            PlantConditionHistoryResource::collection(
                $plantConditionHistoryService->listForPlant($plant)
            )->resolve()
        );
    }

    private function authorizePlantView(Request $request, Plot $plot, Plant $plant, AccessService $accessService): void
    {
        $this->ensureUserCanViewPlot($request, $plot, $accessService);
        abort_unless($plant->fk_plot_id === $plot->id, 404);
    }

    private function authorizePlantEdit(Request $request, Plot $plot, Plant $plant, AccessService $accessService): void
    {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);
        abort_unless($plant->fk_plot_id === $plot->id, 404);
    }
}
