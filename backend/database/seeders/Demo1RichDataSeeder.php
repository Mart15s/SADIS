<?php

namespace Database\Seeders;

use App\Enums\ConditionType;
use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Enums\PlantType;
use App\Enums\TaskPriority;
use App\Enums\TaskState;
use App\Enums\TaskType;
use App\Models\CatalogPlant;
use App\Models\GardenOwner;
use App\Models\HasInventory;
use App\Models\HasPlot;
use App\Models\HarvestRecord;
use App\Models\InventoryItem;
use App\Models\InventoryUsageLog;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantConditionHistory;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\RotationHistory;
use App\Models\RotationPlanDraft;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\TaskResourceRequirement;
use App\Models\UsedOn;
use App\Models\User;
use App\Models\WeatherForecast;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Demo1RichDataSeeder extends Seeder
{
    private const EMAIL = 'demo1@gmail.com';

    private const SOURCE = 'demo1-rich-data';

    private const MARKER = '[demo1-rich]';

    private const ROTATION_PLOT_NAME = 'Rotacijos testavimo sklypas';

    private const CALENDAR_PLOT_NAME = 'Kalendoriaus generavimo sklypas';

    private const ROTATION_ZONE_NAMES = [
        'Ankštinių zona',
        'Šakniavaisių zona',
        'Lapinių daržovių zona',
        'Vaisinių daržovių zona',
        'Kopūstinių zona',
        'Žolelių zona',
    ];

    private const CALENDAR_ZONE_NAMES = [
        'Pomidorų lysvė',
        'Agurkų lysvė',
        'Morkų lysvė',
        'Salotų lysvė',
        'Braškių lysvė',
        'Prieskoninių augalų lysvė',
    ];

    /** @var array<string, int> */
    private array $counts = [
        'zones' => 0,
        'plants' => 0,
        'harvests' => 0,
        'condition_histories' => 0,
        'tasks' => 0,
        'inventory_items' => 0,
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            [$owner, $rotationPlot, $calendarPlot] = $this->resolveDemoWorld();

            $rotationPlot->update(['name' => self::ROTATION_PLOT_NAME]);
            $calendarPlot->update(['name' => self::CALENDAR_PLOT_NAME]);

            $rotationZones = $this->renameZones($rotationPlot, self::ROTATION_ZONE_NAMES, 'Rotacijos zona');
            $calendarZones = $this->renameZones($calendarPlot, self::CALENDAR_ZONE_NAMES, 'Kalendoriaus zona');
            $catalog = $this->seedCatalog();
            $plants = $this->seedPlants($rotationPlot, $rotationZones, $calendarPlot, $calendarZones, $catalog);
            $inventory = $this->seedInventory($owner);
            $this->seedRotationData($owner, $rotationPlot, $rotationZones, $plants);

            $calendarData = $this->seedCalendarData($calendarPlot, $calendarZones, $plants, $inventory);

            $this->seedHarvests($owner, $rotationPlot, $calendarPlot, $plants, $calendarData['tasks']);
            $this->seedConditionHistory($plants);
            $this->seedSnapshots($owner, $rotationPlot, $calendarPlot);
        });

        $this->printSummary();
    }

    /**
     * @return array{GardenOwner, Plot, Plot}
     */
    private function resolveDemoWorld(): array
    {
        $user = User::query()->where('email', self::EMAIL)->firstOrFail();
        $owner = GardenOwner::query()
            ->where('user_id', $user->id)
            ->orWhere('id_user', $user->id)
            ->firstOrFail();

        $linkedPlotIds = HasPlot::query()
            ->where('fk_owner_id', $owner->id_user)
            ->where('fk_profile_id', $owner->fk_profile_id)
            ->pluck('fk_plot_id');

        $plots = Plot::query()
            ->where(function ($query) use ($owner, $linkedPlotIds): void {
                $query->where('garden_owner_id', $owner->id);

                if ($linkedPlotIds->isNotEmpty()) {
                    $query->orWhereIn('id', $linkedPlotIds);
                }
            })
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($plots->count() < 2) {
            throw new \RuntimeException('Demo1RichDataSeeder needs two plots for demo1@gmail.com.');
        }

        return [$owner, $plots[0], $plots[1]];
    }

    /**
     * @param  array<int, string>  $preferredNames
     * @return Collection<int, PlantZone>
     */
    private function renameZones(Plot $plot, array $preferredNames, string $fallbackPrefix): Collection
    {
        $zones = PlantZone::query()
            ->where(function ($query) use ($plot): void {
                $query
                    ->where('plot_id', $plot->id)
                    ->orWhere('fk_plot_id', $plot->id);
            })
            ->orderBy('id')
            ->get();

        if ($zones->isEmpty()) {
            throw new \RuntimeException("Plot {$plot->id} has no zones for demo seeding.");
        }

        $zones->each(function (PlantZone $zone, int $index) use ($preferredNames, $fallbackPrefix): void {
            $zone->update([
                'name' => $preferredNames[$index] ?? sprintf('%s %d', $fallbackPrefix, $index + 1),
            ]);

            $this->counts['zones']++;
        });

        return $zones;
    }

    /**
     * @return array<string, CatalogPlant>
     */
    private function seedCatalog(): array
    {
        $catalog = [];

        foreach ($this->catalogDefinitions() as $definition) {
            $care = PlantCare::query()->updateOrCreate(
                ['canonical_name' => $definition['canonical']],
                [
                    'description' => $definition['description'],
                    'conditions' => $definition['conditions'],
                    'growing_duration_days' => $definition['growing'],
                    'germinating_duration_days' => $definition['germinating'],
                    'flowering_duration_days' => $definition['flowering'],
                    'mature_duration_days' => $definition['mature'],
                    'mature_duration_end_days' => $definition['harvest_window'],
                    'mature_end_duration_days' => $definition['harvest_window'],
                    'regenerating_duration_days' => $definition['regenerating'],
                    'reusable' => $definition['reusable'],
                    'plant_name' => $definition['name'],
                    'task_type' => TaskType::Watering->value,
                    'plant_type' => $definition['type'],
                    'condition' => ConditionType::Growing->value,
                    'watering_interval_days' => $definition['watering'],
                    'fertilizing_interval_days' => $definition['fertilizing'],
                    'pest_check_interval_days' => $definition['pest_check'],
                    'rain_skip_threshold_mm' => $definition['rain_skip'],
                    'frost_temp_threshold_c' => $definition['frost'],
                    'heat_extra_water_temp_c' => $definition['heat'],
                    'wind_protection_kmh' => $definition['wind'],
                    'source_provider' => self::SOURCE,
                    'source_quality' => 'demo',
                    'source_common_name' => $definition['name'],
                    'source_scientific_name' => $definition['scientific'],
                    'source_family' => $definition['family'],
                    'source_image_url' => null,
                ],
            );

            $catalog[$definition['key']] = CatalogPlant::query()->updateOrCreate(
                ['canonical_name' => $definition['canonical']],
                [
                    'name' => $definition['name'],
                    'plant_type' => $definition['type'],
                    'fk_plant_care_id' => $care->id,
                    'description' => $definition['description'],
                    'source_provider' => self::SOURCE,
                    'source_quality' => 'demo',
                    'source_scientific_name' => $definition['scientific'],
                    'source_family' => $definition['family'],
                    'source_image_url' => null,
                    'metadata' => [
                        'seeded_by' => self::SOURCE,
                        'demo1_catalog_key' => $definition['key'],
                    ],
                ],
            )->fresh('plantCare');
        }

        return $catalog;
    }

    /**
     * @param  Collection<int, PlantZone>  $rotationZones
     * @param  Collection<int, PlantZone>  $calendarZones
     * @param  array<string, CatalogPlant>  $catalog
     * @return array<string, Plant>
     */
    private function seedPlants(
        Plot $rotationPlot,
        Collection $rotationZones,
        Plot $calendarPlot,
        Collection $calendarZones,
        array $catalog,
    ): array {
        $plants = [];

        foreach ($this->plantDefinitions() as $definition) {
            $plot = $definition['plot'] === 'rotation' ? $rotationPlot : $calendarPlot;
            $zones = $definition['plot'] === 'rotation' ? $rotationZones : $calendarZones;
            $zone = $this->zoneAt($zones, $definition['zone']);
            $catalogPlant = $catalog[$definition['catalog']];
            $care = $catalogPlant->plantCare;

            $plants[$definition['key']] = Plant::query()->updateOrCreate(
                [
                    'fk_plot_id' => $plot->id,
                    'name' => $definition['name'],
                ],
                [
                    'growing_time_days' => $care?->growing_duration_days,
                    'recommended_temperature' => $definition['recommended_temperature'] ?? $this->recommendedTemperature($care),
                    'recommended_humidity' => $definition['recommended_humidity'] ?? 64,
                    'plant_date' => $definition['plant_date'],
                    'disease_notes' => $definition['disease_notes'] ?? null,
                    'disease' => $definition['condition'] === ConditionType::Diseased->value,
                    'rest_time_days' => $care?->regenerating_duration_days ?? 0,
                    'plant_size' => $definition['size'],
                    'photo_url' => null,
                    'reusable' => $care?->reusable ?? false,
                    'type' => $catalogPlant->plant_type?->value ?? $catalogPlant->plant_type,
                    'condition' => $definition['condition'],
                    'fk_catalog_plant_id' => $catalogPlant->id,
                    'plant_zone_id' => $zone->id,
                    'fk_plant_zone_id' => $zone->id,
                ],
            );

            $this->counts['plants']++;
        }

        return $plants;
    }

    /**
     * @return array<string, InventoryItem>
     */
    private function seedInventory(GardenOwner $owner): array
    {
        $inventory = [];

        foreach ($this->inventoryDefinitions() as $definition) {
            $item = InventoryItem::query()->updateOrCreate(
                [
                    'garden_owner_id' => $owner->id,
                    'name' => $definition['name'],
                ],
                [
                    'quantity' => $definition['quantity'],
                    'inventory_item_type' => $definition['type'],
                    'type' => $definition['type'],
                    'unit' => $definition['unit'],
                ],
            );

            HasInventory::query()->firstOrCreate([
                'fk_inventory_item_id' => $item->id,
                'fk_owner_id' => $owner->id_user,
                'fk_profile_id' => $owner->fk_profile_id,
            ]);

            $inventory[$definition['key']] = $item;
            $this->counts['inventory_items']++;
        }

        return $inventory;
    }

    /**
     * @param  Collection<int, PlantZone>  $zones
     * @param  array<string, Plant>  $plants
     */
    private function seedRotationData(GardenOwner $owner, Plot $plot, Collection $zones, array $plants): void
    {
        foreach ($this->rotationHistoryDefinitions() as $definition) {
            $zone = $this->zoneAt($zones, $definition['zone']);
            $plant = $plants[$definition['plant']];

            RotationHistory::query()->updateOrCreate(
                [
                    'fk_plot_id' => $plot->id,
                    'fk_plant_zone_id' => $zone->id,
                    'fk_plant_id' => $plant->id,
                    'from_date' => $definition['from_date'],
                ],
                [
                    'plant_zone_id' => $zone->id,
                    'from_plant_zone_id' => $definition['from_zone'] === null
                        ? null
                        : $this->zoneAt($zones, $definition['from_zone'])->id,
                    'from_zone_name' => $definition['from_zone'] === null
                        ? null
                        : $this->zoneAt($zones, $definition['from_zone'])->name,
                    'to_zone_name' => $zone->name,
                    'decision_status' => $definition['decision_status'],
                    'decision_note' => $definition['note'].' '.self::MARKER,
                    'to_date' => $definition['to_date'],
                    'fk_plot_via_zone' => $plot->id,
                ],
            );
        }

        if (Schema::hasTable('rotation_plan_drafts')) {
            RotationPlanDraft::query()->updateOrCreate(
                [
                    'plot_id' => $plot->id,
                    'garden_owner_id' => $owner->id,
                    'planning_date' => '2026-04-15',
                ],
                [
                    'plan' => [
                        'seeded_by' => self::SOURCE,
                        'label' => '2026 demo rotacijos draft',
                        'warnings' => [
                            [
                                'zone_id' => $this->zoneAt($zones, 0)->id,
                                'message' => 'Pomidorai po bulvių kartoja Solanaceae šeimą per greitai.',
                            ],
                            [
                                'zone_id' => $this->zoneAt($zones, 4)->id,
                                'message' => 'Ridikėliai po kopūstų kartoja Brassicaceae šeimą.',
                            ],
                        ],
                        'recommended_moves' => [
                            [
                                'plant_id' => $plants['rotation_bean_2026']->id,
                                'zone_id' => $this->zoneAt($zones, 0)->id,
                                'reason' => 'Ankštiniai po vaisinių daržovių leidžia testuoti geresnį priešsėlį.',
                            ],
                            [
                                'plant_id' => $plants['rotation_carrot_2026']->id,
                                'zone_id' => $this->zoneAt($zones, 2)->id,
                                'reason' => 'Šakniavaisiai po žirnių padeda palyginti rekomenduojamą rotaciją.',
                            ],
                        ],
                    ],
                ],
            );
        }
    }

    /**
     * @param  Collection<int, PlantZone>  $zones
     * @param  array<string, Plant>  $plants
     * @param  array<string, InventoryItem>  $inventory
     * @return array{calendar: TaskCalendar, tasks: array<string, Task>}
     */
    private function seedCalendarData(Plot $plot, Collection $zones, array $plants, array $inventory): array
    {
        $calendar = TaskCalendar::query()->updateOrCreate(
            [
                'plot_id' => $plot->id,
                'start_date' => '2026-05-18',
                'end_date' => '2026-06-21',
            ],
            [
                'creation_date' => '2026-05-18 07:30:00',
                'fk_plot_id' => $plot->id,
            ],
        );

        $tasks = [];

        foreach ($this->taskDefinitions() as $definition) {
            $plant = $definition['plant'] ? $plants[$definition['plant']] : null;
            $zone = $this->zoneAt($zones, $definition['zone']);
            $primaryResource = $definition['resources'][0] ?? null;
            $resourceItem = $primaryResource ? $inventory[$primaryResource['item']] : null;

            $task = Task::query()->updateOrCreate(
                [
                    'task_calendar_id' => $calendar->id,
                    'date' => $definition['date'],
                    'plant_id' => $plant?->id,
                    'plant_zone_id' => $zone->id,
                    'task_type' => $definition['type'],
                ],
                [
                    'fk_task_calendar_id' => $calendar->id,
                    'fk_plant_id' => $plant?->id,
                    'name' => $definition['name'],
                    'task_type' => $definition['type'],
                    'type' => $definition['type'],
                    'priority' => $definition['priority'],
                    'reason' => $definition['reason'].' '.self::MARKER,
                    'comment' => $definition['comment'],
                    'item' => $resourceItem?->name,
                    'item_quantity' => $primaryResource['quantity'] ?? null,
                    'weather_context' => $definition['weather_context'],
                    'inventory_context' => $primaryResource
                        ? $this->inventoryContext($resourceItem, $primaryResource['quantity'], $definition['type'])
                        : ['status' => 'not_required', 'inventory_mode' => 'not_required', 'is_actionable' => true],
                    'simulated_state' => [
                        'seeded_by' => self::SOURCE,
                        'demo_key' => $definition['key'],
                    ],
                    'workflow_context' => [
                        'seeded_by' => self::SOURCE,
                        'overdue_candidate' => $definition['overdue_candidate'],
                        'demo_group' => 'calendar-generation',
                    ],
                    'state' => $definition['state'],
                    'status' => $definition['state'],
                ],
            );

            UsedOn::query()->firstOrCreate([
                'fk_plant_zone_id' => $zone->id,
                'fk_plot_id' => $plot->id,
                'fk_task_id' => $task->id,
            ]);

            foreach ($definition['resources'] as $resource) {
                $this->seedRequirementAndUsage(
                    $task,
                    $inventory[$resource['item']],
                    $resource['quantity'],
                    $resource['consumed'],
                );
            }

            $tasks[$definition['key']] = $task;
            $this->counts['tasks']++;
        }

        if (Schema::hasTable('weather_forecasts')) {
            $this->seedWeatherForecasts($calendar, $plot);
        }

        return ['calendar' => $calendar, 'tasks' => $tasks];
    }

    /**
     * @param  array<string, Plant>  $plants
     * @param  array<string, Task>  $tasks
     */
    private function seedHarvests(
        GardenOwner $owner,
        Plot $rotationPlot,
        Plot $calendarPlot,
        array $plants,
        array $tasks,
    ): void {
        foreach ($this->harvestDefinitions() as $definition) {
            $plant = $plants[$definition['plant']];
            $plot = $definition['plot'] === 'rotation' ? $rotationPlot : $calendarPlot;
            $task = $definition['task'] ? $tasks[$definition['task']] : null;

            HarvestRecord::query()->updateOrCreate(
                [
                    'plot_id' => $plot->id,
                    'plant_id' => $plant->id,
                    'harvested_on' => $definition['harvested_on'],
                    'notes' => $definition['notes'].' '.self::MARKER,
                ],
                [
                    'task_id' => $task?->id,
                    'garden_owner_id' => $owner->id,
                    'quantity' => $definition['quantity'],
                ],
            );

            $this->counts['harvests']++;
        }
    }

    /**
     * @param  array<string, Plant>  $plants
     */
    private function seedConditionHistory(array $plants): void
    {
        foreach ($this->conditionHistoryDefinitions() as $definition) {
            $plant = $plants[$definition['plant']];

            PlantConditionHistory::query()->updateOrCreate(
                [
                    'plant_id' => $plant->id,
                    'measured_at' => $definition['measured_at'],
                    'notes' => $definition['notes'].' '.self::MARKER,
                ],
                [
                    'fk_plant_id' => $plant->id,
                    'condition' => $definition['condition'],
                    'condition_type' => $definition['condition'],
                    'photo_url' => null,
                ],
            );

            $this->counts['condition_histories']++;
        }
    }

    private function seedSnapshots(GardenOwner $owner, Plot $rotationPlot, Plot $calendarPlot): void
    {
        if (! Schema::hasTable('plot_snapshots')) {
            return;
        }

        $this->upsertSnapshot(
            $owner,
            $rotationPlot,
            'demo1_rotation_snapshot_2025',
            '2025-10-01 09:00:00',
            'Rotacijos istorijos demo pjūvis po 2025 sezono.',
        );
        $this->upsertSnapshot(
            $owner,
            $rotationPlot,
            'demo1_rotation_snapshot_2026_draft',
            '2026-04-15 09:00:00',
            'Rotacijos planavimo demo pjūvis prieš 2026 sodinimus.',
        );
        $this->upsertSnapshot(
            $owner,
            $calendarPlot,
            'demo1_calendar_snapshot_2026',
            '2026-05-18 07:30:00',
            'Kalendoriaus generavimo demo pjūvis su aktyviais augalais.',
        );
    }

    private function upsertSnapshot(
        GardenOwner $owner,
        Plot $plot,
        string $action,
        string $createdAt,
        string $summary,
    ): void {
        $snapshotPlot = Plot::query()
            ->with('plantZones.plants.catalogPlant')
            ->findOrFail($plot->id);

        DB::table('plot_snapshots')->updateOrInsert(
            [
                'plot_id' => $plot->id,
                'action' => $action,
                'created_at' => $createdAt,
            ],
            [
                'garden_owner_id' => $owner->id,
                'snapshot' => json_encode([
                    'seeded_by' => self::SOURCE,
                    'summary' => $summary,
                    'plot' => $snapshotPlot->toArray(),
                    'zones' => $snapshotPlot->plantZones->toArray(),
                ], JSON_THROW_ON_ERROR),
            ],
        );
    }

    private function seedWeatherForecasts(TaskCalendar $calendar, Plot $plot): void
    {
        foreach ($this->weatherDefinitions() as $definition) {
            WeatherForecast::query()->updateOrCreate(
                [
                    'task_calendar_id' => $calendar->id,
                    'date' => $definition['date'],
                ],
                [
                    'fk_task_calendar_id' => $calendar->id,
                    'temperature' => $definition['temperature'],
                    'temp_min' => $definition['temp_min'],
                    'temp_max' => $definition['temp_max'],
                    'precipitation' => $definition['precipitation'],
                    'humidity' => $definition['humidity'],
                    'wind_kmh' => $definition['wind_kmh'],
                    'condition_code' => $definition['condition_code'],
                    'is_seasonal_fallback' => true,
                    'source' => self::SOURCE,
                    'source_date' => $definition['date'],
                    'source_city' => $plot->city,
                    'city' => $plot->city,
                ],
            );
        }
    }

    private function seedRequirementAndUsage(
        Task $task,
        InventoryItem $item,
        float $quantity,
        bool $consumed,
    ): void {
        $shortage = max(0, $quantity - (float) $item->quantity);
        $requirement = TaskResourceRequirement::query()->updateOrCreate(
            [
                'task_id' => $task->id,
                'resource_name' => $item->name,
            ],
            [
                'inventory_item_type' => $item->inventory_item_type?->value ?? $item->inventory_item_type,
                'unit' => $item->unit?->value ?? $item->unit,
                'required_quantity' => $quantity,
                'shortage_quantity' => $shortage,
                'is_consumed' => $consumed,
            ],
        );

        if ($task->state?->value !== TaskState::Completed->value || ! $consumed) {
            return;
        }

        InventoryUsageLog::query()->updateOrCreate(
            [
                'inventory_item_id' => $item->id,
                'task_id' => $task->id,
                'task_resource_requirement_id' => $requirement->id,
                'change_type' => 'consume',
            ],
            [
                'garden_owner_id' => $item->garden_owner_id,
                'quantity_before' => (float) $item->quantity + $quantity,
                'quantity_delta' => -$quantity,
                'quantity_after' => (float) $item->quantity,
                'unit' => $item->unit?->value ?? $item->unit,
                'metadata' => [
                    'seeded_by' => self::SOURCE,
                    'task_name' => $task->name,
                ],
                'created_at' => $task->date?->copy()->setTime(18, 0) ?? now(),
            ],
        );
    }

    private function zoneAt(Collection $zones, int $index): PlantZone
    {
        return $zones->values()->get($index % $zones->count());
    }

    private function recommendedTemperature(?PlantCare $care): float
    {
        if (! $care?->heat_extra_water_temp_c) {
            return 20;
        }

        return max(8, min(24, (float) $care->heat_extra_water_temp_c - 5));
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryContext(?InventoryItem $item, float $quantity, string $taskType): array
    {
        $available = (float) ($item?->quantity ?? 0);
        $shortage = max(0, $quantity - $available);

        return [
            'status' => $shortage > 0 ? 'shortage' : 'available',
            'inventory_mode' => $taskType === TaskType::Buy->value
                ? 'replenishment'
                : ($shortage > 0 ? 'blocked' : 'available'),
            'is_actionable' => $taskType === TaskType::Buy->value || $shortage <= 0,
            'shortage_quantity' => $shortage,
            'unit' => $item?->unit?->value ?? InventoryUnit::Unit->value,
        ];
    }

    private function printSummary(): void
    {
        $this->command?->info('Rotacijos testavimui naudoti sklypą: '.self::ROTATION_PLOT_NAME);
        $this->command?->info('Kalendoriaus generavimo testavimui naudoti sklypą: '.self::CALENDAR_PLOT_NAME);
        $this->command?->line('Pervadinta zonų: '.$this->counts['zones']);
        $this->command?->line('Sukurta/priskirta augalų: '.$this->counts['plants']);
        $this->command?->line('Derliaus įrašų paruošta: '.$this->counts['harvests']);
        $this->command?->line('Būklės istorijų paruošta: '.$this->counts['condition_histories']);
        $this->command?->line('Kalendoriaus užduočių paruošta: '.$this->counts['tasks']);
        $this->command?->line('Inventoriaus įrašų paruošta: '.$this->counts['inventory_items']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogDefinitions(): array
    {
        return [
            ['key' => 'tomato', 'name' => 'Pomidorai', 'canonical' => 'demo1-pomidorai', 'type' => PlantType::Vegetable->value, 'family' => 'Solanaceae', 'scientific' => 'Solanum lycopersicum', 'growing' => 85, 'germinating' => 7, 'flowering' => 28, 'mature' => 70, 'harvest_window' => 40, 'regenerating' => 0, 'reusable' => false, 'watering' => 2, 'fertilizing' => 14, 'pest_check' => 5, 'rain_skip' => 8, 'frost' => 8, 'heat' => 30, 'wind' => 40, 'description' => 'Demo pomidorų priežiūros profilis kalendoriaus ir derliaus testams.', 'conditions' => 'Saulėta vieta, atramos, tolygi drėgmė ir derlinga dirva.'],
            ['key' => 'cucumber', 'name' => 'Agurkai', 'canonical' => 'demo1-agurkai', 'type' => PlantType::Vegetable->value, 'family' => 'Cucurbitaceae', 'scientific' => 'Cucumis sativus', 'growing' => 62, 'germinating' => 5, 'flowering' => 24, 'mature' => 48, 'harvest_window' => 28, 'regenerating' => 0, 'reusable' => false, 'watering' => 2, 'fertilizing' => 14, 'pest_check' => 5, 'rain_skip' => 10, 'frost' => 9, 'heat' => 29, 'wind' => 35, 'description' => 'Demo agurkų profilis gausiam vasaros laistymo testavimui.', 'conditions' => 'Šilta lysvė, atramos, kompostas ir reguliari drėgmė.'],
            ['key' => 'carrot', 'name' => 'Morkos', 'canonical' => 'demo1-morkos', 'type' => PlantType::Vegetable->value, 'family' => 'Apiaceae', 'scientific' => 'Daucus carota', 'growing' => 78, 'germinating' => 14, 'flowering' => 0, 'mature' => 65, 'harvest_window' => 35, 'regenerating' => 0, 'reusable' => false, 'watering' => 4, 'fertilizing' => 21, 'pest_check' => 10, 'rain_skip' => 10, 'frost' => -2, 'heat' => 28, 'wind' => 45, 'description' => 'Demo morkų profilis šakniavaisių rotacijai ir derliui.', 'conditions' => 'Puri akmenų neturinti dirva ir drėgna sėklų vagelė.'],
            ['key' => 'lettuce', 'name' => 'Salotos', 'canonical' => 'demo1-salotos', 'type' => PlantType::Vegetable->value, 'family' => 'Asteraceae', 'scientific' => 'Lactuca sativa', 'growing' => 45, 'germinating' => 5, 'flowering' => 0, 'mature' => 32, 'harvest_window' => 18, 'regenerating' => 0, 'reusable' => false, 'watering' => 2, 'fertilizing' => 14, 'pest_check' => 7, 'rain_skip' => 7, 'frost' => -1, 'heat' => 25, 'wind' => 35, 'description' => 'Demo salotų profilis lapinių daržovių būklės ir derliaus langui.', 'conditions' => 'Vėsesnė drėgna dirva ir dalinis pavėsis karštyje.'],
            ['key' => 'potato', 'name' => 'Bulvės', 'canonical' => 'demo1-bulves', 'type' => PlantType::Vegetable->value, 'family' => 'Solanaceae', 'scientific' => 'Solanum tuberosum', 'growing' => 105, 'germinating' => 14, 'flowering' => 25, 'mature' => 90, 'harvest_window' => 28, 'regenerating' => 0, 'reusable' => false, 'watering' => 4, 'fertilizing' => 21, 'pest_check' => 7, 'rain_skip' => 12, 'frost' => 1, 'heat' => 29, 'wind' => 45, 'description' => 'Demo bulvių profilis Solanaceae rotacijos konfliktui.', 'conditions' => 'Puri dirva, kaupimas ir saikingas laistymas.'],
            ['key' => 'pea', 'name' => 'Žirniai', 'canonical' => 'demo1-zirniai', 'type' => PlantType::Legume->value, 'family' => 'Fabaceae', 'scientific' => 'Pisum sativum', 'growing' => 66, 'germinating' => 8, 'flowering' => 25, 'mature' => 54, 'harvest_window' => 20, 'regenerating' => 0, 'reusable' => false, 'watering' => 4, 'fertilizing' => 28, 'pest_check' => 7, 'rain_skip' => 8, 'frost' => -3, 'heat' => 27, 'wind' => 40, 'description' => 'Demo žirnių profilis ankštinių priešsėlio scenarijams.', 'conditions' => 'Vėsesnis sezonas, atramos ir tolygi drėgmė.'],
            ['key' => 'bean', 'name' => 'Pupelės', 'canonical' => 'demo1-pupeles', 'type' => PlantType::Legume->value, 'family' => 'Fabaceae', 'scientific' => 'Phaseolus vulgaris', 'growing' => 60, 'germinating' => 7, 'flowering' => 22, 'mature' => 49, 'harvest_window' => 25, 'regenerating' => 0, 'reusable' => false, 'watering' => 3, 'fertilizing' => 28, 'pest_check' => 7, 'rain_skip' => 8, 'frost' => 6, 'heat' => 30, 'wind' => 38, 'description' => 'Demo pupelių profilis ankštinių rotacijos zonai.', 'conditions' => 'Šilta dirva, saikingas tręšimas ir reguliarus skynimas.'],
            ['key' => 'onion', 'name' => 'Svogūnai', 'canonical' => 'demo1-svogunai', 'type' => PlantType::Vegetable->value, 'family' => 'Amaryllidaceae', 'scientific' => 'Allium cepa', 'growing' => 110, 'germinating' => 10, 'flowering' => 0, 'mature' => 92, 'harvest_window' => 25, 'regenerating' => 0, 'reusable' => false, 'watering' => 5, 'fertilizing' => 28, 'pest_check' => 10, 'rain_skip' => 9, 'frost' => -3, 'heat' => 29, 'wind' => 45, 'description' => 'Demo svogūnų profilis šakniavaisių ir sandėliavimo derliui.', 'conditions' => 'Atvira vieta, mažai piktžolių ir mažesnė drėgmė bręstant.'],
            ['key' => 'garlic', 'name' => 'Česnakai', 'canonical' => 'demo1-cesnakai', 'type' => PlantType::Vegetable->value, 'family' => 'Amaryllidaceae', 'scientific' => 'Allium sativum', 'growing' => 235, 'germinating' => 14, 'flowering' => 0, 'mature' => 205, 'harvest_window' => 24, 'regenerating' => 0, 'reusable' => false, 'watering' => 7, 'fertilizing' => 30, 'pest_check' => 14, 'rain_skip' => 10, 'frost' => -10, 'heat' => 28, 'wind' => 45, 'description' => 'Demo česnakų profilis ilgam vegetacijos ciklui.', 'conditions' => 'Saulėta vieta, mulčias ir mažiau vandens prieš nuėmimą.'],
            ['key' => 'cabbage', 'name' => 'Kopūstai', 'canonical' => 'demo1-kopustai', 'type' => PlantType::Vegetable->value, 'family' => 'Brassicaceae', 'scientific' => 'Brassica oleracea var. capitata', 'growing' => 95, 'germinating' => 5, 'flowering' => 0, 'mature' => 80, 'harvest_window' => 30, 'regenerating' => 0, 'reusable' => false, 'watering' => 3, 'fertilizing' => 18, 'pest_check' => 5, 'rain_skip' => 10, 'frost' => -4, 'heat' => 27, 'wind' => 45, 'description' => 'Demo kopūstų profilis Brassicaceae rotacijos perspėjimams.', 'conditions' => 'Derlinga dirva, vienoda drėgmė ir kenkėjų stebėjimas.'],
            ['key' => 'strawberry', 'name' => 'Braškės', 'canonical' => 'demo1-braskes', 'type' => PlantType::Berry->value, 'family' => 'Rosaceae', 'scientific' => 'Fragaria x ananassa', 'growing' => 120, 'germinating' => 21, 'flowering' => 25, 'mature' => 88, 'harvest_window' => 35, 'regenerating' => 30, 'reusable' => true, 'watering' => 3, 'fertilizing' => 21, 'pest_check' => 7, 'rain_skip' => 10, 'frost' => -5, 'heat' => 29, 'wind' => 40, 'description' => 'Demo braškių profilis daugiamečiam uogų derliui.', 'conditions' => 'Mulčiuota lysvė, švarūs vaisiai ir tolygi drėgmė.'],
            ['key' => 'basil', 'name' => 'Bazilikas', 'canonical' => 'demo1-bazilikas', 'type' => PlantType::Herb->value, 'family' => 'Lamiaceae', 'scientific' => 'Ocimum basilicum', 'growing' => 52, 'germinating' => 7, 'flowering' => 35, 'mature' => 35, 'harvest_window' => 24, 'regenerating' => 0, 'reusable' => false, 'watering' => 3, 'fertilizing' => 21, 'pest_check' => 7, 'rain_skip' => 6, 'frost' => 10, 'heat' => 29, 'wind' => 30, 'description' => 'Demo baziliko profilis prieskoninių augalų lysvei.', 'conditions' => 'Šilta vieta, žiedų skabymas ir reguliarus lapų skynimas.'],
            ['key' => 'parsley', 'name' => 'Petražolės', 'canonical' => 'demo1-petrazoles', 'type' => PlantType::Herb->value, 'family' => 'Apiaceae', 'scientific' => 'Petroselinum crispum', 'growing' => 90, 'germinating' => 21, 'flowering' => 0, 'mature' => 70, 'harvest_window' => 40, 'regenerating' => 21, 'reusable' => true, 'watering' => 4, 'fertilizing' => 21, 'pest_check' => 7, 'rain_skip' => 7, 'frost' => -4, 'heat' => 29, 'wind' => 35, 'description' => 'Demo petražolių profilis pakartotiniam žalumynų skynimui.', 'conditions' => 'Drėgnesnė kompostinga dirva ir išorinių stiebų skynimas.'],
            ['key' => 'dill', 'name' => 'Krapai', 'canonical' => 'demo1-krapai', 'type' => PlantType::Herb->value, 'family' => 'Apiaceae', 'scientific' => 'Anethum graveolens', 'growing' => 55, 'germinating' => 10, 'flowering' => 38, 'mature' => 44, 'harvest_window' => 18, 'regenerating' => 0, 'reusable' => false, 'watering' => 4, 'fertilizing' => 21, 'pest_check' => 7, 'rain_skip' => 7, 'frost' => 1, 'heat' => 28, 'wind' => 35, 'description' => 'Demo krapų profilis prieskoniams ir palydoviniams sodinimams.', 'conditions' => 'Atvira šviesi vieta ir saikingas tręšimas.'],
            ['key' => 'pepper', 'name' => 'Paprikos', 'canonical' => 'demo1-paprikos', 'type' => PlantType::Vegetable->value, 'family' => 'Solanaceae', 'scientific' => 'Capsicum annuum', 'growing' => 88, 'germinating' => 9, 'flowering' => 30, 'mature' => 74, 'harvest_window' => 36, 'regenerating' => 0, 'reusable' => false, 'watering' => 3, 'fertilizing' => 18, 'pest_check' => 7, 'rain_skip' => 8, 'frost' => 10, 'heat' => 31, 'wind' => 36, 'description' => 'Demo paprikų profilis vaisinių daržovių rotacijai.', 'conditions' => 'Šilta užuovėja, tolygi drėgmė ir subalansuotas tręšimas.'],
            ['key' => 'radish', 'name' => 'Ridikėliai', 'canonical' => 'demo1-ridikeliai', 'type' => PlantType::Vegetable->value, 'family' => 'Brassicaceae', 'scientific' => 'Raphanus sativus', 'growing' => 32, 'germinating' => 4, 'flowering' => 0, 'mature' => 24, 'harvest_window' => 12, 'regenerating' => 0, 'reusable' => false, 'watering' => 3, 'fertilizing' => 14, 'pest_check' => 7, 'rain_skip' => 7, 'frost' => -2, 'heat' => 27, 'wind' => 35, 'description' => 'Demo ridikėlių profilis greitam Brassicaceae ciklui.', 'conditions' => 'Vėsesnis sezonas ir pastovi drėgmė šaknims.'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plantDefinitions(): array
    {
        return [
            ['key' => 'rotation_pea_2023', 'plot' => 'rotation', 'zone' => 0, 'catalog' => 'pea', 'name' => 'Žirniai 2023 priešsėlis', 'plant_date' => '2023-04-18', 'condition' => ConditionType::Dried->value, 'size' => 2.2],
            ['key' => 'rotation_potato_2024', 'plot' => 'rotation', 'zone' => 0, 'catalog' => 'potato', 'name' => 'Bulvės 2024 rotacijos istorija', 'plant_date' => '2024-04-20', 'condition' => ConditionType::Dried->value, 'size' => 3.8],
            ['key' => 'rotation_tomato_2025', 'plot' => 'rotation', 'zone' => 0, 'catalog' => 'tomato', 'name' => 'Pomidorai 2025 konfliktui', 'plant_date' => '2025-05-10', 'condition' => ConditionType::Dried->value, 'size' => 3.4],
            ['key' => 'rotation_bean_2026', 'plot' => 'rotation', 'zone' => 0, 'catalog' => 'bean', 'name' => 'Pupelės 2026 siūlomai rotacijai', 'plant_date' => '2026-05-15', 'condition' => ConditionType::Growing->value, 'size' => 1.9],
            ['key' => 'rotation_carrot_2023', 'plot' => 'rotation', 'zone' => 1, 'catalog' => 'carrot', 'name' => 'Morkos 2023 istorija', 'plant_date' => '2023-04-22', 'condition' => ConditionType::Dried->value, 'size' => 2.1],
            ['key' => 'rotation_onion_2024', 'plot' => 'rotation', 'zone' => 1, 'catalog' => 'onion', 'name' => 'Svogūnai 2024 istorija', 'plant_date' => '2024-04-14', 'condition' => ConditionType::Dried->value, 'size' => 2.0],
            ['key' => 'rotation_cabbage_2025', 'plot' => 'rotation', 'zone' => 1, 'catalog' => 'cabbage', 'name' => 'Kopūstai 2025 po šakniavaisių', 'plant_date' => '2025-04-28', 'condition' => ConditionType::Dried->value, 'size' => 3.0],
            ['key' => 'rotation_pepper_2026', 'plot' => 'rotation', 'zone' => 1, 'catalog' => 'pepper', 'name' => 'Paprikos 2026 palyginimui', 'plant_date' => '2026-05-12', 'condition' => ConditionType::Growing->value, 'size' => 2.3],
            ['key' => 'rotation_lettuce_2023', 'plot' => 'rotation', 'zone' => 2, 'catalog' => 'lettuce', 'name' => 'Salotos 2023 istorija', 'plant_date' => '2023-04-12', 'condition' => ConditionType::Dried->value, 'size' => 1.2],
            ['key' => 'rotation_pea_2024', 'plot' => 'rotation', 'zone' => 2, 'catalog' => 'pea', 'name' => 'Žirniai 2024 geram priešsėliui', 'plant_date' => '2024-04-09', 'condition' => ConditionType::Dried->value, 'size' => 2.2],
            ['key' => 'rotation_carrot_2025', 'plot' => 'rotation', 'zone' => 2, 'catalog' => 'carrot', 'name' => 'Morkos 2025 po ankštinių', 'plant_date' => '2025-04-16', 'condition' => ConditionType::Dried->value, 'size' => 2.0],
            ['key' => 'rotation_carrot_2026', 'plot' => 'rotation', 'zone' => 2, 'catalog' => 'carrot', 'name' => 'Morkos 2026 planuojamam palyginimui', 'plant_date' => '2026-04-20', 'condition' => ConditionType::Growing->value, 'size' => 1.5],
            ['key' => 'rotation_basil_2026', 'plot' => 'rotation', 'zone' => 3, 'catalog' => 'basil', 'name' => 'Bazilikas 2026 vaisinių daržovių zonoje', 'plant_date' => '2026-05-18', 'condition' => ConditionType::Growing->value, 'size' => 0.8],
            ['key' => 'rotation_cabbage_2024', 'plot' => 'rotation', 'zone' => 4, 'catalog' => 'cabbage', 'name' => 'Kopūstai 2024 kopūstinių istorija', 'plant_date' => '2024-04-26', 'condition' => ConditionType::Dried->value, 'size' => 3.0],
            ['key' => 'rotation_radish_2025', 'plot' => 'rotation', 'zone' => 4, 'catalog' => 'radish', 'name' => 'Ridikėliai 2025 rotacijos įspėjimui', 'plant_date' => '2025-04-04', 'condition' => ConditionType::Dried->value, 'size' => 1.0],
            ['key' => 'rotation_dill_2026', 'plot' => 'rotation', 'zone' => 5, 'catalog' => 'dill', 'name' => 'Krapai 2026 žolelių zonoje', 'plant_date' => '2026-04-28', 'condition' => ConditionType::Growing->value, 'size' => 0.9],
            ['key' => 'calendar_tomato_2026', 'plot' => 'calendar', 'zone' => 0, 'catalog' => 'tomato', 'name' => 'Pomidorai Vilma 2026', 'plant_date' => '2026-04-08', 'condition' => ConditionType::Flowering->value, 'size' => 3.1],
            ['key' => 'calendar_basil_2026', 'plot' => 'calendar', 'zone' => 0, 'catalog' => 'basil', 'name' => 'Bazilikas Genovese 2026', 'plant_date' => '2026-04-25', 'condition' => ConditionType::Growing->value, 'size' => 0.9],
            ['key' => 'calendar_cucumber_2026', 'plot' => 'calendar', 'zone' => 1, 'catalog' => 'cucumber', 'name' => 'Agurkai Marketmore 2026', 'plant_date' => '2026-04-28', 'condition' => ConditionType::Growing->value, 'size' => 2.4],
            ['key' => 'calendar_carrot_2026', 'plot' => 'calendar', 'zone' => 2, 'catalog' => 'carrot', 'name' => 'Morkos Nantes 2026', 'plant_date' => '2026-03-31', 'condition' => ConditionType::Growing->value, 'size' => 1.4],
            ['key' => 'calendar_radish_2026', 'plot' => 'calendar', 'zone' => 2, 'catalog' => 'radish', 'name' => 'Ridikėliai ankstyvam derliui 2026', 'plant_date' => '2026-04-18', 'condition' => ConditionType::Mature->value, 'size' => 0.8],
            ['key' => 'calendar_lettuce_2026', 'plot' => 'calendar', 'zone' => 3, 'catalog' => 'lettuce', 'name' => 'Salotos Little Gem 2026', 'plant_date' => '2026-04-15', 'condition' => ConditionType::Mature->value, 'size' => 1.1],
            ['key' => 'calendar_strawberry_2026', 'plot' => 'calendar', 'zone' => 4, 'catalog' => 'strawberry', 'name' => 'Braškės Honeoye 2026', 'plant_date' => '2025-08-20', 'condition' => ConditionType::Flowering->value, 'size' => 2.0],
            ['key' => 'calendar_parsley_2026', 'plot' => 'calendar', 'zone' => 5, 'catalog' => 'parsley', 'name' => 'Petražolės lapiniam skynimui 2026', 'plant_date' => '2026-03-28', 'condition' => ConditionType::Growing->value, 'size' => 1.0],
            ['key' => 'calendar_dill_2026', 'plot' => 'calendar', 'zone' => 5, 'catalog' => 'dill', 'name' => 'Krapai birželio skynimui 2026', 'plant_date' => '2026-04-30', 'condition' => ConditionType::Germinating->value, 'size' => 0.6],
            ['key' => 'calendar_tomato_2024', 'plot' => 'calendar', 'zone' => 0, 'catalog' => 'tomato', 'name' => 'Pomidorai 2024 derliaus istorijai', 'plant_date' => '2024-04-15', 'condition' => ConditionType::Dried->value, 'size' => 3.2],
            ['key' => 'calendar_cucumber_2024', 'plot' => 'calendar', 'zone' => 1, 'catalog' => 'cucumber', 'name' => 'Agurkai 2024 derliaus istorijai', 'plant_date' => '2024-05-02', 'condition' => ConditionType::Dried->value, 'size' => 2.5],
            ['key' => 'calendar_carrot_2024', 'plot' => 'calendar', 'zone' => 2, 'catalog' => 'carrot', 'name' => 'Morkos 2024 derliaus istorijai', 'plant_date' => '2024-04-03', 'condition' => ConditionType::Dried->value, 'size' => 1.8],
            ['key' => 'calendar_lettuce_2024', 'plot' => 'calendar', 'zone' => 3, 'catalog' => 'lettuce', 'name' => 'Salotos 2024 derliaus istorijai', 'plant_date' => '2024-04-20', 'condition' => ConditionType::Dried->value, 'size' => 1.0],
            ['key' => 'calendar_strawberry_2024', 'plot' => 'calendar', 'zone' => 4, 'catalog' => 'strawberry', 'name' => 'Braškės 2024 derliaus istorijai', 'plant_date' => '2023-08-18', 'condition' => ConditionType::Regenerating->value, 'size' => 1.9],
            ['key' => 'calendar_tomato_2025', 'plot' => 'calendar', 'zone' => 0, 'catalog' => 'tomato', 'name' => 'Pomidorai 2025 derliaus istorijai', 'plant_date' => '2025-04-17', 'condition' => ConditionType::Dried->value, 'size' => 3.3],
            ['key' => 'calendar_cucumber_2025', 'plot' => 'calendar', 'zone' => 1, 'catalog' => 'cucumber', 'name' => 'Agurkai 2025 derliaus istorijai', 'plant_date' => '2025-05-03', 'condition' => ConditionType::Dried->value, 'size' => 2.6],
            ['key' => 'calendar_carrot_2025', 'plot' => 'calendar', 'zone' => 2, 'catalog' => 'carrot', 'name' => 'Morkos 2025 derliaus istorijai', 'plant_date' => '2025-04-01', 'condition' => ConditionType::Dried->value, 'size' => 1.9],
            ['key' => 'calendar_lettuce_2025', 'plot' => 'calendar', 'zone' => 3, 'catalog' => 'lettuce', 'name' => 'Salotos 2025 derliaus istorijai', 'plant_date' => '2025-04-16', 'condition' => ConditionType::Dried->value, 'size' => 1.1],
            ['key' => 'calendar_strawberry_2025', 'plot' => 'calendar', 'zone' => 4, 'catalog' => 'strawberry', 'name' => 'Braškės 2025 derliaus istorijai', 'plant_date' => '2024-08-21', 'condition' => ConditionType::Regenerating->value, 'size' => 2.1],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inventoryDefinitions(): array
    {
        return [
            ['key' => 'compost', 'name' => 'Kompostas', 'quantity' => 120, 'type' => InventoryItemType::Material->value, 'unit' => InventoryUnit::Kilogram->value],
            ['key' => 'fertilizer', 'name' => 'Organinės trąšos', 'quantity' => 18, 'type' => InventoryItemType::Material->value, 'unit' => InventoryUnit::Kilogram->value],
            ['key' => 'mulch', 'name' => 'Mulčias', 'quantity' => 7, 'type' => InventoryItemType::Material->value, 'unit' => InventoryUnit::Bag->value],
            ['key' => 'supports', 'name' => 'Augalų atramos', 'quantity' => 14, 'type' => InventoryItemType::Tool->value, 'unit' => InventoryUnit::Unit->value],
            ['key' => 'hose', 'name' => 'Laistymo žarna', 'quantity' => 1, 'type' => InventoryItemType::Tool->value, 'unit' => InventoryUnit::Unit->value],
            ['key' => 'seeds', 'name' => 'Sėklos', 'quantity' => 16, 'type' => InventoryItemType::Material->value, 'unit' => InventoryUnit::Pack->value],
            ['key' => 'trays', 'name' => 'Daigyklos', 'quantity' => 8, 'type' => InventoryItemType::Tool->value, 'unit' => InventoryUnit::Unit->value],
            ['key' => 'protection', 'name' => 'Augalų apsaugos priemonė', 'quantity' => 1.5, 'type' => InventoryItemType::Material->value, 'unit' => InventoryUnit::Liter->value],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rotationHistoryDefinitions(): array
    {
        return [
            ['plant' => 'rotation_pea_2023', 'zone' => 0, 'from_zone' => null, 'from_date' => '2023-04-18', 'to_date' => '2023-08-15', 'decision_status' => 'recorded', 'note' => '2023 ankštinių sezonas dirvos azoto atkūrimui.'],
            ['plant' => 'rotation_potato_2024', 'zone' => 0, 'from_zone' => 0, 'from_date' => '2024-04-20', 'to_date' => '2024-09-08', 'decision_status' => 'recorded', 'note' => '2024 bulvių sezonas prieš vėlesnį Solanaceae konfliktą.'],
            ['plant' => 'rotation_tomato_2025', 'zone' => 0, 'from_zone' => 0, 'from_date' => '2025-05-10', 'to_date' => '2025-09-21', 'decision_status' => 'manual_override', 'note' => 'Pomidorai po bulvių palikti rotacijos perspėjimo testui.'],
            ['plant' => 'rotation_carrot_2023', 'zone' => 1, 'from_zone' => null, 'from_date' => '2023-04-22', 'to_date' => '2023-09-02', 'decision_status' => 'recorded', 'note' => 'Šakniavaisių bazinis sezonas.'],
            ['plant' => 'rotation_onion_2024', 'zone' => 1, 'from_zone' => 1, 'from_date' => '2024-04-14', 'to_date' => '2024-08-31', 'decision_status' => 'recorded', 'note' => 'Svogūnų sezonas prieš kopūstinius.'],
            ['plant' => 'rotation_cabbage_2025', 'zone' => 1, 'from_zone' => 1, 'from_date' => '2025-04-28', 'to_date' => '2025-10-01', 'decision_status' => 'generated', 'note' => 'Kopūstai po svogūnų neutraliai rotacijos analizei.'],
            ['plant' => 'rotation_lettuce_2023', 'zone' => 2, 'from_zone' => null, 'from_date' => '2023-04-12', 'to_date' => '2023-06-24', 'decision_status' => 'recorded', 'note' => 'Lapinių daržovių ankstyvas sezonas.'],
            ['plant' => 'rotation_pea_2024', 'zone' => 2, 'from_zone' => 2, 'from_date' => '2024-04-09', 'to_date' => '2024-07-28', 'decision_status' => 'recorded', 'note' => 'Žirniai palikti geram priešsėliui testuoti.'],
            ['plant' => 'rotation_carrot_2025', 'zone' => 2, 'from_zone' => 2, 'from_date' => '2025-04-16', 'to_date' => '2025-09-05', 'decision_status' => 'generated', 'note' => 'Morkos po ankštinių geresnės rotacijos pavyzdžiui.'],
            ['plant' => 'rotation_cabbage_2024', 'zone' => 4, 'from_zone' => null, 'from_date' => '2024-04-26', 'to_date' => '2024-09-18', 'decision_status' => 'recorded', 'note' => 'Kopūstinių istorinis sezonas.'],
            ['plant' => 'rotation_radish_2025', 'zone' => 4, 'from_zone' => 4, 'from_date' => '2025-04-04', 'to_date' => '2025-05-19', 'decision_status' => 'manual_override', 'note' => 'Ridikėliai po kopūstų kartoja Brassicaceae šeimą perspėjimui.'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function taskDefinitions(): array
    {
        return [
            [
                'key' => 'tomato_fertilize_done',
                'date' => '2026-05-18',
                'name' => 'Patręšti žydinčius pomidorus',
                'plant' => 'calendar_tomato_2026',
                'zone' => 0,
                'type' => TaskType::Fertilize->value,
                'state' => TaskState::Completed->value,
                'priority' => TaskPriority::High->value,
                'reason' => 'Pomidorų tręšimo intervalas sutampa su žydėjimo etapu.',
                'comment' => 'Po tręšimo palaistyti prie šaknų ir nešlapinti lapų.',
                'weather_context' => ['rule' => 'normal', 'message' => 'Vėsus rytas tinkamas tręšimui.'],
                'resources' => [['item' => 'fertilizer', 'quantity' => 1.2, 'consumed' => true]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'strawberry_mulch_done',
                'date' => '2026-05-19',
                'name' => 'Papildyti braškių mulčią',
                'plant' => 'calendar_strawberry_2026',
                'zone' => 4,
                'type' => TaskType::Rest->value,
                'state' => TaskState::Completed->value,
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Braškių vaisiams reikia švaraus ir drėgmę saugančio paviršiaus.',
                'comment' => 'Palikti laisvą augalo skrotelės centrą.',
                'weather_context' => ['rule' => 'rain_followup', 'message' => 'Po lietaus plonas mulčio sluoksnis nusėdo.'],
                'resources' => [['item' => 'mulch', 'quantity' => 1.5, 'consumed' => true]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'tomato_support_done',
                'date' => '2026-05-20',
                'name' => 'Pritvirtinti pomidorus prie atramų',
                'plant' => 'calendar_tomato_2026',
                'zone' => 0,
                'type' => TaskType::Transplant->value,
                'state' => TaskState::Completed->value,
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Aukštesni stiebai prieš vėją turi būti prilaikomi.',
                'comment' => 'Patikrinti, kad rišimas neveržtų stiebo.',
                'weather_context' => ['rule' => 'wind_protection', 'message' => 'Artėja stipresnio vėjo prognozė.'],
                'resources' => [['item' => 'supports', 'quantity' => 4, 'consumed' => false]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'carrot_weeding_overdue',
                'date' => '2026-05-19',
                'name' => 'Išravėti morkų vageles',
                'plant' => 'calendar_carrot_2026',
                'zone' => 2,
                'type' => TaskType::Rest->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Morkų daigai lėti, todėl ravėjimo darbas matomas kaip praleistas laiku.',
                'comment' => 'Ravėti sekliai, kad nepažeistų jaunų šaknų.',
                'weather_context' => ['rule' => 'normal', 'message' => 'Dirva pakankamai drėgna lengvam ravėjimui.'],
                'resources' => [],
                'overdue_candidate' => true,
            ],
            [
                'key' => 'lettuce_check_overdue',
                'date' => '2026-05-20',
                'name' => 'Patikrinti salotų būklę ir amarus',
                'plant' => 'calendar_lettuce_2026',
                'zone' => 3,
                'type' => TaskType::Spray->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::High->value,
                'reason' => 'Pest check intervalas baigėsi prieš derliaus skynimą.',
                'comment' => 'Pirmiausia apžiūrėti lapų apačią ir įrašyti būklę.',
                'weather_context' => ['rule' => 'normal', 'message' => 'Prognozė leidžia atlikti greitą apžiūrą.'],
                'resources' => [['item' => 'protection', 'quantity' => 0.15, 'consumed' => true]],
                'overdue_candidate' => true,
            ],
            [
                'key' => 'cucumber_water_today',
                'date' => '2026-05-21',
                'name' => 'Palaistyti agurkų lysvę',
                'plant' => 'calendar_cucumber_2026',
                'zone' => 1,
                'type' => TaskType::Watering->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::High->value,
                'reason' => 'Agurkų laistymo intervalas trumpas ir daigai aktyviai auga.',
                'comment' => 'Laistyti ryte ties dirvos paviršiumi.',
                'weather_context' => ['rule' => 'heat_watch', 'message' => 'Dieną prognozuojamas šiltesnis oras.'],
                'resources' => [['item' => 'hose', 'quantity' => 1, 'consumed' => false]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'tomato_condition_today',
                'date' => '2026-05-21',
                'name' => 'Įvertinti pomidorų lapų būklę',
                'plant' => 'calendar_tomato_2026',
                'zone' => 0,
                'type' => TaskType::Spray->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Po drėgnesnių naktų reikia būklės patikros.',
                'comment' => 'Jei matyti dėmių, būklės istorijoje pažymėti ligos požymius.',
                'weather_context' => ['rule' => 'humidity_watch', 'message' => 'Nakties drėgmė buvo padidėjusi.'],
                'resources' => [],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'radish_harvest',
                'date' => '2026-05-23',
                'name' => 'Nuimti ankstyvų ridikėlių derlių',
                'plant' => 'calendar_radish_2026',
                'zone' => 2,
                'type' => TaskType::Harvest->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::High->value,
                'reason' => 'Ridikėliai pasiekė brandos langą.',
                'comment' => 'Po nuėmimo pažymėti kilogramus derliaus žurnale.',
                'weather_context' => ['rule' => 'normal', 'message' => 'Derlių patogu nuimti prieš šiltesnes dienas.'],
                'resources' => [],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'lettuce_rain_skip',
                'date' => '2026-05-24',
                'name' => 'Atšaukti salotų laistymą po lietaus',
                'plant' => 'calendar_lettuce_2026',
                'zone' => 3,
                'type' => TaskType::Watering->value,
                'state' => TaskState::Canceled->value,
                'priority' => TaskPriority::Low->value,
                'reason' => 'Kritulių kiekis viršijo salotų rain skip slenkstį.',
                'comment' => 'Palikti tik vizualią dirvos drėgmės patikrą.',
                'weather_context' => ['rule' => 'rain_skip', 'message' => 'Prognozuojama daugiau nei 10 mm kritulių.'],
                'resources' => [['item' => 'hose', 'quantity' => 1, 'consumed' => false]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'cucumber_water_weekend',
                'date' => '2026-05-25',
                'name' => 'Pakartotinai palaistyti agurkus',
                'plant' => 'calendar_cucumber_2026',
                'zone' => 1,
                'type' => TaskType::Watering->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Po dviejų dienų reikia patikrinti agurkų drėgmę.',
                'comment' => 'Jei lietaus pakako, užduotį galima atšaukti.',
                'weather_context' => ['rule' => 'normal', 'message' => 'Kritulių suma sumažėja.'],
                'resources' => [['item' => 'hose', 'quantity' => 1, 'consumed' => false]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'buy_compost',
                'date' => '2026-05-26',
                'name' => 'Papildyti komposto atsargas',
                'plant' => null,
                'zone' => 0,
                'type' => TaskType::Buy->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::High->value,
                'reason' => 'Būsimi lysvių papildymo darbai viršija norimą komposto rezervą.',
                'comment' => 'Papildymas reikalingas prieš birželio tręšimo bangą.',
                'weather_context' => ['rule' => 'inventory', 'message' => 'Pirkimo užduotis nepriklauso nuo orų.'],
                'resources' => [['item' => 'compost', 'quantity' => 160, 'consumed' => false]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'tomato_protection',
                'date' => '2026-05-27',
                'name' => 'Patikrinti pomidorus dėl ligų požymių',
                'plant' => 'calendar_tomato_2026',
                'zone' => 0,
                'type' => TaskType::Spray->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::High->value,
                'reason' => 'Pest check intervalas ir drėgmės fonas kelia riziką.',
                'comment' => 'Priemonę naudoti tik jei patikra patvirtins poreikį.',
                'weather_context' => ['rule' => 'humidity_watch', 'message' => 'Drėgnas periodas tęsiasi.'],
                'resources' => [['item' => 'protection', 'quantity' => 0.25, 'consumed' => true]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'lettuce_harvest',
                'date' => '2026-05-29',
                'name' => 'Nuskinti salotų išorinius lapus',
                'plant' => 'calendar_lettuce_2026',
                'zone' => 3,
                'type' => TaskType::Harvest->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Salotos pažymėtos kaip pasiruošusios derliui.',
                'comment' => 'Palikti vidinę skrotelę tolimesniam augimui.',
                'weather_context' => ['rule' => 'heat_watch', 'message' => 'Prieš šiltesnę savaitę lapai būna švelnesni.'],
                'resources' => [],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'sow_dill',
                'date' => '2026-06-01',
                'name' => 'Pasėti papildomą krapų partiją',
                'plant' => 'calendar_dill_2026',
                'zone' => 5,
                'type' => TaskType::Planting->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::Low->value,
                'reason' => 'Pakaitinis sėjimas pratęs žalumynų skynimą.',
                'comment' => 'Naudoti laisvą prieskoninių augalų lysvės kraštą.',
                'weather_context' => ['rule' => 'normal', 'message' => 'Dirva pakankamai sušilusi sėjai.'],
                'resources' => [['item' => 'seeds', 'quantity' => 1, 'consumed' => true]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'strawberry_fertilize',
                'date' => '2026-06-03',
                'name' => 'Patręšti braškes po pirmų uogų',
                'plant' => 'calendar_strawberry_2026',
                'zone' => 4,
                'type' => TaskType::Fertilize->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Braškėms po ankstyvo derliaus reikia atstatyti maisto medžiagas.',
                'comment' => 'Tręšti saikingai ir neatidengti šaknų.',
                'weather_context' => ['rule' => 'normal', 'message' => 'Vidutinė temperatūra tinkama papildymui.'],
                'resources' => [['item' => 'fertilizer', 'quantity' => 0.8, 'consumed' => true]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'carrot_water',
                'date' => '2026-06-06',
                'name' => 'Palaistyti morkų lysvę per sausą savaitę',
                'plant' => 'calendar_carrot_2026',
                'zone' => 2,
                'type' => TaskType::Watering->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::Low->value,
                'reason' => 'Šakniavaisiams reikia tolygaus drėgmės režimo.',
                'comment' => 'Laistyti giliau, bet rečiau.',
                'weather_context' => ['rule' => 'dry_spell', 'message' => 'Kelias dienas be reikšmingų kritulių.'],
                'resources' => [['item' => 'hose', 'quantity' => 1, 'consumed' => false]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'buy_supports',
                'date' => '2026-06-08',
                'name' => 'Nupirkti papildomų pomidorų atramų',
                'plant' => null,
                'zone' => 0,
                'type' => TaskType::Buy->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Antram pomidorų rišimo etapui suplanuotas atramų rezervas.',
                'comment' => 'Pirkti pagal lysvės eilių skaičių.',
                'weather_context' => ['rule' => 'inventory', 'message' => 'Inventoriaus papildymas.'],
                'resources' => [['item' => 'supports', 'quantity' => 18, 'consumed' => false]],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'strawberry_harvest',
                'date' => '2026-06-10',
                'name' => 'Nuimti braškių derlių',
                'plant' => 'calendar_strawberry_2026',
                'zone' => 4,
                'type' => TaskType::Harvest->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::High->value,
                'reason' => 'Braškių žydėjimas perėjo į derliaus langą.',
                'comment' => 'Skinti sausus vaisius ir atskirti pažeistus.',
                'weather_context' => ['rule' => 'normal', 'message' => 'Sausas rytas tinkamas uogoms.'],
                'resources' => [],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'herb_weeding',
                'date' => '2026-06-14',
                'name' => 'Išravėti prieskoninių augalų lysvę',
                'plant' => 'calendar_parsley_2026',
                'zone' => 5,
                'type' => TaskType::Rest->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::Low->value,
                'reason' => 'Prieskoninių augalų zona turi išlikti lengvai skinama.',
                'comment' => 'Po ravėjimo pažymėti petražolių būklę.',
                'weather_context' => ['rule' => 'normal', 'message' => 'Darbas suplanuotas po drėgnesnio periodo.'],
                'resources' => [],
                'overdue_candidate' => false,
            ],
            [
                'key' => 'cucumber_check',
                'date' => '2026-06-18',
                'name' => 'Patikrinti agurkų lapus ir žiedus',
                'plant' => 'calendar_cucumber_2026',
                'zone' => 1,
                'type' => TaskType::Spray->value,
                'state' => TaskState::Pending->value,
                'priority' => TaskPriority::Medium->value,
                'reason' => 'Pest check intervalas pasiekia kitą ciklą.',
                'comment' => 'Jei žiedai gausūs, įvertinti artėjantį derliaus laiką.',
                'weather_context' => ['rule' => 'normal', 'message' => 'Tęstinė būklės patikra.'],
                'resources' => [],
                'overdue_candidate' => false,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function weatherDefinitions(): array
    {
        return [
            ['date' => '2026-05-19', 'temperature' => 15.2, 'temp_min' => 8.4, 'temp_max' => 19.7, 'precipitation' => 11.8, 'humidity' => 86, 'wind_kmh' => 24, 'condition_code' => 'rain'],
            ['date' => '2026-05-21', 'temperature' => 20.1, 'temp_min' => 11.2, 'temp_max' => 27.6, 'precipitation' => 0.4, 'humidity' => 62, 'wind_kmh' => 18, 'condition_code' => 'partly-cloudy'],
            ['date' => '2026-05-22', 'temperature' => 18.3, 'temp_min' => 9.8, 'temp_max' => 23.4, 'precipitation' => 1.0, 'humidity' => 59, 'wind_kmh' => 49, 'condition_code' => 'windy'],
            ['date' => '2026-05-24', 'temperature' => 13.6, 'temp_min' => 7.4, 'temp_max' => 16.0, 'precipitation' => 14.6, 'humidity' => 91, 'wind_kmh' => 28, 'condition_code' => 'rain'],
            ['date' => '2026-05-27', 'temperature' => 10.8, 'temp_min' => 1.2, 'temp_max' => 17.6, 'precipitation' => 0.2, 'humidity' => 72, 'wind_kmh' => 20, 'condition_code' => 'cold-night'],
            ['date' => '2026-06-03', 'temperature' => 24.7, 'temp_min' => 14.1, 'temp_max' => 31.6, 'precipitation' => 0.0, 'humidity' => 51, 'wind_kmh' => 17, 'condition_code' => 'hot'],
            ['date' => '2026-06-06', 'temperature' => 22.4, 'temp_min' => 13.0, 'temp_max' => 29.0, 'precipitation' => 0.0, 'humidity' => 46, 'wind_kmh' => 21, 'condition_code' => 'dry'],
            ['date' => '2026-06-18', 'temperature' => 21.5, 'temp_min' => 12.8, 'temp_max' => 28.3, 'precipitation' => 2.6, 'humidity' => 64, 'wind_kmh' => 25, 'condition_code' => 'showers'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function harvestDefinitions(): array
    {
        return [
            ['plot' => 'calendar', 'plant' => 'calendar_tomato_2024', 'task' => null, 'harvested_on' => '2024-07-28', 'quantity' => 3.4, 'notes' => '2024 pirmasis pomidorų skynimas.'],
            ['plot' => 'calendar', 'plant' => 'calendar_tomato_2024', 'task' => null, 'harvested_on' => '2024-08-10', 'quantity' => 5.1, 'notes' => '2024 pomidorų pikas analitikai.'],
            ['plot' => 'calendar', 'plant' => 'calendar_cucumber_2024', 'task' => null, 'harvested_on' => '2024-07-12', 'quantity' => 2.8, 'notes' => '2024 agurkų ankstyvas derlius.'],
            ['plot' => 'calendar', 'plant' => 'calendar_cucumber_2024', 'task' => null, 'harvested_on' => '2024-07-26', 'quantity' => 4.0, 'notes' => '2024 agurkų derliaus banga.'],
            ['plot' => 'calendar', 'plant' => 'calendar_carrot_2024', 'task' => null, 'harvested_on' => '2024-08-29', 'quantity' => 6.3, 'notes' => '2024 morkų pagrindinis kasimas.'],
            ['plot' => 'calendar', 'plant' => 'calendar_lettuce_2024', 'task' => null, 'harvested_on' => '2024-06-03', 'quantity' => 1.6, 'notes' => '2024 salotų lapų skynimas.'],
            ['plot' => 'calendar', 'plant' => 'calendar_strawberry_2024', 'task' => null, 'harvested_on' => '2024-06-19', 'quantity' => 1.9, 'notes' => '2024 braškių ankstyvos uogos.'],
            ['plot' => 'rotation', 'plant' => 'rotation_potato_2024', 'task' => null, 'harvested_on' => '2024-09-08', 'quantity' => 12.4, 'notes' => '2024 bulvių rotacijos derlius.'],
            ['plot' => 'rotation', 'plant' => 'rotation_onion_2024', 'task' => null, 'harvested_on' => '2024-08-31', 'quantity' => 4.6, 'notes' => '2024 svogūnų sandėliavimo derlius.'],
            ['plot' => 'rotation', 'plant' => 'rotation_pea_2024', 'task' => null, 'harvested_on' => '2024-07-21', 'quantity' => 2.1, 'notes' => '2024 žirnių ankščių derlius geram priešsėliui.'],
            ['plot' => 'calendar', 'plant' => 'calendar_tomato_2025', 'task' => null, 'harvested_on' => '2025-07-30', 'quantity' => 4.2, 'notes' => '2025 pomidorų pradinis skynimas.'],
            ['plot' => 'calendar', 'plant' => 'calendar_tomato_2025', 'task' => null, 'harvested_on' => '2025-08-16', 'quantity' => 6.0, 'notes' => '2025 pomidorų gausus skynimas.'],
            ['plot' => 'calendar', 'plant' => 'calendar_cucumber_2025', 'task' => null, 'harvested_on' => '2025-07-14', 'quantity' => 3.1, 'notes' => '2025 agurkų pirmas derlius.'],
            ['plot' => 'calendar', 'plant' => 'calendar_cucumber_2025', 'task' => null, 'harvested_on' => '2025-07-30', 'quantity' => 4.5, 'notes' => '2025 agurkų pakartotinis derlius.'],
            ['plot' => 'calendar', 'plant' => 'calendar_carrot_2025', 'task' => null, 'harvested_on' => '2025-08-27', 'quantity' => 6.8, 'notes' => '2025 morkų derlius suvienodintai suvestinei.'],
            ['plot' => 'calendar', 'plant' => 'calendar_lettuce_2025', 'task' => null, 'harvested_on' => '2025-05-31', 'quantity' => 1.4, 'notes' => '2025 salotų ankstyvas skynimas.'],
            ['plot' => 'calendar', 'plant' => 'calendar_strawberry_2025', 'task' => null, 'harvested_on' => '2025-06-17', 'quantity' => 2.2, 'notes' => '2025 braškių pirmoji banga.'],
            ['plot' => 'calendar', 'plant' => 'calendar_strawberry_2025', 'task' => null, 'harvested_on' => '2025-06-28', 'quantity' => 1.7, 'notes' => '2025 braškių vėlesnis skynimas.'],
            ['plot' => 'rotation', 'plant' => 'rotation_tomato_2025', 'task' => null, 'harvested_on' => '2025-08-08', 'quantity' => 4.9, 'notes' => '2025 pomidorų derlius rotacijos konflikto zonoje.'],
            ['plot' => 'rotation', 'plant' => 'rotation_radish_2025', 'task' => null, 'harvested_on' => '2025-05-19', 'quantity' => 1.2, 'notes' => '2025 ridikėlių greitas Brassicaceae derlius.'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conditionHistoryDefinitions(): array
    {
        return [
            ['plant' => 'calendar_tomato_2026', 'measured_at' => '2026-04-08 09:10:00', 'condition' => ConditionType::Planted->value, 'notes' => 'Daigai pasodinti, bendra būklė sveikas.'],
            ['plant' => 'calendar_tomato_2026', 'measured_at' => '2026-04-23 09:10:00', 'condition' => ConditionType::Growing->value, 'notes' => 'Auga tolygiai, bet vakare reikia laistyti.'],
            ['plant' => 'calendar_tomato_2026', 'measured_at' => '2026-05-07 09:10:00', 'condition' => ConditionType::Diseased->value, 'notes' => 'Apatiniuose lapuose matyti ligos požymiai po drėgnų naktų.'],
            ['plant' => 'calendar_tomato_2026', 'measured_at' => '2026-05-20 09:10:00', 'condition' => ConditionType::Flowering->value, 'notes' => 'Žiedai gausūs, augalas grįžo į sveikas stebėsenos būsenas.'],
            ['plant' => 'calendar_cucumber_2026', 'measured_at' => '2026-04-28 08:40:00', 'condition' => ConditionType::Planted->value, 'notes' => 'Agurkų sėjinukai perkelti į lysvę.'],
            ['plant' => 'calendar_cucumber_2026', 'measured_at' => '2026-05-06 08:40:00', 'condition' => ConditionType::Germinating->value, 'notes' => 'Nauji lapeliai rodo gerą įsitvirtinimą.'],
            ['plant' => 'calendar_cucumber_2026', 'measured_at' => '2026-05-15 08:40:00', 'condition' => ConditionType::Growing->value, 'notes' => 'Stiebai auga, karštesnę dieną reikia laistyti.'],
            ['plant' => 'calendar_cucumber_2026', 'measured_at' => '2026-05-21 08:40:00', 'condition' => ConditionType::Growing->value, 'notes' => 'Būklė sveikas, ūseliai kabinasi į atramas.'],
            ['plant' => 'calendar_lettuce_2026', 'measured_at' => '2026-04-15 09:25:00', 'condition' => ConditionType::Planted->value, 'notes' => 'Salotos pasodintos ankstyvam skynimui.'],
            ['plant' => 'calendar_lettuce_2026', 'measured_at' => '2026-04-29 09:25:00', 'condition' => ConditionType::Growing->value, 'notes' => 'Lapai tankėja, dirva kiek sausesnė ir reikia laistyti.'],
            ['plant' => 'calendar_lettuce_2026', 'measured_at' => '2026-05-13 09:25:00', 'condition' => ConditionType::Mature->value, 'notes' => 'Brandos stadija pasiekta, lapai pasiruošę derliui.'],
            ['plant' => 'calendar_lettuce_2026', 'measured_at' => '2026-05-20 09:25:00', 'condition' => ConditionType::Mature->value, 'notes' => 'Derliaus patikra suplanuota, vidus dar sveikas.'],
            ['plant' => 'calendar_strawberry_2026', 'measured_at' => '2026-04-12 10:00:00', 'condition' => ConditionType::Regenerating->value, 'notes' => 'Po žiemos braškių kerai atsinaujina.'],
            ['plant' => 'calendar_strawberry_2026', 'measured_at' => '2026-05-01 10:00:00', 'condition' => ConditionType::Growing->value, 'notes' => 'Lapai sveiki, mulčio kraštuose reikia papildyti.'],
            ['plant' => 'calendar_strawberry_2026', 'measured_at' => '2026-05-14 10:00:00', 'condition' => ConditionType::Flowering->value, 'notes' => 'Žydėjimas gausus, artėja brandos stadija.'],
            ['plant' => 'calendar_strawberry_2026', 'measured_at' => '2026-05-20 10:00:00', 'condition' => ConditionType::Flowering->value, 'notes' => 'Pirmi vaisiai mezgasi, kol kas ligos požymių nėra.'],
            ['plant' => 'calendar_tomato_2024', 'measured_at' => '2024-04-15 09:00:00', 'condition' => ConditionType::Planted->value, 'notes' => '2024 pomidorų daigų sodinimo istorija.'],
            ['plant' => 'calendar_tomato_2024', 'measured_at' => '2024-06-12 09:00:00', 'condition' => ConditionType::Flowering->value, 'notes' => '2024 žydėjimas ir sveikas lapijos augimas.'],
            ['plant' => 'calendar_tomato_2024', 'measured_at' => '2024-07-28 09:00:00', 'condition' => ConditionType::Mature->value, 'notes' => '2024 pomidorai pasiruošę derliui.'],
            ['plant' => 'calendar_tomato_2024', 'measured_at' => '2024-09-20 09:00:00', 'condition' => ConditionType::Dried->value, 'notes' => '2024 derlius nuimtas ir augalas užbaigė sezoną.'],
            ['plant' => 'calendar_carrot_2024', 'measured_at' => '2024-04-03 08:50:00', 'condition' => ConditionType::Planted->value, 'notes' => '2024 morkų sėjos istorija.'],
            ['plant' => 'calendar_carrot_2024', 'measured_at' => '2024-04-21 08:50:00', 'condition' => ConditionType::Germinating->value, 'notes' => 'Morkų dygimas matomas, dirvai reikia laistyti.'],
            ['plant' => 'calendar_carrot_2024', 'measured_at' => '2024-07-10 08:50:00', 'condition' => ConditionType::Growing->value, 'notes' => 'Šaknys storėja ir lapija sveikas.'],
            ['plant' => 'calendar_carrot_2024', 'measured_at' => '2024-08-29 08:50:00', 'condition' => ConditionType::Mature->value, 'notes' => 'Morkos brandos stadijoje ir pasiruošusios derliui.'],
            ['plant' => 'rotation_tomato_2025', 'measured_at' => '2025-05-10 09:35:00', 'condition' => ConditionType::Planted->value, 'notes' => 'Pomidorai pasodinti rotacijos konflikto zonoje.'],
            ['plant' => 'rotation_tomato_2025', 'measured_at' => '2025-06-28 09:35:00', 'condition' => ConditionType::Flowering->value, 'notes' => 'Žydėjimas aktyvus, lapai sveikas.'],
            ['plant' => 'rotation_tomato_2025', 'measured_at' => '2025-07-19 09:35:00', 'condition' => ConditionType::Diseased->value, 'notes' => 'Po lietaus atsirado ligos požymiai apatiniuose lapuose.'],
            ['plant' => 'rotation_tomato_2025', 'measured_at' => '2025-09-21 09:35:00', 'condition' => ConditionType::Dried->value, 'notes' => 'Sezonas baigtas, derlius nuimtas.'],
            ['plant' => 'rotation_radish_2025', 'measured_at' => '2025-04-04 08:20:00', 'condition' => ConditionType::Planted->value, 'notes' => 'Ridikėlių sėja rotacijos įspėjimui.'],
            ['plant' => 'rotation_radish_2025', 'measured_at' => '2025-04-12 08:20:00', 'condition' => ConditionType::Germinating->value, 'notes' => 'Ridikėliai sudygo tolygiai.'],
            ['plant' => 'rotation_radish_2025', 'measured_at' => '2025-05-05 08:20:00', 'condition' => ConditionType::Mature->value, 'notes' => 'Ridikėliai brandos stadijoje ir pasiruošę derliui.'],
            ['plant' => 'rotation_radish_2025', 'measured_at' => '2025-05-19 08:20:00', 'condition' => ConditionType::Dried->value, 'notes' => 'Derlius nuimtas, trumpas ciklas užbaigtas.'],
        ];
    }
}
