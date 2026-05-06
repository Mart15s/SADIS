<?php

namespace App\Services\Plot;

use App\Models\GardenOwner;
use App\Models\Plant;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Services\Plant\CatalogPlantService;
use App\Services\Plant\PlantCareService;
use BackedEnum;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlotWorkspaceService
{
    public function __construct(
        private readonly CatalogPlantService $catalogPlantService,
        private readonly PlantCareService $plantCareService,
    ) {
    }

    /**
     * @param  array{
     *     plot: array<string, mixed>,
     *     zones: array<int, array<string, mixed>>,
     *     plants: array<int, array<string, mixed>>
     * }  $payload
     * @return array{
     *     plot: Plot,
     *     zones: array<int, array<string, mixed>>,
     *     plants: EloquentCollection<int, Plant>,
     *     history_entry: array<string, mixed>|null,
     *     changes: array<string, mixed>
     * }
     */
    public function commitDraft(
        Plot $plot,
        GardenOwner $owner,
        array $payload,
        PlotSnapshotService $plotSnapshotService,
    ): array {
        return DB::transaction(function () use ($plot, $owner, $payload, $plotSnapshotService): array {
            $plot = $plot->fresh(['plantZones', 'plants.catalogPlant.plantCare']);

            if (! $plot) {
                throw ValidationException::withMessages([
                    'plot' => ['The plot could not be refreshed for saving.'],
                ]);
            }

            $changes = [
                'plot_changed' => false,
                'zones' => [
                    'created' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                ],
                'plants' => [
                    'created' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                ],
            ];

            $plotAttributes = [
                'plot_size' => round((float) $payload['plot']['plot_size'], 2),
                'geometry' => $payload['plot']['geometry'] ?? null,
            ];

            if ($this->hasModelChanges($plot, $plotAttributes, ['plot_size', 'geometry'])) {
                $plot->update($plotAttributes);
                $changes['plot_changed'] = true;
            }

            $existingZones = $plot->plantZones()->get()->keyBy(fn (PlantZone $zone): string => (string) $zone->id);
            $existingPlants = $plot->plants()->with('catalogPlant.plantCare')->get()->keyBy(fn (Plant $plant): string => (string) $plant->id);
            $zoneReferenceMap = [];
            $retainedZoneIds = [];
            $retainedPlantIds = [];

            foreach ($payload['zones'] as $zonePayload) {
                $zoneAttributes = [
                    'name' => $zonePayload['name'],
                    'zone_size' => round((float) $zonePayload['zone_size'], 2),
                    'soil_type' => $zonePayload['soil_type'],
                    'rotation_stage' => (int) ($zonePayload['rotation_stage'] ?? 0),
                    'last_planting_date' => $zonePayload['last_planting_date'] ?? null,
                    'geometry' => $zonePayload['geometry'] ?? null,
                ];
                $referenceKey = $this->draftReferenceKey($zonePayload);
                $zoneId = $zonePayload['id'] ?? null;

                if ($this->isPersistedIdentifier($zoneId)) {
                    $zone = $existingZones->get((string) $zoneId);

                    if (! $zone) {
                        throw ValidationException::withMessages([
                            'zones' => ["Zone {$zoneId} does not belong to the selected plot."],
                        ]);
                    }

                    if ($this->hasModelChanges($zone, $zoneAttributes, ['name', 'zone_size', 'soil_type', 'rotation_stage', 'last_planting_date', 'geometry'])) {
                        $zone->update($zoneAttributes);
                        $changes['zones']['updated'] += 1;
                    }

                    $retainedZoneIds[] = $zone->id;
                    $zoneReferenceMap[(string) $zone->id] = $zone->id;
                    $zoneReferenceMap[$referenceKey] = $zone->id;
                    continue;
                }

                $zone = PlantZone::query()->create(array_merge($zoneAttributes, [
                    'plot_id' => $plot->id,
                    'fk_plot_id' => $plot->id,
                ]));

                $changes['zones']['created'] += 1;
                $retainedZoneIds[] = $zone->id;
                $zoneReferenceMap[$referenceKey] = $zone->id;
                $zoneReferenceMap[(string) $zone->id] = $zone->id;
            }

            foreach ($payload['plants'] as $plantPayload) {
                $resolvedZoneId = $this->resolveZoneReference($plantPayload['fk_plant_zone_id'], $zoneReferenceMap);
                $plantAttributes = [
                    'name' => $plantPayload['name'],
                    'type' => $plantPayload['type'] ?? null,
                    'condition' => $plantPayload['condition'],
                    'plant_date' => $plantPayload['plant_date'],
                    'disease' => (bool) ($plantPayload['disease'] ?? false),
                    'disease_notes' => $plantPayload['disease_notes'] ?? null,
                    'fk_catalog_plant_id' => $plantPayload['fk_catalog_plant_id'] ?? null,
                    'plant_zone_id' => $resolvedZoneId,
                    'fk_plant_zone_id' => $resolvedZoneId,
                    'fk_plot_id' => $plot->id,
                ];
                $plantId = $plantPayload['id'] ?? null;

                if ($this->isPersistedIdentifier($plantId)) {
                    $plant = $existingPlants->get((string) $plantId);

                    if (! $plant) {
                        throw ValidationException::withMessages([
                            'plants' => ["Plant {$plantId} does not belong to the selected plot."],
                        ]);
                    }

                    $catalogChanged = (int) ($plant->fk_catalog_plant_id ?? 0) !== (int) ($plantAttributes['fk_catalog_plant_id'] ?? 0);
                    $needsPlantUpdate = $this->hasModelChanges($plant, $plantAttributes, [
                        'name',
                        'type',
                        'condition',
                        'plant_date',
                        'disease',
                        'disease_notes',
                        'fk_catalog_plant_id',
                        'plant_zone_id',
                        'fk_plant_zone_id',
                    ]);

                    if ($needsPlantUpdate) {
                        $plant->update($plantAttributes);
                        $changes['plants']['updated'] += 1;
                    }

                    if ($catalogChanged) {
                        $this->syncCatalogPlant($plant->fresh(), $plantAttributes['fk_catalog_plant_id']);
                        $this->syncPlantCare(
                            $plant->fresh(),
                            true,
                            $plantPayload['perenual_species_id'] ?? null,
                        );
                    }

                    $retainedPlantIds[] = $plant->id;
                    continue;
                }

                $plant = Plant::query()->create($plantAttributes);
                $this->syncCatalogPlant($plant->fresh(), $plantAttributes['fk_catalog_plant_id']);
                $this->syncPlantCare(
                    $plant->fresh(),
                    true,
                    $plantPayload['perenual_species_id'] ?? null,
                );

                $changes['plants']['created'] += 1;
                $retainedPlantIds[] = $plant->id;
            }

            $plantsToDelete = $existingPlants
                ->reject(fn (Plant $plant): bool => in_array($plant->id, $retainedPlantIds, true));

            if ($plantsToDelete->isNotEmpty()) {
                Plant::query()->whereIn('id', $plantsToDelete->pluck('id')->all())->delete();
                $changes['plants']['deleted'] = $plantsToDelete->count();
            }

            $zonesToDelete = $existingZones
                ->reject(fn (PlantZone $zone): bool => in_array($zone->id, $retainedZoneIds, true));

            if ($zonesToDelete->isNotEmpty()) {
                PlantZone::query()->whereIn('id', $zonesToDelete->pluck('id')->all())->delete();
                $changes['zones']['deleted'] = $zonesToDelete->count();
            }

            $historyEntry = null;
            if ($this->hasMeaningfulChanges($changes)) {
                $plotSnapshotService->captureCommittedVersion(
                    $plot->fresh(['plantZones', 'plants']),
                    $owner,
                    $this->buildHistoryMetadata($changes),
                );

                $historyEntry = $plotSnapshotService
                    ->listHistoryForPlot($plot->fresh(), 1)
                    ->first();
            }

            $freshPlot = $plot->fresh(['plantZones', 'plants.catalogPlant.plantCare']);

            return [
                'plot' => $freshPlot,
                'zones' => $freshPlot?->plantZones()->orderBy('id')->get()->map->toArray()->all() ?? [],
                'plants' => $freshPlot?->plants()->with(['plot', 'plantZone', 'catalogPlant.plantCare'])->orderBy('id')->get()
                    ?? new EloquentCollection(),
                'history_entry' => $historyEntry,
                'changes' => $changes,
            ];
        });
    }

    /**
     * @param  array<string, int|bool|array<string, int>>  $changes
     * @return array<string, mixed>
     */
    private function buildHistoryMetadata(array $changes): array
    {
        $zoneSummary = $changes['zones'];
        $plantSummary = $changes['plants'];
        $parts = [];

        if ($changes['plot_changed']) {
            $parts[] = 'layout updated';
        }

        foreach ([
            ['count' => $zoneSummary['created'], 'label' => 'zone added'],
            ['count' => $zoneSummary['updated'], 'label' => 'zone updated'],
            ['count' => $zoneSummary['deleted'], 'label' => 'zone removed'],
            ['count' => $plantSummary['created'], 'label' => 'plant added'],
            ['count' => $plantSummary['updated'], 'label' => 'plant updated'],
            ['count' => $plantSummary['deleted'], 'label' => 'plant removed'],
        ] as $entry) {
            if ($entry['count'] > 0) {
                $parts[] = sprintf(
                    '%d %s%s',
                    $entry['count'],
                    $entry['label'],
                    $entry['count'] === 1 ? '' : 's',
                );
            }
        }

        $label = 'Saved plot version';

        if ($changes['plot_changed'] && ($plantSummary['created'] + $plantSummary['updated'] + $plantSummary['deleted']) === 0) {
            $label = 'Saved layout update';
        } elseif (($zoneSummary['created'] + $zoneSummary['updated'] + $zoneSummary['deleted']) > 0 && ($plantSummary['created'] + $plantSummary['updated'] + $plantSummary['deleted']) === 0) {
            $label = 'Committed zone changes';
        } elseif (($plantSummary['created'] + $plantSummary['updated'] + $plantSummary['deleted']) > 0 && ! $changes['plot_changed']) {
            $label = 'Saved planting update';
        }

        return [
            'label' => $label,
            'summary' => $parts === [] ? 'No visible workspace changes were detected.' : ucfirst(implode(', ', $parts)).'.',
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, int|bool|array<string, int>>  $changes
     */
    private function hasMeaningfulChanges(array $changes): bool
    {
        return $changes['plot_changed']
            || array_sum($changes['zones']) > 0
            || array_sum($changes['plants']) > 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function draftReferenceKey(array $payload): string
    {
        $clientId = $payload['client_id'] ?? $payload['id'] ?? null;

        return (string) $clientId;
    }

    /**
     * @param  mixed  $reference
     */
    private function resolveZoneReference(mixed $reference, array $zoneReferenceMap): int
    {
        $key = (string) $reference;

        if (isset($zoneReferenceMap[$key])) {
            return (int) $zoneReferenceMap[$key];
        }

        throw ValidationException::withMessages([
            'plants' => ["Plant zone reference {$key} is not available in the current draft save."],
        ]);
    }

    /**
     * @param  mixed  $identifier
     */
    private function isPersistedIdentifier(mixed $identifier): bool
    {
        return is_int($identifier) || (is_string($identifier) && ctype_digit($identifier));
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function hasModelChanges(object $model, array $attributes, array $keys): bool
    {
        foreach ($keys as $key) {
            $current = $this->normalizeComparableValue($this->currentModelValue($model, $key));
            $next = $this->normalizeComparableValue($attributes[$key] ?? null);

            if (is_array($current) || is_array($next)) {
                if (json_encode($current) !== json_encode($next)) {
                    return true;
                }

                continue;
            }

            if ((string) ($current ?? '') !== (string) ($next ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function currentModelValue(object $model, string $key): mixed
    {
        $value = $model->{$key};

        if ($value !== null) {
            return $value;
        }

        return match ($key) {
            'plant_zone_id' => $model->fk_plant_zone_id ?? null,
            'fk_plant_zone_id' => $model->plant_zone_id ?? null,
            default => $value,
        };
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    private function syncCatalogPlant(Plant $plant, ?int $catalogPlantId): void
    {
        if ($catalogPlantId === null) {
            return;
        }

        $catalogPlant = \App\Models\CatalogPlant::query()->with('plantCare')->findOrFail($catalogPlantId);
        $this->catalogPlantService->assignCatalogPlantToPlant($plant, $catalogPlant);
    }

    private function syncPlantCare(Plant $plant, bool $ensureLinked, ?int $speciesId = null): void
    {
        if (! $ensureLinked) {
            return;
        }

        $this->plantCareService->syncPlantCareConfiguration(
            $plant,
            [],
            null,
            false,
            $speciesId,
        );
    }
}
