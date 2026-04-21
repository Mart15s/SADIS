<?php

namespace App\Http\Controllers\Plot;

use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Plant\PlantResource;
use App\Models\Plant;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\RotationHistory;
use App\Models\RotationPlanDraft;
use App\Services\AccessService;
use App\Services\PlotSnapshotService;
use App\Services\RotationPlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RotationController extends Controller
{
    use AuthorizesPlotAccess;

    public function index(Request $request, Plot $plot, AccessService $accessService): JsonResponse
    {
        $this->ensureUserCanViewPlot($request, $plot, $accessService);

        return response()->json(
            $plot->rotationHistory()
                ->with(['plantZone', 'plant'])
                ->latest('from_date')
                ->get()
                ->map(fn (RotationHistory $history) => $this->serializeRotationHistory($history))
                ->all()
        );
    }

    public function recommendations(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        RotationPlannerService $rotationPlannerService
    ): JsonResponse {
        $this->ensureUserCanViewPlot($request, $plot, $accessService);

        $validated = $request->validate([
            'fk_plant_id' => ['sometimes', 'integer', 'exists:plants,id'],
            'plant_zone_id' => ['sometimes', 'integer', 'exists:plant_zones,id'],
            'fk_plant_zone_id' => ['sometimes', 'integer', 'exists:plant_zones,id'],
            'from_date' => ['sometimes', 'date'],
        ]);

        $zoneId = $validated['plant_zone_id'] ?? $validated['fk_plant_zone_id'] ?? null;
        $plantId = $validated['fk_plant_id'] ?? null;

        if (! $plantId || ! $zoneId) {
            return response()->json([
                'data' => $rotationPlannerService->evaluatePlot($plot, $validated['from_date'] ?? null),
            ]);
        }

        $plant = Plant::query()
            ->whereKey($plantId)
            ->where('fk_plot_id', $plot->id)
            ->firstOrFail();
        $plantZone = PlantZone::query()
            ->whereKey($zoneId)
            ->where('plot_id', $plot->id)
            ->firstOrFail();

        return response()->json([
            'data' => $rotationPlannerService->evaluatePlacement(
                $plot,
                $plant,
                $plantZone,
                $validated['from_date'] ?? null
            ),
        ]);
    }

    public function store(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService,
        RotationPlannerService $rotationPlannerService
    ): JsonResponse {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);

        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'plant_zone_id' => ['nullable', 'integer', 'exists:plant_zones,id'],
            'fk_plant_zone_id' => ['required', 'integer', 'exists:plant_zones,id'],
            'fk_plant_id' => ['required', 'integer', 'exists:plants,id'],
        ]);

        $validated['plant_zone_id'] = $validated['plant_zone_id'] ?? $validated['fk_plant_zone_id'];

        $plantZone = PlantZone::query()
            ->whereKey($validated['plant_zone_id'])
            ->where('plot_id', $plot->id)
            ->first();

        $plant = Plant::query()
            ->whereKey($validated['fk_plant_id'])
            ->where('fk_plot_id', $plot->id)
            ->first();

        abort_unless($plantZone && $plant, 422, 'The selected plant zone or plant does not belong to the plot.');

        $evaluation = $rotationPlannerService->evaluatePlacement(
            $plot,
            $plant,
            $plantZone,
            $validated['from_date']
        );

        $rotation = RotationHistory::query()->create([
            'from_date' => $validated['from_date'],
            'to_date' => $validated['to_date'] ?? null,
            'plant_zone_id' => $plantZone->id,
            'fk_plot_id' => $plot->id,
            'fk_plant_zone_id' => $plantZone->id,
            'fk_plot_via_zone' => $plot->id,
            'fk_plant_id' => $plant->id,
        ]);

        $plantZone->update([
            'rotation_stage' => $plantZone->rotation_stage + 1,
            'last_planting_date' => $validated['from_date'],
        ]);

        $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'rotation_recorded', $request->user()->gardenOwner, [
            'rotation_history_id' => $rotation->id,
            'rotation_evaluation' => $evaluation,
        ]);

        return response()->json([
            'rotation' => $rotation->toArray(),
            'evaluation' => $evaluation,
        ], 201);
    }

    public function plan(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        RotationPlannerService $rotationPlannerService
    ): JsonResponse
    {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);

        $validated = $request->validate([
            'planning_date' => ['nullable', 'date'],
        ]);

        RotationPlanDraft::query()
            ->where('plot_id', $plot->id)
            ->delete();

        $draft = $rotationPlannerService->createDraft(
            $plot,
            $validated['planning_date'] ?? null,
            $request->user()->gardenOwner
        );

        return response()->json([
            'draft' => $this->serializeRotationDraft($draft),
        ], 201);
    }

    public function confirm(
        Request $request,
        Plot $plot,
        RotationPlanDraft $rotationPlanDraft,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService,
        RotationPlannerService $rotationPlannerService
    ): JsonResponse {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);
        abort_unless((int) $rotationPlanDraft->plot_id === (int) $plot->id, 404);

        $result = $rotationPlannerService->confirmDraft(
            $plot,
            $rotationPlanDraft,
            $plotSnapshotService,
            $request->user()->gardenOwner
        );

        return response()->json([
            'planning_date' => $result['planning_date'],
            'changed_plant_ids' => $result['changed_plant_ids'],
            'plants' => PlantResource::collection($result['plants'])->resolve(),
            'plant_zones' => collect($result['plant_zones'])->map(fn (PlantZone $zone) => $zone->toArray())->all(),
            'rotation_history' => collect($result['rotation_history'])
                ->map(fn (RotationHistory $history) => $this->serializeRotationHistory($history))
                ->all(),
        ]);
    }

    public function reject(
        Request $request,
        Plot $plot,
        RotationPlanDraft $rotationPlanDraft,
        AccessService $accessService,
        RotationPlannerService $rotationPlannerService
    ): JsonResponse {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);
        abort_unless((int) $rotationPlanDraft->plot_id === (int) $plot->id, 404);

        $rotationPlannerService->rejectDraft($rotationPlanDraft);

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRotationDraft(RotationPlanDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'planning_date' => $draft->planning_date?->toDateString(),
            'created_at' => $draft->created_at?->toIso8601String(),
            'plan' => $draft->plan ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRotationHistory(RotationHistory $history): array
    {
        return [
            'id' => $history->id,
            'from_date' => $history->from_date?->toDateString(),
            'to_date' => $history->to_date?->toDateString(),
            'fk_plot_id' => $history->fk_plot_id,
            'fk_plant_zone_id' => $history->fk_plant_zone_id,
            'fk_plant_id' => $history->fk_plant_id,
            'plant_zone' => $history->plantZone ? [
                'id' => $history->plantZone->id,
                'name' => $history->plantZone->name,
            ] : null,
            'plant' => $history->plant ? [
                'id' => $history->plant->id,
                'name' => $history->plant->name,
                'type' => $history->plant->type?->value ?? $history->plant->type,
            ] : null,
        ];
    }
}
