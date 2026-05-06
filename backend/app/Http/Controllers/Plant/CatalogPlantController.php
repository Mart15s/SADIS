<?php

namespace App\Http\Controllers\Plant;

use App\Enums\PlantType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Plant\CatalogPlantResource;
use App\Models\CatalogPlant;
use App\Services\Plant\CatalogPlantService;
use App\Services\Integrations\PerenualService;
use App\Support\PlantCareName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogPlantController extends Controller
{
    public function searchPerenual(Request $request, PerenualService $perenualService): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:9'],
        ]);

        return response()->json(
            $perenualService->searchPlants(
                $validated['q'],
                isset($validated['limit']) ? (int) $validated['limit'] : null
            )
        );
    }

    public function previewPerenualSpecies(int $speciesId, CatalogPlantService $catalogPlantService): JsonResponse
    {
        abort_if($speciesId < 1, 422, 'The selected Perenual species is invalid.');

        return response()->json(
            $catalogPlantService->buildPerenualDraft($speciesId)
        );
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $catalogPlants = CatalogPlant::query()
            ->with(['plantCare'])
            ->withCount('plants')
            ->when(
                filled($validated['q'] ?? null),
                fn (Builder $query) => $this->applySearch($query, (string) $validated['q'])
            )
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(
            CatalogPlantResource::collection($catalogPlants)->resolve()
        );
    }

    public function show(CatalogPlant $catalogPlant): JsonResponse
    {
        return response()->json(
            CatalogPlantResource::make(
                $catalogPlant->load(['plantCare'])->loadCount('plants')
            )->resolve()
        );
    }

    public function store(Request $request, CatalogPlantService $catalogPlantService): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $catalogPlant = DB::transaction(
            fn () => $catalogPlantService->saveCatalogPlant($validated)
        );

        return response()->json(
            CatalogPlantResource::make($catalogPlant->loadCount('plants'))->resolve(),
            201
        );
    }

    public function update(
        Request $request,
        CatalogPlant $catalogPlant,
        CatalogPlantService $catalogPlantService
    ): JsonResponse {
        $validated = $this->validatePayload($request, $catalogPlant);

        $catalogPlant = DB::transaction(
            fn () => $catalogPlantService->saveCatalogPlant($validated, $catalogPlant)
        );

        return response()->json(
            CatalogPlantResource::make($catalogPlant->loadCount('plants'))->resolve()
        );
    }

    public function destroy(CatalogPlant $catalogPlant): JsonResponse
    {
        $catalogPlant->loadCount('plants');

        abort_if(
            $catalogPlant->plants_count > 0,
            422,
            'This catalog plant is already used by planted records and cannot be deleted.'
        );

        $catalogPlant->delete();

        return response()->json(status: 204);
    }

    private function validatePayload(Request $request, ?CatalogPlant $catalogPlant = null): array
    {
        if ($request->filled('canonical_name')) {
            $request->merge([
                'canonical_name' => PlantCareName::normalize(
                    $request->input('canonical_name')
                ),
            ]);
        } elseif (! $catalogPlant && $request->filled('name')) {
            $request->merge([
                'canonical_name' => PlantCareName::normalize($request->input('name')),
            ]);
        }

        return $request->validate([
            'name' => [$catalogPlant ? 'sometimes' : 'required', 'string', 'max:255'],
            'canonical_name' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('catalog_plants', 'canonical_name')->ignore($catalogPlant?->id),
            ],
            'plant_type' => [$catalogPlant ? 'sometimes' : 'required', Rule::enum(PlantType::class)],
            'description' => ['nullable', 'string'],
            'fk_plant_care_id' => ['nullable', 'integer', 'exists:plant_care,id'],
            'source_provider' => ['nullable', 'string', 'max:255'],
            'source_quality' => ['nullable', 'string', 'max:255'],
            'source_scientific_name' => ['nullable', 'string', 'max:255'],
            'source_family' => ['nullable', 'string', 'max:255'],
            'source_image_url' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'perenual_species_id' => ['nullable', 'integer', 'min:1'],
            'plant_care' => ['sometimes', 'array'],
            'plant_care.description' => ['nullable', 'string'],
            'plant_care.conditions' => ['nullable', 'string'],
            'plant_care.watering_interval_days' => ['nullable', 'integer', 'min:0'],
            'plant_care.fertilizing_interval_days' => ['nullable', 'integer', 'min:0'],
            'plant_care.pest_check_interval_days' => ['nullable', 'integer', 'min:0'],
            'plant_care.rain_skip_threshold_mm' => ['nullable', 'numeric'],
            'plant_care.frost_temp_threshold_c' => ['nullable', 'numeric'],
            'plant_care.heat_extra_water_temp_c' => ['nullable', 'numeric'],
            'plant_care.wind_protection_kmh' => ['nullable', 'numeric', 'min:0'],
            'plant_care.reusable' => ['nullable', 'boolean'],
            'plant_care.growing_duration_days' => ['nullable', 'integer', 'min:0'],
            'plant_care.germinating_duration_days' => ['nullable', 'integer', 'min:0'],
            'plant_care.flowering_duration_days' => ['nullable', 'integer', 'min:0'],
            'plant_care.mature_duration_days' => ['nullable', 'integer', 'min:0'],
            'plant_care.mature_duration_end_days' => ['nullable', 'integer', 'min:0'],
            'plant_care.mature_end_duration_days' => ['nullable', 'integer', 'min:0'],
            'plant_care.regenerating_duration_days' => ['nullable', 'integer', 'min:0'],
            'plant_care.source_perenual_species_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function applySearch(Builder $query, string $search): void
    {
        $term = '%'.mb_strtolower($search).'%';

        $query->where(function (Builder $catalogQuery) use ($term): void {
            $catalogQuery
                ->orWhereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(canonical_name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(source_scientific_name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(source_family) LIKE ?', [$term])
                ->orWhereHas('plantCare', function (Builder $careQuery) use ($term): void {
                    $careQuery
                        ->whereRaw('LOWER(plant_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(canonical_name) LIKE ?', [$term]);
                });
        });
    }
}
