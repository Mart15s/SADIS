<?php

namespace App\Http\Controllers\Plant;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Enums\UserRole;
use App\Http\Controllers\Concerns\AuthorizesPlotAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\Plant\PlantResource;
use App\Models\CatalogPlant;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Services\Plot\AccessService;
use App\Services\Plant\CatalogPlantService;
use App\Services\Integrations\PerenualService;
use App\Services\Plant\PlantCareService;
use App\Services\Plant\PlantLifecycleService;
use App\Services\Plot\PlotSnapshotService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlantController extends Controller
{
    use AuthorizesPlotAccess;

    public function listAll(Request $request, AccessService $accessService): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $plants = $this->accessiblePlantQuery($request, $accessService)
            ->with(['plot', 'plantZone', 'catalogPlant.plantCare'])
            ->when(
                filled($validated['q'] ?? null),
                fn (Builder $query) => $this->applyPlantSearch($query, (string) $validated['q'])
            )
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(
            PlantResource::collection($plants)->resolve()
        );
    }

    public function index(Request $request, Plot $plot, AccessService $accessService): JsonResponse
    {
        $this->ensureUserCanViewPlot($request, $plot, $accessService);

        return response()->json(
            PlantResource::collection(
                $plot->plants()->with(['plot', 'plantZone', 'catalogPlant.plantCare'])->get()
            )->resolve()
        );
    }

    public function search(Request $request, PerenualService $perenualService): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        return response()->json(
            $perenualService->searchPlants($validated['q'])
        );
    }

    public function storeGlobal(
        Request $request,
        AccessService $accessService,
        CatalogPlantService $catalogPlantService,
        PlantCareService $plantCareService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $validated = $this->hydrateCatalogDefaults(
            $this->validateGlobalPlantPayload($request)
        );
        $plot = Plot::query()->findOrFail($validated['fk_plot_id']);

        $this->ensureUserCanEditPlot($request, $plot, $accessService);

        $zone = $this->resolveZoneForPlot($plot, (int) $validated['fk_plant_zone_id']);

        $plant = DB::transaction(function () use (
            $validated,
            $plot,
            $zone,
            $request,
            $catalogPlantService,
            $plantCareService,
            $plotSnapshotService,
        ): Plant {
            $plant = Plant::query()->create($this->extractPlantAttributes($validated, $plot->id, $zone->id));

            $this->syncCatalogPlant(
                $plant,
                $validated['fk_catalog_plant_id'] ?? null,
                $catalogPlantService
            );

            $this->syncPlantCare(
                $plant,
                $plantCareService,
                true,
                $validated['perenual_species_id'] ?? null
            );

            $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plant_created', $request->user()->gardenOwner, [
                'plant_id' => $plant->id,
            ]);

            return $plant->fresh(['plot', 'plantZone', 'catalogPlant.plantCare']);
        });

        return response()->json(
            PlantResource::make($plant)->resolve(),
            201
        );
    }

    public function store(
        Request $request,
        Plot $plot,
        AccessService $accessService,
        CatalogPlantService $catalogPlantService,
        PlantCareService $plantCareService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $this->ensureUserCanEditPlot($request, $plot, $accessService);

        $validated = $request->validate([
            'name' => ['required_without:fk_catalog_plant_id', 'nullable', 'string', 'max:255'],
            'growing_time_days' => ['nullable', 'integer', 'min:0'],
            'recommended_temperature' => ['nullable', 'numeric'],
            'recommended_humidity' => ['nullable', 'numeric'],
            'plant_date' => ['required', 'date'],
            'disease' => ['sometimes', 'boolean'],
            'disease_notes' => ['nullable', 'string', 'max:255'],
            'rest_time_days' => ['nullable', 'integer', 'min:0'],
            'plant_size' => ['nullable', 'numeric', 'min:0'],
            'photo_url' => ['nullable', 'string', 'max:255'],
            'reusable' => ['sometimes', 'boolean'],
            'type' => ['required_without:fk_catalog_plant_id', 'nullable', Rule::enum(PlantType::class)],
            'condition' => ['required', Rule::enum(ConditionType::class)],
            'fk_plant_care_id' => ['prohibited'],
            'fk_catalog_plant_id' => ['nullable', 'integer', 'exists:catalog_plants,id'],
            'plant_care' => ['prohibited'],
            'plant_zone_id' => ['nullable', 'integer', 'exists:plant_zones,id'],
            'fk_plant_zone_id' => ['required', 'integer', 'exists:plant_zones,id'],
            'from_catalog' => ['sometimes', 'boolean'],
            'perenual_species_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $validated = $this->hydrateCatalogDefaults($validated);

        $validated['plant_zone_id'] = $validated['plant_zone_id'] ?? $validated['fk_plant_zone_id'];
        $zone = $this->resolveZoneForPlot($plot, (int) $validated['plant_zone_id']);

        $plant = DB::transaction(function () use (
            $validated,
            $plot,
            $zone,
            $request,
            $catalogPlantService,
            $plantCareService,
            $plotSnapshotService
        ): Plant {
            $plant = Plant::query()->create(array_merge(
                Arr::except($validated, ['from_catalog', 'perenual_species_id']),
                [
                    'plant_zone_id' => $zone->id,
                    'fk_plant_zone_id' => $zone->id,
                    'fk_plot_id' => $plot->id,
                    'reusable' => (bool) ($validated['reusable'] ?? false),
                    'disease' => (bool) ($validated['disease'] ?? false),
                ]
            ));

            $this->syncCatalogPlant(
                $plant,
                $validated['fk_catalog_plant_id'] ?? null,
                $catalogPlantService
            );

            $this->syncPlantCare(
                $plant,
                $plantCareService,
                true,
                $validated['perenual_species_id'] ?? null
            );

            $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plant_created', $request->user()->gardenOwner, [
                'plant_id' => $plant->id,
            ]);

            return $plant->fresh(['plot', 'plantZone', 'catalogPlant.plantCare']);
        });

        return response()->json(
            PlantResource::make($plant)->resolve(),
            201
        );
    }

    public function showGlobal(
        Request $request,
        Plant $plant,
        AccessService $accessService,
        PlantCareService $plantCareService,
        PlantLifecycleService $plantLifecycleService,
    ): JsonResponse {
        $plot = $plant->plot()->firstOrFail();

        $this->ensureUserCanViewPlot($request, $plot, $accessService);

        if (! $plant->fk_catalog_plant_id || ! $plant->catalogPlant()->whereNotNull('fk_plant_care_id')->exists()) {
            $plantCareService->ensureLinkedCareProfile($plant);
        }

        $plant = $plant->fresh(['plot', 'plantZone', 'catalogPlant.plantCare', 'conditionHistory', 'harvestRecords']);
        $care = $plant->effectivePlantCare();

        if ($care) {
            $plant->setAttribute('lifecycle_summary', $plantLifecycleService->buildSummary($plant, $care));
        }

        return response()->json(PlantResource::make($plant)->resolve());
    }

    public function show(
        Request $request,
        Plot $plot,
        Plant $plant,
        AccessService $accessService,
        PlantCareService $plantCareService,
        PlantLifecycleService $plantLifecycleService,
    ): JsonResponse {
        $this->authorizePlantView($request, $plot, $plant, $accessService);

        if (! $plant->fk_catalog_plant_id || ! $plant->catalogPlant()->whereNotNull('fk_plant_care_id')->exists()) {
            $plantCareService->ensureLinkedCareProfile($plant);
        }

        $plant = $plant->fresh(['plot', 'plantZone', 'catalogPlant.plantCare', 'conditionHistory', 'harvestRecords']);
        $care = $plant->effectivePlantCare();

        if ($care) {
            $plant->setAttribute('lifecycle_summary', $plantLifecycleService->buildSummary($plant, $care));
        }

        return response()->json(PlantResource::make($plant)->resolve());
    }

    public function updateGlobal(
        Request $request,
        Plant $plant,
        AccessService $accessService,
        CatalogPlantService $catalogPlantService,
        PlantCareService $plantCareService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $plot = $plant->plot()->firstOrFail();

        $this->ensureUserCanEditPlot($request, $plot, $accessService);

        $validated = $this->validateGlobalPlantPayload($request, true);
        $zoneId = $validated['fk_plant_zone_id'] ?? $plant->plant_zone_id ?? $plant->fk_plant_zone_id;
        $zone = $this->resolveZoneForPlot($plot, (int) $zoneId);

        $plant = DB::transaction(function () use (
            $validated,
            $plant,
            $plot,
            $zone,
            $request,
            $catalogPlantService,
            $plantCareService,
            $plotSnapshotService
        ): Plant {
            $plant->update($this->extractPlantAttributes($validated, $plot->id, $zone->id, true));

            $this->syncCatalogPlant(
                $plant->refresh(),
                $validated['fk_catalog_plant_id'] ?? null,
                $catalogPlantService
            );

            $this->syncPlantCare(
                $plant->refresh(),
                $plantCareService,
                array_key_exists('fk_catalog_plant_id', $validated)
                || ! $plant->fk_catalog_plant_id,
                $validated['perenual_species_id'] ?? null,
            );

            $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plant_updated', $request->user()->gardenOwner, [
                'plant_id' => $plant->id,
            ]);

            return $plant->fresh(['plot', 'plantZone', 'catalogPlant.plantCare']);
        });

        return response()->json(
            PlantResource::make($plant)->resolve()
        );
    }

    public function update(
        Request $request,
        Plot $plot,
        Plant $plant,
        AccessService $accessService,
        CatalogPlantService $catalogPlantService,
        PlantCareService $plantCareService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $this->authorizePlantEdit($request, $plot, $plant, $accessService);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'growing_time_days' => ['nullable', 'integer', 'min:0'],
            'recommended_temperature' => ['nullable', 'numeric'],
            'recommended_humidity' => ['nullable', 'numeric'],
            'plant_date' => ['sometimes', 'date'],
            'disease' => ['sometimes', 'boolean'],
            'disease_notes' => ['nullable', 'string', 'max:255'],
            'rest_time_days' => ['nullable', 'integer', 'min:0'],
            'plant_size' => ['nullable', 'numeric', 'min:0'],
            'photo_url' => ['nullable', 'string', 'max:255'],
            'reusable' => ['sometimes', 'boolean'],
            'type' => ['sometimes', Rule::enum(PlantType::class)],
            'condition' => ['sometimes', Rule::enum(ConditionType::class)],
            'fk_plant_care_id' => ['prohibited'],
            'fk_catalog_plant_id' => ['nullable', 'integer', 'exists:catalog_plants,id'],
            'plant_care' => ['prohibited'],
            'plant_zone_id' => ['sometimes', 'integer', 'exists:plant_zones,id'],
            'fk_plant_zone_id' => ['sometimes', 'integer', 'exists:plant_zones,id'],
        ]);

        if (array_key_exists('fk_plant_zone_id', $validated) && ! array_key_exists('plant_zone_id', $validated)) {
            $validated['plant_zone_id'] = $validated['fk_plant_zone_id'];
        }

        if (array_key_exists('plant_zone_id', $validated)) {
            $zone = $this->resolveZoneForPlot($plot, (int) $validated['plant_zone_id']);
            $validated['plant_zone_id'] = $zone->id;
            $validated['fk_plant_zone_id'] = $zone->id;
        }

        $plant = DB::transaction(function () use (
            $validated,
            $plant,
            $plot,
            $request,
            $catalogPlantService,
            $plantCareService,
            $plotSnapshotService
        ): Plant {
            $plant->update($validated);

            $this->syncCatalogPlant(
                $plant->refresh(),
                $validated['fk_catalog_plant_id'] ?? null,
                $catalogPlantService
            );

            $this->syncPlantCare(
                $plant->refresh(),
                $plantCareService,
                array_key_exists('fk_catalog_plant_id', $validated)
                || ! $plant->fk_catalog_plant_id,
                null,
            );

            $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plant_updated', $request->user()->gardenOwner, [
                'plant_id' => $plant->id,
            ]);

            return $plant->fresh(['plot', 'plantZone', 'catalogPlant.plantCare']);
        });

        return response()->json(
            PlantResource::make($plant)->resolve()
        );
    }

    public function destroyGlobal(
        Request $request,
        Plant $plant,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $plot = $plant->plot()->firstOrFail();

        $this->ensureUserCanEditPlot($request, $plot, $accessService);

        DB::transaction(function () use ($plot, $plant, $request, $plotSnapshotService): void {
            $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plant_deleted', $request->user()->gardenOwner, [
                'plant_id' => $plant->id,
            ]);

            $plant->delete();
        });

        return response()->json(status: 204);
    }

    public function destroy(
        Request $request,
        Plot $plot,
        Plant $plant,
        AccessService $accessService,
        PlotSnapshotService $plotSnapshotService
    ): JsonResponse {
        $this->authorizePlantEdit($request, $plot, $plant, $accessService);

        DB::transaction(function () use ($plot, $plant, $request, $plotSnapshotService): void {
            $plotSnapshotService->capture($plot->fresh(['plantZones', 'plants']), 'plant_deleted', $request->user()->gardenOwner, [
                'plant_id' => $plant->id,
            ]);

            $plant->delete();
        });

        return response()->json(status: 204);
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

    private function resolveZoneForPlot(Plot $plot, int $zoneId): PlantZone
    {
        $zone = PlantZone::query()
            ->whereKey($zoneId)
            ->where('plot_id', $plot->id)
            ->first();

        abort_unless($zone, 422, 'The selected plant zone does not belong to the plot.');

        return $zone;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGlobalPlantPayload(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required_without:fk_catalog_plant_id', 'nullable', 'string', 'max:255'],
            'growing_time_days' => ['nullable', 'integer', 'min:0'],
            'recommended_temperature' => ['nullable', 'numeric'],
            'recommended_humidity' => ['nullable', 'numeric'],
            'plant_date' => [$partial ? 'sometimes' : 'required', 'date'],
            'disease' => ['sometimes', 'boolean'],
            'disease_notes' => ['nullable', 'string', 'max:255'],
            'rest_time_days' => ['nullable', 'integer', 'min:0'],
            'plant_size' => ['nullable', 'numeric', 'min:0'],
            'photo_url' => ['nullable', 'string', 'max:255'],
            'reusable' => ['sometimes', 'boolean'],
            'type' => [$partial ? 'sometimes' : 'required_without:fk_catalog_plant_id', 'nullable', Rule::enum(PlantType::class)],
            'condition' => [$partial ? 'sometimes' : 'required', Rule::enum(ConditionType::class)],
            'fk_plot_id' => [$partial ? 'nullable' : 'required', 'integer', 'exists:plots,id'],
            'fk_plant_zone_id' => [$partial ? 'nullable' : 'required', 'integer', 'exists:plant_zones,id'],
            'fk_plant_care_id' => ['prohibited'],
            'fk_catalog_plant_id' => ['nullable', 'integer', 'exists:catalog_plants,id'],
            'plant_care' => ['prohibited'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPlantAttributes(array $validated, int $plotId, int $zoneId, bool $partial = false): array
    {
        $attributes = Arr::only($validated, [
            'name',
            'growing_time_days',
            'recommended_temperature',
            'recommended_humidity',
            'plant_date',
            'disease',
            'disease_notes',
            'rest_time_days',
            'plant_size',
            'photo_url',
            'reusable',
            'type',
            'condition',
            'fk_catalog_plant_id',
        ]);

        if (! $partial || array_key_exists('fk_plant_zone_id', $validated)) {
            $attributes['plant_zone_id'] = $zoneId;
            $attributes['fk_plant_zone_id'] = $zoneId;
        }

        if (! $partial) {
            $attributes['fk_plot_id'] = $plotId;
        }

        if (array_key_exists('disease', $attributes)) {
            $attributes['disease'] = (bool) $attributes['disease'];
        }

        if (array_key_exists('reusable', $attributes)) {
            $attributes['reusable'] = (bool) $attributes['reusable'];
        }

        return $attributes;
    }

    /**
     * Plant care for planted records is always shared. Editing happens on the catalog plant.
     */
    private function syncPlantCare(
        Plant $plant,
        PlantCareService $plantCareService,
        bool $ensureLinked = false,
        ?int $speciesId = null,
    ): void {
        if (! $ensureLinked) {
            return;
        }

        $plantCareService->syncPlantCareConfiguration(
            $plant,
            [],
            null,
            false,
            $speciesId,
        );
    }

    private function applyPlantSearch(Builder $query, string $search): void
    {
        $term = '%'.mb_strtolower($search).'%';

        $query->where(function (Builder $plantQuery) use ($term): void {
            $plantQuery
                ->orWhereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereHas('catalogPlant.plantCare', function (Builder $careQuery) use ($term): void {
                    $careQuery
                        ->whereRaw('LOWER(plant_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(canonical_name) LIKE ?', [$term]);
                })
                ->orWhereHas('catalogPlant', function (Builder $catalogQuery) use ($term): void {
                    $catalogQuery
                        ->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(canonical_name) LIKE ?', [$term]);
                })
                ->orWhereHas('plot', fn (Builder $plotQuery) => $plotQuery->whereRaw('LOWER(name) LIKE ?', [$term]))
                ->orWhereHas('plantZone', fn (Builder $zoneQuery) => $zoneQuery->whereRaw('LOWER(name) LIKE ?', [$term]));
        });
    }

    private function syncCatalogPlant(
        Plant $plant,
        ?int $selectedCatalogPlantId,
        CatalogPlantService $catalogPlantService
    ): void {
        if ($selectedCatalogPlantId === null) {
            return;
        }

        $catalogPlant = CatalogPlant::query()->with('plantCare')->findOrFail($selectedCatalogPlantId);
        $catalogPlantService->assignCatalogPlantToPlant($plant, $catalogPlant);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function hydrateCatalogDefaults(array $validated): array
    {
        $catalogPlantId = $validated['fk_catalog_plant_id'] ?? null;

        if ($catalogPlantId === null) {
            return $validated;
        }

        $catalogPlant = CatalogPlant::query()->with('plantCare')->findOrFail($catalogPlantId);

        $validated['name'] ??= $catalogPlant->name;
        $validated['type'] ??= $catalogPlant->plant_type?->value ?? $catalogPlant->plant_type;

        return $validated;
    }

    private function accessiblePlantQuery(Request $request, AccessService $accessService): Builder
    {
        if (($request->user()?->role?->value ?? $request->user()?->role) === UserRole::Admin->value) {
            return Plant::query();
        }

        $plotIds = $accessService->accessiblePlotIds($request->user()->gardenOwner);

        return Plant::query()->whereIn('fk_plot_id', $plotIds === [] ? [0] : $plotIds);
    }
}
