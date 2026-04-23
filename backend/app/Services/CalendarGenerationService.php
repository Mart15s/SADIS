<?php

namespace App\Services;

use App\Enums\ConditionType;
use App\Enums\InventoryItemType;
use App\Enums\TaskPriority;
use App\Enums\TaskState;
use App\Enums\TaskType;
use App\Exceptions\CalendarGenerationException;
use App\Models\GardenOwner;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\Plot;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\TaskResourceRequirement;
use App\Models\WeatherForecast;
use App\ValueObjects\NormalizedTaskResource;
use App\ValueObjects\WeatherData;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CalendarGenerationService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly TaskInventoryCoverageService $taskInventoryCoverageService,
        private readonly PlantCareService $plantCareService,
        private readonly PlantLifecyclePhaseService $plantLifecyclePhaseService,
        private readonly PlantLifecycleService $plantLifecycleService,
        private readonly WeatherService $weatherService,
    ) {
    }

    public function generateCalendar(Plot|int $plot, Carbon $startDate, Carbon $endDate): TaskCalendar
    {
        $plot = $plot instanceof Plot
            ? $plot
            : Plot::query()->findOrFail($plot);

        $plot->loadMissing([
            'gardenOwner',
            'plantZones.rotationHistory',
            'plantZones.plants.catalogPlant.plantCare',
            'plantZones.plants.conditionHistory',
            'plantZones.plants.harvestRecords',
        ]);

        $plants = $this->collectPlants($plot);

        if ($plants->isEmpty()) {
            throw CalendarGenerationException::noPlants();
        }

        return DB::transaction(function () use ($plot, $plants, $startDate, $endDate) {
            $plants->each(function (Plant $plant): void {
                $care = $this->plantCareService->resolveEffectivePlantCare($plant);

                if ($plant->relationLoaded('catalogPlant') && $plant->catalogPlant) {
                    $plant->catalogPlant->setRelation('plantCare', $care);
                }
            });

            $calendar = TaskCalendar::query()->create([
                'creation_date' => now(),
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'plot_id' => $plot->id,
                'fk_plot_id' => $plot->id,
            ]);

            $weatherByDate = $this->weatherService->getForecastRange($plot->city, $startDate, $endDate);

            foreach ($weatherByDate as $date => $weather) {
                $weatherData = WeatherData::fromArray($weather);

                WeatherForecast::query()->create([
                    'date' => $date,
                    'temperature' => $weatherData->averageTemperature(),
                    'temp_min' => $weatherData->tempMin,
                    'temp_max' => $weatherData->tempMax,
                    'precipitation' => $weatherData->precipitationMm,
                    'humidity' => $weatherData->humidity,
                    'wind_kmh' => $weatherData->windKmh,
                    'condition_code' => $weatherData->conditionCode,
                    'is_seasonal_fallback' => $weatherData->isSeasonalFallback,
                    'source' => $weatherData->source,
                    'source_date' => $weatherData->sourceDate,
                    'source_city' => $weatherData->sourceCity,
                    'city' => $plot->city,
                    'task_calendar_id' => $calendar->id,
                    'fk_task_calendar_id' => $calendar->id,
                ]);
            }

            $owner = $this->resolveGardenOwner($plot);
            $inventoryLedger = $this->inventoryService->buildPlanningLedger($owner);
            $existingBuyTasks = $this->loadOpenBuyTasks($plot, $startDate);
            $plannedActions = [];
            $planningActionCounter = 0;

            foreach (CarbonPeriod::create($startDate->copy()->startOfDay(), $endDate->copy()->startOfDay()) as $generationDate) {
                $dateKey = $generationDate->toDateString();
                $weatherData = WeatherData::fromArray($weatherByDate[$dateKey] ?? []);
                $weatherContext = $this->buildWeatherContext($weatherData, $weatherByDate, $generationDate);

                foreach ($plants as $plant) {
                    if ($generationDate->lt($plant->plant_date->copy()->startOfDay())) {
                        continue;
                    }

                    /** @var PlantCare $care */
                    $care = $plant->effectivePlantCare();
                    $simulatedState = $this->plantLifecyclePhaseService->resolveSimulatedPhase($plant, $care, $generationDate);

                    if ($simulatedState['simulated_phase'] === null && ! ($simulatedState['transition']['from'] ?? null)) {
                        continue;
                    }

                    if (($simulatedState['actual_condition'] ?? null) === ConditionType::Dried->value) {
                        continue;
                    }

                    $actions = array_merge(
                        $this->buildBaseActions($plant, $care, $simulatedState),
                        $this->plantLifecycleService->buildActionsForDate(
                            $plant,
                            $care,
                            $simulatedState,
                            $generationDate,
                            $plant->conditionHistory,
                            $plant->harvestRecords,
                        )
                    );
                    $actions = $this->applyWeatherRules($actions, $plant, $care, $simulatedState, $weatherData, $weatherContext);

                    if ($this->hasPreviousZoneRotation($plant) && $generationDate->isSameDay($plant->plant_date)) {
                        $actions[] = [
                            'type' => TaskType::Transplant->value,
                            'name' => "Review rotation for {$plant->name}",
                            'priority' => TaskPriority::Medium->value,
                            'reason' => 'Rotation history shows the same zone was used previously.',
                            'comment' => 'Review the previous seasons before planting in the same zone again.',
                        ];
                    }

                    foreach ($this->deduplicateActions($actions) as $action) {
                        $action['date'] = $dateKey;
                        $action['plant_id'] = $plant->id;
                        $action['zone_id'] = $plant->plant_zone_id;
                        $action['weather_context'] = $weatherContext;
                        $action['simulated_state'] = $simulatedState;
                        $action['required_resources'] = $this->normalizeActionRequirements($action['required_resources'] ?? []);
                        $action['planning_key'] = sprintf('planned-%d', ++$planningActionCounter);

                        $plannedActions[] = $action;
                    }
                }
            }

            [$plannedActions, $buyActions] = $owner
                ? $this->applyDayLevelInventoryPlanning(
                    collect($plannedActions),
                    $inventoryLedger,
                    $existingBuyTasks,
                )
                : [$this->markActionsWithoutInventoryOwner(collect($plannedActions)), collect()];

            $allActions = $this->finalizeActions($plannedActions->concat($buyActions));
            $createdBuyTaskIdsByKey = [];
            $taskBuyDependencies = [];

            foreach ($allActions as $action) {
                $primaryRequirement = $this->primaryRequirement($action['required_resources'] ?? []);
                $inventoryContext = $action['inventory_context'] ?? null;
                $pendingBuyKeys = $inventoryContext['pending_buy_keys'] ?? [];

                if (is_array($inventoryContext)) {
                    unset($inventoryContext['pending_buy_keys']);
                }

                $task = Task::query()->create([
                    'date' => $action['date'],
                    'name' => $action['name'],
                    'task_type' => TaskType::normalize($action['type']),
                    'type' => TaskType::normalize($action['type']),
                    'priority' => TaskPriority::normalize($action['priority'] ?? null),
                    'reason' => $action['reason'] ?? null,
                    'comment' => $action['comment'] ?? null,
                    'item' => $primaryRequirement['resource_name'] ?? null,
                    'item_quantity' => $primaryRequirement['required_quantity'] ?? null,
                    'weather_context' => $action['weather_context'] ?? null,
                    'inventory_context' => $inventoryContext,
                    'simulated_state' => $action['simulated_state'] ?? null,
                    'workflow_context' => $action['workflow_context'] ?? null,
                    'state' => TaskState::Pending,
                    'status' => TaskState::Pending->value,
                    'task_calendar_id' => $calendar->id,
                    'fk_task_calendar_id' => $calendar->id,
                    'plant_id' => $action['plant_id'] ?? null,
                    'fk_plant_id' => $action['plant_id'] ?? null,
                    'plant_zone_id' => $action['zone_id'] ?? null,
                ]);

                foreach ($action['required_resources'] ?? [] as $requirement) {
                    TaskResourceRequirement::query()->create([
                        'task_id' => $task->id,
                        'resource_name' => $requirement['resource_name'],
                        'normalized_name' => $requirement['normalized_name'],
                        'inventory_item_type' => $requirement['inventory_item_type'],
                        'unit' => $requirement['unit'],
                        'required_quantity' => $requirement['required_quantity'],
                        'shortage_quantity' => $requirement['shortage_quantity'] ?? 0,
                        'is_consumed' => $requirement['is_consumed'],
                    ]);
                }

                if (($action['type'] ?? null) === TaskType::Buy->value && isset($action['buy_action_key'])) {
                    $createdBuyTaskIdsByKey[$action['buy_action_key']] = $task->id;
                }

                if ($pendingBuyKeys !== []) {
                    $taskBuyDependencies[$task->id] = $pendingBuyKeys;
                }
            }

            foreach ($taskBuyDependencies as $taskId => $buyActionKeys) {
                $task = Task::query()->find($taskId);

                if (! $task) {
                    continue;
                }

                $inventoryContext = $task->inventory_context ?? [];
                $buyTaskIds = collect($inventoryContext['buy_task_ids'] ?? [])
                    ->merge(collect($buyActionKeys)->map(fn (string $buyKey) => $createdBuyTaskIdsByKey[$buyKey] ?? null)->filter())
                    ->unique()
                    ->values()
                    ->all();
                $inventoryContext['buy_task_ids'] = $buyTaskIds;
                $inventoryContext['blocked_by_replenishment'] = $buyTaskIds !== [];

                $task->forceFill([
                    'inventory_context' => $inventoryContext,
                ])->saveQuietly();
            }

            $calendar->load([
                'tasks.plant.plantZone',
                'tasks.plantZone',
                'tasks.requiredResources',
                'weatherForecasts',
            ]);

            if ($owner) {
                $dayResourceSummary = $this->inventoryService->attachLiveTaskInventory($owner, $calendar->tasks);
                $calendar->setAttribute('day_resource_summary', $dayResourceSummary);
            }

            return $calendar;
        });
    }

    private function collectPlants(Plot $plot): Collection
    {
        return $plot->plantZones
            ->flatMap(function ($zone) {
                return $zone->plants->map(function (Plant $plant) use ($zone) {
                    $plant->plant_zone_id = $plant->plant_zone_id ?? $zone->id;
                    $plant->setRelation('plantZone', $zone);

                    return $plant;
                });
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $simulatedState
     * @return array<int, array<string, mixed>>
     */
    private function buildBaseActions(Plant $plant, PlantCare $care, array $simulatedState): array
    {
        $daysSincePlanting = (int) $simulatedState['elapsed_days_from_planted'];
        $phase = (string) ($simulatedState['simulated_phase'] ?? '');
        $actualCondition = (string) ($simulatedState['actual_condition'] ?? '');
        $actions = [];

        if ($plant->disease || $actualCondition === ConditionType::Diseased->value) {
            return [[
                'type' => TaskType::Spray->value,
                'name' => "Treat {$plant->name}",
                'priority' => TaskPriority::High->value,
                'reason' => 'The plant is marked as diseased and needs immediate attention.',
                'comment' => 'Disease status overrides the normal phase simulation for treatment.',
                'required_resources' => [
                    $this->resourceRequirement('Fungicide', InventoryItemType::Material, 1, 'l', true),
                    $this->resourceRequirement('Sprayer', InventoryItemType::Tool, 1, 'unit', false),
                ],
            ]];
        }

        if (in_array($phase, [ConditionType::Planted->value, ConditionType::Germinating->value], true)
            && (($simulatedState['is_transition_day'] ?? false) || $daysSincePlanting <= 3)) {
            $actions[] = [
                'type' => TaskType::Rest->value,
                'name' => "Monitor {$plant->name} establishment",
                'priority' => TaskPriority::Low->value,
                'reason' => 'Young plants should be checked closely while they establish.',
                'comment' => 'Observe early growth, moisture balance, and transplant shock.',
            ];
        }

        if ($phase !== '' && $this->isIntervalDue($daysSincePlanting, $care->watering_interval_days, 0)) {
            $actions[] = [
                'type' => TaskType::Watering->value,
                'name' => "Water {$plant->name}",
                'priority' => TaskPriority::Medium->value,
                'reason' => 'The watering interval is due for this simulated day.',
                'comment' => sprintf(
                    'Watering interval aligned with the expected %s phase for this day.',
                    $simulatedState['phase_label'] ?? $phase
                ),
            ];
        }

        if (in_array($phase, [
            ConditionType::Growing->value,
            ConditionType::Flowering->value,
            ConditionType::Mature->value,
            ConditionType::Regenerating->value,
        ], true)
            && $this->isIntervalDue($daysSincePlanting, $care->fertilizing_interval_days, max(1, (int) ($care->germinating_duration_days ?? 0) + 1))) {
            $actions[] = [
                'type' => TaskType::Fertilize->value,
                'name' => "Fertilize {$plant->name}",
                'priority' => TaskPriority::Medium->value,
                'reason' => 'The fertilizing interval is due for the simulated lifecycle phase on this day.',
                'comment' => sprintf(
                    'Fertilize after establishment, using the expected %s phase for scheduling.',
                    $simulatedState['phase_label'] ?? $phase
                ),
                'required_resources' => [
                    $this->resourceRequirement('Fertilizer', InventoryItemType::Material, 1, 'kg', true),
                ],
            ];
        }

        if (in_array($phase, [
            ConditionType::Growing->value,
            ConditionType::Flowering->value,
            ConditionType::Mature->value,
            ConditionType::Regenerating->value,
        ], true)
            && $this->isIntervalDue($daysSincePlanting, $care->pest_check_interval_days, 0)) {
            $actions[] = [
                'type' => TaskType::Spray->value,
                'name' => "Inspect {$plant->name} for pests",
                'priority' => TaskPriority::Medium->value,
                'reason' => 'The pest inspection interval is due for this plant.',
                'comment' => 'Inspect leaves, stems, and surrounding soil for pests or disease.',
            ];
        }

        if (($simulatedState['transition']['to'] ?? null) === ConditionType::Mature->value) {
            $actions[] = [
                'type' => TaskType::Rest->value,
                'name' => "Inspect {$plant->name} support and canopy",
                'priority' => TaskPriority::Low->value,
                'reason' => 'Mature plants benefit from a maintenance check when the mature phase begins.',
                'comment' => 'Check support, spacing, and overall plant structure.',
                'required_resources' => [
                    $this->resourceRequirement('Plant support', InventoryItemType::Tool, 1, 'unit', false),
                ],
            ];
        }

        return $actions;
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array<string, mixed>  $simulatedState
     * @param  array<string, mixed>  $weatherContext
     * @return array<int, array<string, mixed>>
     */
    private function applyWeatherRules(
        array $actions,
        Plant $plant,
        PlantCare $care,
        array $simulatedState,
        WeatherData $weatherData,
        array $weatherContext,
    ): array {
        $rainThreshold = $care->rain_skip_threshold_mm !== null
            ? max(0.1, (float) $care->rain_skip_threshold_mm)
            : null;
        $frostThreshold = $care->frost_temp_threshold_c !== null
            ? (float) $care->frost_temp_threshold_c
            : null;
        $heatThreshold = $care->heat_extra_water_temp_c !== null
            ? (float) $care->heat_extra_water_temp_c
            : null;
        $windThreshold = $care->wind_protection_kmh !== null
            ? (float) $care->wind_protection_kmh
            : null;

        if ($rainThreshold !== null && $weatherData->precipitationMm >= $rainThreshold) {
            $actions = array_values(array_filter(
                $actions,
                fn (array $action) => $action['type'] !== TaskType::Watering->value
            ));
        }

        if ($weatherContext['is_heavy_rain']) {
            $actions = array_values(array_filter(
                $actions,
                fn (array $action) => ! in_array($action['type'], [TaskType::Watering->value, TaskType::Harvest->value], true)
            ));

            $actions[] = [
                'type' => TaskType::Rest->value,
                'name' => "Inspect drainage around {$plant->name}",
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Heavy rain is forecast for this exact day.',
                'comment' => 'Inspect for waterlogging and soil compaction after the rain event.',
            ];
        }

        if (($frostThreshold !== null && $weatherData->tempMin < $frostThreshold) || $weatherContext['is_snow']) {
            $actions = array_values(array_filter(
                $actions,
                fn (array $action) => ! in_array($action['type'], [TaskType::Harvest->value, TaskType::Fertilize->value], true)
            ));

            $actions[] = [
                'type' => TaskType::Rest->value,
                'name' => "Protect {$plant->name} from frost",
                'priority' => TaskPriority::High->value,
                'reason' => $weatherContext['is_snow']
                    ? 'Snow or freezing conditions are forecast for this day.'
                    : 'The minimum temperature is below the configured frost threshold.',
                'comment' => 'Cover the plant or delay exposure until conditions improve.',
                'required_resources' => [
                    $this->resourceRequirement('Protective cover', InventoryItemType::Tool, 1, 'unit', false),
                ],
            ];
        }

        if (
            $heatThreshold !== null
            && $weatherData->tempMax > $heatThreshold
            && ($rainThreshold === null || $weatherData->precipitationMm < $rainThreshold)
        ) {
            $actions[] = [
                'type' => TaskType::Watering->value,
                'name' => "Relieve heat stress for {$plant->name}",
                'priority' => $weatherData->tempMax >= ($heatThreshold + 5)
                    ? TaskPriority::High->value
                    : TaskPriority::Medium->value,
                'reason' => 'High heat is forecast and rainfall is not sufficient to offset stress.',
                'comment' => 'Add water or temporary shade to reduce heat stress.',
            ];
        }

        if ($weatherContext['is_drought']
            && ($simulatedState['simulated_phase'] ?? null) !== ConditionType::Mature->value
            && ! $weatherContext['is_heavy_rain']) {
            $actions[] = [
                'type' => TaskType::Watering->value,
                'name' => "Drought recovery watering for {$plant->name}",
                'priority' => TaskPriority::High->value,
                'reason' => 'Multiple hot and dry forecast days increase drought stress.',
                'comment' => 'Conservative drought support triggered by a rolling dry-weather pattern.',
            ];
        }

        if ($windThreshold !== null && $weatherData->windKmh > $windThreshold) {
            $actions[] = [
                'type' => TaskType::Rest->value,
                'name' => "Secure {$plant->name} against wind",
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Wind speed is above the plant-care protection threshold.',
                'comment' => 'Secure stems, supports, or nearby structures before the wind picks up.',
                'required_resources' => [
                    $this->resourceRequirement('Plant support', InventoryItemType::Tool, 1, 'unit', false),
                ],
            ];
        }

        return $actions;
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateActions(array $actions): array
    {
        $deduplicated = [];

        foreach ($actions as $action) {
            $requirementKey = collect($action['required_resources'] ?? [])
                ->map(function (array $requirement): string {
                    return implode('|', [
                        $requirement['inventory_item_type'] instanceof InventoryItemType
                            ? $requirement['inventory_item_type']->value
                            : $requirement['inventory_item_type'],
                        $requirement['unit'] ?? 'unit',
                        mb_strtolower((string) ($requirement['resource_name'] ?? '')),
                    ]);
                })
                ->sort()
                ->implode(',');

            $key = in_array($action['type'] ?? null, [
                TaskType::Watering->value,
                TaskType::Fertilize->value,
                TaskType::Harvest->value,
                TaskType::Spray->value,
                TaskType::Planting->value,
                TaskType::Transplant->value,
            ], true)
                ? implode('|', [$action['type'] ?? 'rest', $requirementKey])
                : implode('|', [
                    $action['type'] ?? 'rest',
                    mb_strtolower((string) ($action['name'] ?? '')),
                    $requirementKey,
                ]);

            if (! isset($deduplicated[$key])) {
                $deduplicated[$key] = $action;
                continue;
            }

            $deduplicated[$key] = $this->mergeAction($deduplicated[$key], $action);
        }

        return array_values($deduplicated);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeAction(array $existing, array $incoming): array
    {
        $existing['priority'] = $this->higherPriority(
            (string) ($existing['priority'] ?? TaskPriority::Medium->value),
            (string) ($incoming['priority'] ?? TaskPriority::Medium->value),
        );

        $existing['reason'] = $this->mergeText($existing['reason'] ?? null, $incoming['reason'] ?? null, '; ');
        $existing['comment'] = $this->mergeText($existing['comment'] ?? null, $incoming['comment'] ?? null, PHP_EOL);
        $existing['required_resources'] = $this->mergeRequirements(
            $existing['required_resources'] ?? [],
            $incoming['required_resources'] ?? [],
        );
        $existing['workflow_context'] = $existing['workflow_context'] ?? $incoming['workflow_context'] ?? null;

        return $existing;
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $sourceAction
     * @param  array<string, mixed>  $shortageRequirement
     * @return array<string, mixed>
     */
    private function mergeBuyAction(
        ?array $existing,
        array $sourceAction,
        array $shortageRequirement,
        Carbon $generationDate,
        Plant $plant,
    ): array {
        $quantity = (float) ($shortageRequirement['shortage_quantity'] ?? 0);
        $item = (string) ($shortageRequirement['resource_name'] ?? '');
        $itemType = (string) ($shortageRequirement['inventory_item_type'] ?? InventoryItemType::Material->value);
        $unit = (string) ($shortageRequirement['unit'] ?? 'unit');
        $plannedTaskNames = [$sourceAction['name']];
        $plannedPlants = [$plant->name];
        $taskComment = sprintf(
            'Required for %s before the planned work can be completed.',
            $sourceAction['name']
        );

        if ($existing === null) {
            return [
                'date' => $generationDate->toDateString(),
                'type' => TaskType::Buy->value,
                'name' => "Buy {$item}",
                'priority' => TaskPriority::High->value,
                'reason' => "Projected inventory is insufficient for {$sourceAction['name']}.",
                'comment' => $taskComment,
                'plant_id' => null,
                'zone_id' => null,
                'weather_context' => $sourceAction['weather_context'] ?? null,
                'inventory_context' => [
                    'status' => 'purchase_required',
                    'shortage_quantity' => $quantity,
                    'planned_for_tasks' => $plannedTaskNames,
                    'planned_for_plants' => $plannedPlants,
                    'expected_item_type' => $itemType,
                    'unit' => $unit,
                ],
                'simulated_state' => null,
                'required_resources' => [[
                    'resource_name' => $item,
                    'normalized_name' => $shortageRequirement['normalized_name'],
                    'inventory_item_type' => $itemType,
                    'unit' => $unit,
                    'required_quantity' => $quantity,
                    'shortage_quantity' => $quantity,
                    'is_consumed' => false,
                ]],
            ];
        }

        $existing['required_resources'][0]['required_quantity'] = round(
            (float) ($existing['required_resources'][0]['required_quantity'] ?? 0) + $quantity,
            2
        );
        $existing['required_resources'][0]['shortage_quantity'] = $existing['required_resources'][0]['required_quantity'];
        $existing['inventory_context']['shortage_quantity'] = $existing['required_resources'][0]['required_quantity'];
        $plannedForTasks = collect($existing['inventory_context']['planned_for_tasks'] ?? []);
        $shouldAppendComment = ! $plannedForTasks->contains($sourceAction['name']);

        $existing['inventory_context']['planned_for_tasks'] = $plannedForTasks
            ->push($sourceAction['name'])
            ->unique()
            ->values()
            ->all();
        $existing['inventory_context']['planned_for_plants'] = collect($existing['inventory_context']['planned_for_plants'] ?? [])
            ->push($plant->name)
            ->unique()
            ->values()
            ->all();

        if ($shouldAppendComment) {
            $existing['comment'] = $this->mergeText(
                $existing['comment'] ?? null,
                "Also needed for {$sourceAction['name']}.",
                PHP_EOL
            );
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $requirement
     */
    private function buyActionKey(array $requirement): string
    {
        if (isset($requirement['resource_key']) && is_string($requirement['resource_key'])) {
            return $requirement['resource_key'];
        }

        return NormalizedTaskResource::from($requirement)->key();
    }

    private function dailyBuyActionKey(string $date, string $resourceKey): string
    {
        return sprintf('%s|%s', $date, $resourceKey);
    }

    /**
     * @param  array<string, array<string, mixed>>  $weatherByDate
     * @return array<string, mixed>
     */
    private function buildWeatherContext(WeatherData $weatherData, array $weatherByDate, Carbon $generationDate): array
    {
        $recentWindow = collect(range(0, 2))
            ->map(function (int $offset) use ($weatherByDate, $generationDate): array {
                $dateKey = $generationDate->copy()->subDays($offset)->toDateString();

                return $weatherByDate[$dateKey] ?? [];
            })
            ->filter()
            ->map(fn (array $row) => WeatherData::fromArray($row))
            ->values();

        $dryHotWindow = $recentWindow->count() === 3
            && $recentWindow->every(fn (WeatherData $row) => $row->precipitationMm < 1.5 && $row->tempMax >= 24);

        return [
            'date' => $generationDate->toDateString(),
            'temp_min' => $weatherData->tempMin,
            'temp_max' => $weatherData->tempMax,
            'precipitation_mm' => $weatherData->precipitationMm,
            'humidity' => $weatherData->humidity,
            'wind_kmh' => $weatherData->windKmh,
            'condition_code' => $weatherData->conditionCode,
            'is_snow' => $weatherData->isSnowCondition(),
            'is_heavy_rain' => $weatherData->precipitationMm >= 12,
            'is_drought' => $dryHotWindow,
            'is_seasonal_fallback' => $weatherData->isSeasonalFallback,
        ];
    }

    private function hasPreviousZoneRotation(Plant $plant): bool
    {
        $plantZone = $plant->relationLoaded('plantZone') ? $plant->plantZone : null;

        if (! $plantZone) {
            return false;
        }

        $plantZone->loadMissing('rotationHistory');

        return $plantZone->rotationHistory->contains(function ($history) use ($plant) {
            return $history->to_date !== null && $history->to_date->lt($plant->plant_date);
        });
    }

    private function isIntervalDue(int $daysSincePlanting, ?int $interval, int $offset): bool
    {
        if ($interval === null || $interval <= 0 || $daysSincePlanting <= 0 || $daysSincePlanting < $offset) {
            return false;
        }

        return (($daysSincePlanting - $offset) % $interval) === 0;
    }

    private function resolveGardenOwner(Plot $plot): ?GardenOwner
    {
        if ($plot->relationLoaded('gardenOwner') && $plot->gardenOwner) {
            return $plot->gardenOwner;
        }

        return $plot->garden_owner_id
            ? GardenOwner::query()->find($plot->garden_owner_id)
            : null;
    }

    private function higherPriority(string $left, string $right): string
    {
        $rank = [
            TaskPriority::Low->value => 1,
            TaskPriority::Medium->value => 2,
            TaskPriority::High->value => 3,
        ];

        return ($rank[$right] ?? 0) > ($rank[$left] ?? 0) ? $right : $left;
    }

    private function mergeText(?string $left, ?string $right, string $separator): ?string
    {
        $parts = collect([$left, $right])
            ->filter(fn (?string $value) => filled($value))
            ->unique()
            ->values()
            ->all();

        return $parts === [] ? null : implode($separator, $parts);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $actions
     * @return Collection<int, array<string, mixed>>
     */
    private function finalizeActions(Collection $actions): Collection
    {
        return $actions
            ->sortBy([
                ['date', 'asc'],
                [fn (array $action) => $action['plant_id'] ?? PHP_INT_MAX, 'asc'],
                ['type', 'asc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $actions
     * @param  array<string, array<string, mixed>>  $inventoryLedger
     * @param  array<string, Task>  $existingBuyTasks
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, array<string, mixed>>}
     */
    private function applyDayLevelInventoryPlanning(
        Collection $actions,
        array &$inventoryLedger,
        array $existingBuyTasks,
    ): array {
        $plannedActions = collect();
        $buyActions = [];

        foreach ($actions->groupBy('date')->sortKeys() as $date => $dayActions) {
            $datePlan = $this->evaluateDateRequirementsForPlan($inventoryLedger, $dayActions);
            $datePlanByKey = collect($datePlan)->keyBy('resource_key');

            foreach ($dayActions as $action) {
                $inventoryContext = $this->buildActionInventoryContextFromDatePlan($action, $datePlanByKey, $date);
                $pendingBuyKeys = [];
                $openBuyTaskIds = collect($inventoryContext['buy_task_ids'] ?? []);

                foreach ($inventoryContext['missing_resources'] ?? [] as $missingResource) {
                    $existingBuyTask = $existingBuyTasks[$missingResource['resource_key']] ?? null;

                    if ($existingBuyTask) {
                        $openBuyTaskIds->push($existingBuyTask->id);
                        continue;
                    }

                    $buyActionKey = $this->dailyBuyActionKey($date, $missingResource['resource_key']);

                    if (! isset($buyActions[$buyActionKey])) {
                        $buyActions[$buyActionKey] = $this->buildDayBuyAction($date, $missingResource);
                    }

                    $pendingBuyKeys[] = $buyActionKey;
                }

                $inventoryContext['buy_task_ids'] = $openBuyTaskIds->merge($inventoryContext['buy_task_ids'] ?? [])
                    ->unique()
                    ->values()
                    ->all();
                $inventoryContext['pending_buy_keys'] = array_values(array_unique($pendingBuyKeys));
                $action['inventory_context'] = $inventoryContext;
                $action['required_resources'] = collect($action['required_resources'] ?? [])
                    ->map(function (array $requirement) use ($datePlanByKey): array {
                        $resourceKey = $this->buyActionKey($requirement);
                        $resourcePlan = $datePlanByKey->get($resourceKey);
                        $dayShortage = round((float) ($resourcePlan['shortage_quantity'] ?? 0), 2);

                        return array_merge($requirement, [
                            'shortage_quantity' => $dayShortage > 0
                                ? round(min((float) $requirement['required_quantity'], $dayShortage), 2)
                                : 0.0,
                        ]);
                    })
                    ->values()
                    ->all();

                $plannedActions->push($action);
            }
        }

        return [$plannedActions, collect(array_values($buyActions))];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $dayActions
     * @return array<int, array<string, mixed>>
     */
    private function evaluateDateRequirementsForPlan(array &$inventoryLedger, Collection $dayActions): array
    {
        $aggregatedRequirements = [];

        foreach ($dayActions as $action) {
            foreach ($action['required_resources'] ?? [] as $requirement) {
                $resourceKey = $this->buyActionKey($requirement);

                if (! isset($aggregatedRequirements[$resourceKey])) {
                    $aggregatedRequirements[$resourceKey] = [
                        'resource_key' => $resourceKey,
                        'resource_name' => $requirement['resource_name'],
                        'normalized_name' => $requirement['normalized_name'],
                        'inventory_item_type' => $requirement['inventory_item_type'],
                        'unit' => $requirement['unit'],
                        'required_quantity' => 0.0,
                        'resource_mode' => $requirement['resource_mode'] ?? ((bool) $requirement['is_consumed'] ? 'consumable' : 'reusable'),
                        'is_consumed' => (bool) $requirement['is_consumed'],
                        'consumption_mode' => (bool) $requirement['is_consumed'] ? 'consumable' : 'reusable',
                        'task_names' => [],
                        'task_keys' => [],
                    ];
                }

                $aggregatedRequirements[$resourceKey]['required_quantity'] = round(
                    (float) $aggregatedRequirements[$resourceKey]['required_quantity'] + (float) $requirement['required_quantity'],
                    2
                );
                $aggregatedRequirements[$resourceKey]['task_names'][] = $action['name'];
                $aggregatedRequirements[$resourceKey]['task_keys'][] = $action['planning_key'];
            }
        }

        foreach ($aggregatedRequirements as $resourceKey => $requirement) {
            $reservation = $this->inventoryService->reserveRequirementForPlan($inventoryLedger, $requirement);

            $aggregatedRequirements[$resourceKey] = array_merge($requirement, [
                'available_quantity' => round((float) ($reservation['available_before'] ?? 0), 2),
                'reserved_quantity' => round((float) ($reservation['reserved_quantity'] ?? 0), 2),
                'shortage_quantity' => round((float) ($reservation['shortage_quantity'] ?? 0), 2),
                'remaining_after' => round((float) ($reservation['remaining_after'] ?? 0), 2),
                'task_names' => array_values(array_unique($requirement['task_names'])),
                'task_keys' => array_values(array_unique($requirement['task_keys'])),
            ]);
        }

        return collect($aggregatedRequirements)
            ->sortBy('resource_name')
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $datePlanByKey
     * @return array<string, mixed>
     */
    private function buildActionInventoryContextFromDatePlan(
        array $action,
        \Illuminate\Support\Collection $datePlanByKey,
        string $date,
    ): array {
        $requirements = collect($action['required_resources'] ?? [])
            ->map(function (array $requirement) use ($datePlanByKey): array {
                $resourcePlan = $datePlanByKey->get($this->buyActionKey($requirement));
                $dayShortage = round((float) ($resourcePlan['shortage_quantity'] ?? 0), 2);

                return [
                    'resource_key' => $resourcePlan['resource_key'] ?? $this->buyActionKey($requirement),
                    'resource_name' => $requirement['resource_name'],
                    'normalized_name' => $requirement['normalized_name'],
                    'inventory_item_type' => $requirement['inventory_item_type'],
                    'unit' => $requirement['unit'],
                    'resource_mode' => $resourcePlan['resource_mode'] ?? ($requirement['resource_mode'] ?? ((bool) $requirement['is_consumed'] ? 'consumable' : 'reusable')),
                    'required_quantity' => round((float) $requirement['required_quantity'], 2),
                    'available_quantity' => round((float) ($resourcePlan['available_quantity'] ?? 0), 2),
                    'shortage_quantity' => $dayShortage > 0
                        ? round(min((float) $requirement['required_quantity'], $dayShortage), 2)
                        : 0.0,
                    'daily_required_quantity' => round((float) ($resourcePlan['required_quantity'] ?? $requirement['required_quantity']), 2),
                    'daily_shortage_quantity' => $dayShortage,
                    'is_consumed' => (bool) $requirement['is_consumed'],
                    'consumption_mode' => (bool) $requirement['is_consumed'] ? 'consumable' : 'reusable',
                    'buy_task_ids' => [],
                    'task_names' => $resourcePlan['task_names'] ?? [],
                    'task_keys' => $resourcePlan['task_keys'] ?? [],
                    'is_sufficient' => $dayShortage <= 0,
                ];
            })
            ->values();
        $missingResources = $requirements
            ->filter(fn (array $requirement) => (float) ($requirement['daily_shortage_quantity'] ?? 0) > 0)
            ->values();

        if ($requirements->isEmpty()) {
            return [
                'status' => 'not_required',
                'inventory_mode' => 'not_required',
                'is_actionable' => true,
                'shortage_count' => 0,
                'requirements' => [],
                'missing_resources' => [],
                'buy_task_ids' => [],
                'calendar_date' => $date,
            ];
        }

        return [
            'status' => $missingResources->isEmpty() ? 'available' : 'shortage',
            'inventory_mode' => $missingResources->isEmpty() ? 'available' : 'shortage',
            'is_actionable' => $missingResources->isEmpty(),
            'shortage_count' => $missingResources->count(),
            'requirements' => $requirements->all(),
            'missing_resources' => $missingResources->all(),
            'buy_task_ids' => [],
            'calendar_date' => $date,
        ];
    }

    /**
     * @param  array<string, mixed>  $missingResource
     * @return array<string, mixed>
     */
    private function buildDayBuyAction(string $date, array $missingResource): array
    {
        $quantity = round((float) ($missingResource['daily_shortage_quantity'] ?? $missingResource['shortage_quantity'] ?? 0), 2);
        $taskNames = collect($missingResource['task_names'] ?? [])
            ->unique()
            ->values()
            ->all();
        $itemType = (string) ($missingResource['inventory_item_type'] ?? InventoryItemType::Material->value);
        $unit = (string) ($missingResource['unit'] ?? 'unit');
        $buyActionKey = $this->dailyBuyActionKey($date, (string) $missingResource['resource_key']);

        return [
            'planning_key' => sprintf('buy-%s', $buyActionKey),
            'buy_action_key' => $buyActionKey,
            'date' => $date,
            'type' => TaskType::Buy->value,
            'name' => "Buy {$missingResource['resource_name']}",
            'priority' => TaskPriority::High->value,
            'reason' => sprintf(
                'Day-level inventory shortage blocks %d planned task(s) on %s.',
                count($taskNames),
                $date,
            ),
            'comment' => sprintf(
                'Missing %s %s for %d blocked task%s.',
                number_format($quantity, $itemType === InventoryItemType::Tool->value ? 0 : 2, '.', ''),
                $unit,
                count($taskNames),
                count($taskNames) === 1 ? '' : 's',
            ),
            'plant_id' => null,
            'zone_id' => null,
            'weather_context' => null,
            'inventory_context' => [
                'status' => 'replenishment',
                'inventory_mode' => 'replenishment',
                'is_actionable' => true,
                'shortage_count' => 1,
                'shortage_quantity' => $quantity,
                'required_quantity' => round((float) ($missingResource['daily_required_quantity'] ?? $missingResource['required_quantity'] ?? $quantity), 2),
                'available_quantity' => round((float) ($missingResource['available_quantity'] ?? 0), 2),
                'planned_for_tasks' => $taskNames,
                'planned_for_task_count' => count($taskNames),
                'calendar_date' => $date,
                'resource_key' => $missingResource['resource_key'],
                'resource_mode' => $missingResource['resource_mode'] ?? ($missingResource['is_consumed'] ? 'consumable' : 'reusable'),
                'expected_item_type' => $itemType,
                'unit' => $unit,
                'buy_task_ids' => [],
            ],
            'simulated_state' => null,
            'required_resources' => [[
                'resource_name' => $missingResource['resource_name'],
                'normalized_name' => $missingResource['normalized_name'],
                'inventory_item_type' => $itemType,
                'unit' => $unit,
                'required_quantity' => $quantity,
                'shortage_quantity' => $quantity,
                'resource_mode' => $missingResource['resource_mode'] ?? ($missingResource['is_consumed'] ? 'consumable' : 'reusable'),
                'is_consumed' => false,
            ]],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $actions
     * @return Collection<int, array<string, mixed>>
     */
    private function markActionsWithoutInventoryOwner(Collection $actions): Collection
    {
        return $actions->map(function (array $action): array {
            $action['inventory_context'] = ($action['required_resources'] ?? []) === []
                ? ['status' => 'not_required', 'inventory_mode' => 'not_required', 'is_actionable' => true, 'shortage_count' => 0, 'requirements' => [], 'missing_resources' => []]
                : ['status' => 'unknown', 'inventory_mode' => 'shortage', 'is_actionable' => false, 'shortage_count' => count($action['required_resources'] ?? []), 'requirements' => [], 'missing_resources' => $action['required_resources'] ?? []];

            return $action;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $requirements
     * @return array<int, array<string, mixed>>
     */
    private function normalizeActionRequirements(array $requirements): array
    {
        return collect($requirements)
            ->map(function (array $requirement): array {
                $type = $requirement['inventory_item_type'] instanceof InventoryItemType
                    ? $requirement['inventory_item_type']
                    : InventoryItemType::from((string) $requirement['inventory_item_type']);

                return [
                    'resource_name' => (string) $requirement['resource_name'],
                    'normalized_name' => mb_strtolower(trim((string) ($requirement['normalized_name'] ?? $requirement['resource_name']))),
                    'inventory_item_type' => $type->value,
                    'unit' => (string) $requirement['unit'],
                    'required_quantity' => round((float) $requirement['required_quantity'], 2),
                    'shortage_quantity' => round((float) ($requirement['shortage_quantity'] ?? 0), 2),
                    'resource_mode' => NormalizedTaskResource::normalizeResourceMode($requirement),
                    'is_consumed' => (bool) $requirement['is_consumed'],
                ];
            })
            ->filter(fn (array $requirement) => $requirement['required_quantity'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $requirements
     * @return array{requirements: array<int, array<string, mixed>>, shortage_requirements: array<int, array<string, mixed>>, context: array<string, mixed>}
     */
    private function evaluateActionInventory(array &$inventoryLedger, array $requirements): array
    {
        $contexts = [];
        $evaluatedRequirements = [];
        $shortageRequirements = [];

        foreach ($requirements as $requirement) {
            $context = $this->inventoryService->reserveRequirementForPlan($inventoryLedger, $requirement);
            $contexts[] = $context;
            $evaluatedRequirement = array_merge($requirement, [
                'shortage_quantity' => $context['shortage_quantity'],
            ]);
            $evaluatedRequirements[] = $evaluatedRequirement;

            if (($context['shortage_quantity'] ?? 0) > 0) {
                $shortageRequirements[] = $evaluatedRequirement;
            }
        }

        return [
            'requirements' => $evaluatedRequirements,
            'shortage_requirements' => $shortageRequirements,
            'context' => [
                'status' => $shortageRequirements === [] ? 'available' : 'shortage',
                'is_actionable' => $shortageRequirements === [],
                'requirements' => $contexts,
                'shortage_count' => count($shortageRequirements),
                'open_buy_task_ids' => [],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $requirements
     * @return array<string, mixed>|null
     */
    private function primaryRequirement(array $requirements): ?array
    {
        if ($requirements === []) {
            return null;
        }

        $consumable = collect($requirements)->first(fn (array $requirement) => $requirement['is_consumed']);

        return $consumable ?? $requirements[0];
    }

    /**
     * @param  array<int, array<string, mixed>>  $left
     * @param  array<int, array<string, mixed>>  $right
     * @return array<int, array<string, mixed>>
     */
    private function mergeRequirements(array $left, array $right): array
    {
        $merged = [];

        foreach (array_merge($left, $right) as $requirement) {
            $key = implode('|', [
                $requirement['inventory_item_type'],
                $requirement['unit'],
                $requirement['resource_mode'] ?? ((bool) $requirement['is_consumed'] ? 'consumable' : 'reusable'),
                $requirement['normalized_name'],
            ]);

            if (! isset($merged[$key])) {
                $merged[$key] = $requirement;
                continue;
            }

            $merged[$key]['required_quantity'] = max(
                (float) $merged[$key]['required_quantity'],
                (float) $requirement['required_quantity']
            );
            $merged[$key]['shortage_quantity'] = max(
                (float) ($merged[$key]['shortage_quantity'] ?? 0),
                (float) ($requirement['shortage_quantity'] ?? 0)
            );
        }

        return array_values($merged);
    }

    /**
     * @return array<string, Task>
     */
    private function loadOpenBuyTasks(Plot $plot, Carbon $currentRangeStart): array
    {
        return Task::query()
            ->where('task_type', TaskType::Buy->value)
            ->where('state', TaskState::Pending->value)
            ->whereDate('date', '<', $currentRangeStart->toDateString())
            ->whereHas('taskCalendar', function ($query) use ($plot): void {
                $query->where('plot_id', $plot->id);
            })
            ->with('requiredResources')
            ->get()
            ->flatMap(function (Task $task) {
                $resourceKey = data_get($task->inventory_context, 'resource_key');

                if (is_string($resourceKey) && $resourceKey !== '') {
                    return [$resourceKey => $task];
                }

                return $task->requiredResources->mapWithKeys(function (TaskResourceRequirement $requirement) use ($task): array {
                    $baseRequirement = [
                        'inventory_item_type' => $requirement->inventory_item_type?->value ?? $requirement->inventory_item_type,
                        'unit' => $requirement->unit?->value ?? $requirement->unit,
                        'normalized_name' => $requirement->normalized_name,
                        'resource_mode' => data_get($task->inventory_context, 'resource_mode', $requirement->is_consumed ? 'consumable' : 'reusable'),
                        'is_consumed' => $requirement->is_consumed,
                    ];
                    $keys = [$this->buyActionKey($baseRequirement)];
                    $itemType = (string) ($baseRequirement['inventory_item_type'] ?? '');

                    if (! data_get($task->inventory_context, 'resource_mode') && $itemType !== '') {
                        $keys[] = $this->buyActionKey(array_merge($baseRequirement, [
                            'resource_mode' => $itemType === InventoryItemType::Material->value ? 'consumable' : 'reusable',
                            'is_consumed' => $itemType === InventoryItemType::Material->value,
                        ]));
                    }

                    return collect($keys)
                        ->unique()
                        ->mapWithKeys(fn (string $key): array => [$key => $task])
                        ->all();
                });
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceRequirement(
        string $resourceName,
        InventoryItemType $type,
        float $requiredQuantity,
        string $unit,
        bool $isConsumed,
    ): array {
        return [
            'resource_name' => $resourceName,
            'normalized_name' => mb_strtolower(trim($resourceName)),
            'inventory_item_type' => $type,
            'unit' => $unit,
            'required_quantity' => round($requiredQuantity, 2),
            'shortage_quantity' => 0.0,
            'resource_mode' => $isConsumed ? 'consumable' : 'reusable',
            'is_consumed' => $isConsumed,
        ];
    }
}
