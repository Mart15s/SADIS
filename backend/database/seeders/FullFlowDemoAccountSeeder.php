<?php

namespace Database\Seeders;

use App\Enums\AccessRole;
use App\Enums\ConditionType;
use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Enums\TaskPriority;
use App\Enums\TaskState;
use App\Models\AccessRight;
use App\Models\CatalogPlant;
use App\Models\GardenOwner;
use App\Models\HarvestRecord;
use App\Models\HasInventory;
use App\Models\HasPlot;
use App\Models\InventoryItem;
use App\Models\InventoryUsageLog;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantConditionHistory;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\RotationHistory;
use App\Models\RotationPlanDraft;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\TaskResourceRequirement;
use App\Models\User;
use App\Models\WeatherForecast;
use App\Services\Plot\PlotSnapshotService;
use App\Services\Plot\RotationPlannerService;
use App\Services\Calendar\TaskCalendarService;
use App\Services\Calendar\TaskWorkflowService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FullFlowDemoAccountSeeder extends Seeder
{
    private const DEMO_EMAIL = 'demo.garden@example.test';

    private const DEMO_PASSWORD = 'DemoGarden123!';

    /**
     * @var array<int, array{email:string,password:string,name:string,surname:string}>
     */
    private const DEMO_USERS = [
        [
            'email' => self::DEMO_EMAIL,
            'password' => self::DEMO_PASSWORD,
            'name' => 'Demo Garden',
            'surname' => 'User',
        ],
        [
            'email' => 'demo.viewer@example.test',
            'password' => 'DemoViewer123!',
            'name' => 'Shared Plot',
            'surname' => 'Viewer',
        ],
        [
            'email' => 'demo.editor@example.test',
            'password' => 'DemoEditor123!',
            'name' => 'Shared Plot',
            'surname' => 'Editor',
        ],
    ];

    /**
     * @var array<int, string>
     */
    private const DEMO_CATALOG_CANONICAL_NAMES = [
        'demo-pomidoras',
        'demo-agurkas',
        'demo-bazilikas',
        'demo-morka',
        'demo-svogunas',
        'demo-pupa',
        'demo-salota',
        'demo-braske',
        'demo-burokelis',
        'demo-serbentas',
        'demo-krapai',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->cleanupDemoData();

            $today = now()->startOfDay();

            [$demoUser, $demoOwner] = $this->createDemoOwner(self::DEMO_USERS[0]);
            [, $viewerOwner] = $this->createDemoOwner(self::DEMO_USERS[1]);
            [, $editorOwner] = $this->createDemoOwner(self::DEMO_USERS[2]);

            $catalog = $this->seedCatalog();
            $garden = $this->seedGardenStructure($demoOwner, $catalog, $today);
            $this->seedInventory($demoOwner);
            $this->seedSharing($demoOwner, $viewerOwner, $editorOwner, $garden['plots']);
            $this->seedConditionHistory($garden['plants'], $today);
            $this->seedRotationHistory($garden['plots'], $garden['zones'], $garden['plants'], $today);
            $this->seedHistoricalHarvests($demoOwner, $garden['plots'], $garden['plants'], $today);
            $this->seedPlotSnapshots($demoOwner, $garden['plots'], $today);
            $this->seedCalendarsAndWorkflow($demoOwner, $garden['plots'], $garden['plants'], $today);
            $this->seedRotationDraft($demoOwner, $garden['plots']['namu_darzas'], $today);

            $summary = $this->buildSummary($demoOwner);

            if ($this->command) {
                $this->command->info('Full-flow demo account prepared.');
                $this->command->line('Email: '.self::DEMO_EMAIL);
                $this->command->line('Password: '.self::DEMO_PASSWORD);
                $this->command->line('Role: '.($demoUser->role?->value ?? $demoUser->role));
                $this->command->line(sprintf(
                    'Plots: %d | Zones: %d | Plants: %d | Catalog plants: %d | Inventory items: %d | Tasks: %d',
                    $summary['plots'],
                    $summary['zones'],
                    $summary['plants'],
                    $summary['catalog_plants'],
                    $summary['inventory_items'],
                    $summary['tasks'],
                ));
            }
        });
    }

    private function cleanupDemoData(): void
    {
        $userIds = User::query()
            ->whereIn('email', collect(self::DEMO_USERS)->pluck('email'))
            ->pluck('id');

        if ($userIds->isEmpty()) {
            $this->cleanupDemoCatalog();

            return;
        }

        $owners = GardenOwner::query()
            ->whereIn('id', $userIds)
            ->orWhereIn('user_id', $userIds)
            ->orWhereIn('id_user', $userIds)
            ->get();
        $ownerIds = $owners->pluck('id')->unique()->values();
        $profileIds = $owners->pluck('fk_profile_id')->filter()->unique()->values();
        $plotIds = Plot::query()
            ->whereIn('garden_owner_id', $ownerIds)
            ->pluck('id');
        $zoneIds = PlantZone::query()
            ->whereIn('plot_id', $plotIds)
            ->pluck('id');
        $plantIds = Plant::query()
            ->whereIn('fk_plot_id', $plotIds)
            ->pluck('id');
        $calendarIds = TaskCalendar::query()
            ->whereIn('plot_id', $plotIds)
            ->pluck('id');
        $taskIds = Task::query()
            ->whereIn('task_calendar_id', $calendarIds)
            ->pluck('id');
        $inventoryItemIds = InventoryItem::query()
            ->whereIn('garden_owner_id', $ownerIds)
            ->pluck('id');

        AccessRight::query()
            ->whereIn('plot_id', $plotIds)
            ->orWhereIn('fk_grantor_owner_id', $userIds)
            ->orWhereIn('fk_recipient_owner_id', $userIds)
            ->delete();

        InventoryUsageLog::query()
            ->whereIn('task_id', $taskIds)
            ->orWhereIn('garden_owner_id', $ownerIds)
            ->orWhereIn('inventory_item_id', $inventoryItemIds)
            ->delete();

        HarvestRecord::query()
            ->whereIn('task_id', $taskIds)
            ->orWhereIn('plot_id', $plotIds)
            ->orWhereIn('plant_id', $plantIds)
            ->orWhereIn('garden_owner_id', $ownerIds)
            ->delete();

        TaskResourceRequirement::query()
            ->whereIn('task_id', $taskIds)
            ->delete();

        WeatherForecast::query()
            ->whereIn('task_calendar_id', $calendarIds)
            ->delete();

        Task::query()
            ->whereIn('id', $taskIds)
            ->delete();

        RotationPlanDraft::query()
            ->whereIn('plot_id', $plotIds)
            ->orWhereIn('garden_owner_id', $ownerIds)
            ->delete();

        RotationHistory::query()
            ->whereIn('fk_plot_id', $plotIds)
            ->orWhereIn('fk_plot_via_zone', $plotIds)
            ->orWhereIn('fk_plant_zone_id', $zoneIds)
            ->orWhereIn('fk_plant_id', $plantIds)
            ->delete();

        PlantConditionHistory::query()
            ->whereIn('plant_id', $plantIds)
            ->delete();

        DB::table('plot_snapshots')
            ->whereIn('plot_id', $plotIds)
            ->delete();

        Plant::query()
            ->whereIn('id', $plantIds)
            ->delete();

        PlantZone::query()
            ->whereIn('id', $zoneIds)
            ->delete();

        TaskCalendar::query()
            ->whereIn('id', $calendarIds)
            ->delete();

        HasPlot::query()
            ->whereIn('fk_plot_id', $plotIds)
            ->orWhereIn('fk_owner_id', $userIds)
            ->delete();

        Plot::query()
            ->whereIn('id', $plotIds)
            ->delete();

        HasInventory::query()
            ->whereIn('fk_inventory_item_id', $inventoryItemIds)
            ->orWhereIn('fk_owner_id', $userIds)
            ->delete();

        InventoryItem::query()
            ->whereIn('id', $inventoryItemIds)
            ->delete();

        GardenOwner::query()
            ->whereIn('id', $ownerIds)
            ->delete();

        Profile::query()
            ->whereIn('id', $profileIds)
            ->orWhereIn('user_id', $userIds)
            ->delete();

        User::query()
            ->whereIn('id', $userIds)
            ->delete();

        $this->cleanupDemoCatalog();
    }

    private function cleanupDemoCatalog(): void
    {
        $catalogIds = CatalogPlant::query()
            ->where('source_provider', 'demo')
            ->whereIn('canonical_name', self::DEMO_CATALOG_CANONICAL_NAMES)
            ->pluck('id');

        CatalogPlant::query()
            ->whereIn('id', $catalogIds)
            ->delete();

        PlantCare::query()
            ->where('source_provider', 'demo')
            ->whereIn('canonical_name', self::DEMO_CATALOG_CANONICAL_NAMES)
            ->delete();
    }

    /**
     * @param  array{email:string,password:string,name:string,surname:string}  $attributes
     * @return array{0: User, 1: GardenOwner}
     */
    private function createDemoOwner(array $attributes): array
    {
        $user = User::query()->create([
            'email' => $attributes['email'],
            'password' => $attributes['password'],
            'role' => 'owner',
        ]);

        $profile = Profile::query()->create([
            'user_id' => $user->id,
            'name' => $attributes['name'],
            'surname' => $attributes['surname'],
            'last_login' => now()->subDay(),
        ]);

        $owner = GardenOwner::query()->create([
            'id' => $user->id,
            'user_id' => $user->id,
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        return [$user, $owner];
    }

    /**
     * @return array<string, CatalogPlant>
     */
    private function seedCatalog(): array
    {
        return [
            'tomato' => $this->createCatalogPlant([
                'name' => 'Pomidoras',
                'canonical_name' => 'demo-pomidoras',
                'plant_type' => PlantType::Vegetable,
                'description' => 'Silumam megstantis siltnamio pomidoras.',
                'conditions' => 'Rich soil, full sun, evenly moist.',
                'germinating_duration_days' => 7,
                'growing_duration_days' => 24,
                'flowering_duration_days' => 10,
                'mature_duration_days' => 8,
                'mature_duration_end_days' => 6,
                'mature_end_duration_days' => 6,
                'watering_interval_days' => 2,
                'fertilizing_interval_days' => 7,
                'pest_check_interval_days' => 5,
                'rain_skip_threshold_mm' => 6,
                'frost_temp_threshold_c' => 4,
                'heat_extra_water_temp_c' => 27,
                'wind_protection_kmh' => 26,
                'reusable' => false,
                'source_scientific_name' => 'Solanum lycopersicum',
                'source_family' => 'Solanaceae',
            ]),
            'cucumber' => $this->createCatalogPlant([
                'name' => 'Agurkas',
                'canonical_name' => 'demo-agurkas',
                'plant_type' => PlantType::Vegetable,
                'description' => 'Greitai augantis siltnamio agurkas.',
                'conditions' => 'Rich soil, full sun, evenly moist.',
                'germinating_duration_days' => 5,
                'growing_duration_days' => 18,
                'flowering_duration_days' => 8,
                'mature_duration_days' => 7,
                'mature_duration_end_days' => 5,
                'mature_end_duration_days' => 5,
                'watering_interval_days' => 1,
                'fertilizing_interval_days' => 10,
                'pest_check_interval_days' => 6,
                'rain_skip_threshold_mm' => 7,
                'frost_temp_threshold_c' => 5,
                'heat_extra_water_temp_c' => 25,
                'wind_protection_kmh' => 24,
                'reusable' => false,
                'source_scientific_name' => 'Cucumis sativus',
                'source_family' => 'Cucurbitaceae',
            ]),
            'basil' => $this->createCatalogPlant([
                'name' => 'Bazilikas',
                'canonical_name' => 'demo-bazilikas',
                'plant_type' => PlantType::Herb,
                'description' => 'Kvapus vienmetis prieskoninis augalas.',
                'conditions' => 'Rich soil, full sun, evenly moist.',
                'germinating_duration_days' => 3,
                'growing_duration_days' => 14,
                'flowering_duration_days' => 5,
                'mature_duration_days' => 5,
                'mature_duration_end_days' => 4,
                'mature_end_duration_days' => 4,
                'watering_interval_days' => 1,
                'fertilizing_interval_days' => 3,
                'pest_check_interval_days' => 3,
                'rain_skip_threshold_mm' => 6,
                'frost_temp_threshold_c' => 5,
                'heat_extra_water_temp_c' => 26,
                'wind_protection_kmh' => 30,
                'reusable' => false,
                'source_scientific_name' => 'Ocimum basilicum',
                'source_family' => 'Lamiaceae',
            ]),
            'carrot' => $this->createCatalogPlant([
                'name' => 'Morka',
                'canonical_name' => 'demo-morka',
                'plant_type' => PlantType::Vegetable,
                'description' => 'Sakniavaisis lengvesniam smelingam dirvozemiu.',
                'conditions' => 'Sandy, well-drained soil.',
                'germinating_duration_days' => 10,
                'growing_duration_days' => 35,
                'flowering_duration_days' => 0,
                'mature_duration_days' => 12,
                'mature_duration_end_days' => 8,
                'mature_end_duration_days' => 8,
                'watering_interval_days' => 3,
                'fertilizing_interval_days' => 16,
                'pest_check_interval_days' => 7,
                'rain_skip_threshold_mm' => 8,
                'frost_temp_threshold_c' => 0,
                'heat_extra_water_temp_c' => 28,
                'wind_protection_kmh' => null,
                'reusable' => false,
                'source_scientific_name' => 'Daucus carota',
                'source_family' => 'Apiaceae',
            ]),
            'onion' => $this->createCatalogPlant([
                'name' => 'Svogunas',
                'canonical_name' => 'demo-svogunas',
                'plant_type' => PlantType::Vegetable,
                'description' => 'Lengvai auginamas svogunas atviroje lysveje.',
                'conditions' => 'Well-drained soil, full sun.',
                'germinating_duration_days' => 6,
                'growing_duration_days' => 28,
                'flowering_duration_days' => 0,
                'mature_duration_days' => 10,
                'mature_duration_end_days' => 6,
                'mature_end_duration_days' => 6,
                'watering_interval_days' => 4,
                'fertilizing_interval_days' => 18,
                'pest_check_interval_days' => 9,
                'rain_skip_threshold_mm' => 8,
                'frost_temp_threshold_c' => 0,
                'heat_extra_water_temp_c' => 28,
                'wind_protection_kmh' => null,
                'reusable' => false,
                'source_scientific_name' => 'Allium cepa',
                'source_family' => 'Amaryllidaceae',
            ]),
            'bean' => $this->createCatalogPlant([
                'name' => 'Pupa',
                'canonical_name' => 'demo-pupa',
                'plant_type' => PlantType::Legume,
                'description' => 'Pavasarine pupa jautri salnoms.',
                'conditions' => 'Clay or rich soil, full sun.',
                'germinating_duration_days' => 6,
                'growing_duration_days' => 25,
                'flowering_duration_days' => 8,
                'mature_duration_days' => 7,
                'mature_duration_end_days' => 4,
                'mature_end_duration_days' => 4,
                'watering_interval_days' => 2,
                'fertilizing_interval_days' => 12,
                'pest_check_interval_days' => 5,
                'rain_skip_threshold_mm' => 6,
                'frost_temp_threshold_c' => 3,
                'heat_extra_water_temp_c' => 27,
                'wind_protection_kmh' => 25,
                'reusable' => false,
                'source_scientific_name' => 'Phaseolus vulgaris',
                'source_family' => 'Fabaceae',
            ]),
            'lettuce' => $this->createCatalogPlant([
                'name' => 'Salota',
                'canonical_name' => 'demo-salota',
                'plant_type' => PlantType::Vegetable,
                'description' => 'Dalinis prieziuros profilis salotu testavimui.',
                'conditions' => 'Cool weather, evenly moist soil.',
                'germinating_duration_days' => 3,
                'growing_duration_days' => 14,
                'flowering_duration_days' => 0,
                'mature_duration_days' => 7,
                'mature_duration_end_days' => 4,
                'mature_end_duration_days' => 4,
                'watering_interval_days' => 2,
                'fertilizing_interval_days' => null,
                'pest_check_interval_days' => 5,
                'rain_skip_threshold_mm' => null,
                'frost_temp_threshold_c' => null,
                'heat_extra_water_temp_c' => null,
                'wind_protection_kmh' => null,
                'reusable' => false,
                'source_scientific_name' => 'Lactuca sativa',
                'source_family' => 'Asteraceae',
                'source_quality' => 'partial',
            ]),
            'strawberry' => $this->createCatalogPlant([
                'name' => 'Braske',
                'canonical_name' => 'demo-braske',
                'plant_type' => PlantType::Berry,
                'description' => 'Daugiamete braske su keliomis derliaus bangomis.',
                'conditions' => 'Rich soil, full sun, evenly moist.',
                'germinating_duration_days' => 0,
                'growing_duration_days' => 18,
                'flowering_duration_days' => 8,
                'mature_duration_days' => 6,
                'mature_duration_end_days' => 4,
                'mature_end_duration_days' => 4,
                'regenerating_duration_days' => 6,
                'watering_interval_days' => 2,
                'fertilizing_interval_days' => 10,
                'pest_check_interval_days' => 5,
                'rain_skip_threshold_mm' => 6,
                'frost_temp_threshold_c' => 2,
                'heat_extra_water_temp_c' => 27,
                'wind_protection_kmh' => 24,
                'reusable' => true,
                'source_scientific_name' => 'Fragaria x ananassa',
                'source_family' => 'Rosaceae',
            ]),
            'beetroot' => $this->createCatalogPlant([
                'name' => 'Burokelis',
                'canonical_name' => 'demo-burokelis',
                'plant_type' => PlantType::Vegetable,
                'description' => 'Vidutinio sezono burokelis sakniavaisiu testams.',
                'conditions' => 'Loam or sandy soil, evenly moist.',
                'germinating_duration_days' => 5,
                'growing_duration_days' => 30,
                'flowering_duration_days' => 0,
                'mature_duration_days' => 8,
                'mature_duration_end_days' => 5,
                'mature_end_duration_days' => 5,
                'watering_interval_days' => 3,
                'fertilizing_interval_days' => 14,
                'pest_check_interval_days' => 6,
                'rain_skip_threshold_mm' => 8,
                'frost_temp_threshold_c' => 1,
                'heat_extra_water_temp_c' => 28,
                'wind_protection_kmh' => null,
                'reusable' => false,
                'source_scientific_name' => 'Beta vulgaris',
                'source_family' => 'Amaranthaceae',
            ]),
            'currant' => $this->createCatalogPlant([
                'name' => 'Serbentas',
                'canonical_name' => 'demo-serbentas',
                'plant_type' => PlantType::Shrub,
                'description' => 'Daugiametis uoginis krumas.',
                'conditions' => 'Rich soil, partial sun, evenly moist.',
                'germinating_duration_days' => 0,
                'growing_duration_days' => 18,
                'flowering_duration_days' => 10,
                'mature_duration_days' => 10,
                'mature_duration_end_days' => 5,
                'mature_end_duration_days' => 5,
                'regenerating_duration_days' => 12,
                'watering_interval_days' => 4,
                'fertilizing_interval_days' => 18,
                'pest_check_interval_days' => 7,
                'rain_skip_threshold_mm' => 8,
                'frost_temp_threshold_c' => 1,
                'heat_extra_water_temp_c' => 29,
                'wind_protection_kmh' => 22,
                'reusable' => true,
                'source_scientific_name' => 'Ribes rubrum',
                'source_family' => 'Grossulariaceae',
            ]),
            'dill' => $this->createCatalogPlant([
                'name' => 'Krapai',
                'canonical_name' => 'demo-krapai',
                'plant_type' => PlantType::Herb,
                'description' => 'Katalogo augalas be pasodintu instanciju.',
                'conditions' => 'Full sun, light soil.',
                'germinating_duration_days' => 5,
                'growing_duration_days' => 14,
                'flowering_duration_days' => 4,
                'mature_duration_days' => 5,
                'mature_duration_end_days' => 3,
                'mature_end_duration_days' => 3,
                'watering_interval_days' => 2,
                'fertilizing_interval_days' => 10,
                'pest_check_interval_days' => 5,
                'rain_skip_threshold_mm' => 7,
                'frost_temp_threshold_c' => 2,
                'heat_extra_water_temp_c' => 27,
                'wind_protection_kmh' => null,
                'reusable' => false,
                'source_scientific_name' => 'Anethum graveolens',
                'source_family' => 'Apiaceae',
            ]),
        ];
    }

    /**
     * @param  array<string, CatalogPlant>  $catalog
     * @return array{plots: array<string, Plot>, zones: array<string, PlantZone>, plants: array<string, Plant>}
     */
    private function seedGardenStructure(GardenOwner $owner, array $catalog, Carbon $today): array
    {
        $plots = [
            'namu_darzas' => $this->createPlot($owner, [
                'name' => 'Namu darzas',
                'city' => 'Vilnius',
                'plot_size' => 84,
                'creation_date' => $today->copy()->subMonths(4)->toDateString(),
                'description' => 'Pagrindinis lauko darzas su rotacijos bandymais.',
                'share' => true,
                'geometry' => $this->rectGeometry(0, 0, 12, 7),
            ]),
            'siltnamis' => $this->createPlot($owner, [
                'name' => 'Siltnamis',
                'city' => 'Kaunas',
                'plot_size' => 36,
                'creation_date' => $today->copy()->subMonths(3)->toDateString(),
                'description' => 'Siltnamis pomidorams ir agurkams.',
                'share' => true,
                'geometry' => $this->rectGeometry(0, 0, 9, 4),
            ]),
            'uogu_kampas' => $this->createPlot($owner, [
                'name' => 'Uogu kampas',
                'city' => 'Utena',
                'plot_size' => 28,
                'creation_date' => $today->copy()->subMonths(6)->toDateString(),
                'description' => 'Braskiu ir serbentu zona su sezoniniais derliais.',
                'share' => true,
                'geometry' => $this->rectGeometry(0, 0, 7, 4),
            ]),
            'prieskoniu_lysve' => $this->createPlot($owner, [
                'name' => 'Prieskoniu lysve',
                'city' => 'Vilnius',
                'plot_size' => 16,
                'creation_date' => $today->copy()->subMonths(2)->toDateString(),
                'description' => 'Kasdien naudojamu prieskoniu lysve prie terasos.',
                'share' => false,
                'geometry' => $this->rectGeometry(0, 0, 8, 2),
            ]),
        ];

        $zones = [
            'sakniavaisiu' => $this->createZone($plots['namu_darzas'], [
                'name' => 'Sakniavaisiu lysve',
                'zone_size' => 18,
                'soil_type' => SoilType::Sandy,
                'rotation_stage' => 2,
                'last_planting_date' => $today->copy()->subDays(10)->toDateString(),
                'geometry' => $this->rectGeometry(0.4, 0.6, 4, 2.4),
            ]),
            'pupiniu' => $this->createZone($plots['namu_darzas'], [
                'name' => 'Pupiniu lysve',
                'zone_size' => 16,
                'soil_type' => SoilType::Clay,
                'rotation_stage' => 1,
                'last_planting_date' => $today->copy()->subDays(7)->toDateString(),
                'geometry' => $this->rectGeometry(4.8, 0.6, 3.6, 2.4),
            ]),
            'laisva_rotacija' => $this->createZone($plots['namu_darzas'], [
                'name' => 'Laisva rotacijos zona',
                'zone_size' => 14,
                'soil_type' => SoilType::Sandy,
                'rotation_stage' => 0,
                'last_planting_date' => $today->copy()->subDays(70)->toDateString(),
                'geometry' => $this->rectGeometry(8.8, 0.6, 2.8, 2.4),
            ]),
            'pomidoru' => $this->createZone($plots['siltnamis'], [
                'name' => 'Pomidoru zona',
                'zone_size' => 12,
                'soil_type' => SoilType::Greasy,
                'rotation_stage' => 1,
                'last_planting_date' => $today->copy()->subDays(21)->toDateString(),
                'geometry' => $this->rectGeometry(0.4, 0.4, 4, 3),
            ]),
            'agurku' => $this->createZone($plots['siltnamis'], [
                'name' => 'Agurku zona',
                'zone_size' => 10,
                'soil_type' => SoilType::Greasy,
                'rotation_stage' => 1,
                'last_planting_date' => $today->copy()->subDays(14)->toDateString(),
                'geometry' => $this->rectGeometry(4.8, 0.4, 3.6, 3),
            ]),
            'uogu' => $this->createZone($plots['uogu_kampas'], [
                'name' => 'Uogu juosta',
                'zone_size' => 14,
                'soil_type' => SoilType::Greasy,
                'rotation_stage' => 3,
                'last_planting_date' => $today->copy()->subDays(180)->toDateString(),
                'geometry' => $this->rectGeometry(0.5, 0.5, 3.5, 2.8),
            ]),
            'isvalyta' => $this->createZone($plots['uogu_kampas'], [
                'name' => 'Isvalyta zona',
                'zone_size' => 10,
                'soil_type' => SoilType::Sandy,
                'rotation_stage' => 2,
                'last_planting_date' => $today->copy()->subDays(4)->toDateString(),
                'geometry' => $this->rectGeometry(4.3, 0.5, 2.0, 2.8),
            ]),
            'prieskoniu' => $this->createZone($plots['prieskoniu_lysve'], [
                'name' => 'Prieskoniu juosta',
                'zone_size' => 8,
                'soil_type' => SoilType::Greasy,
                'rotation_stage' => 1,
                'last_planting_date' => $today->copy()->subDays(11)->toDateString(),
                'geometry' => $this->rectGeometry(0.4, 0.4, 7.2, 1.2),
            ]),
        ];

        $plants = [
            'carrot' => $this->createPlant($plots['namu_darzas'], $zones['sakniavaisiu'], $catalog['carrot'], [
                'plant_date' => $today->copy()->subDays(42)->toDateString(),
                'condition' => ConditionType::Growing,
                'plant_size' => 3.5,
                'rest_time_days' => 40,
                'recommended_temperature' => 18,
                'recommended_humidity' => 65,
            ]),
            'onion' => $this->createPlant($plots['namu_darzas'], $zones['sakniavaisiu'], $catalog['onion'], [
                'plant_date' => $today->copy()->subDays(55)->toDateString(),
                'condition' => ConditionType::Mature,
                'plant_size' => 2.8,
                'rest_time_days' => 35,
                'recommended_temperature' => 18,
                'recommended_humidity' => 60,
            ]),
            'beetroot' => $this->createPlant($plots['namu_darzas'], $zones['sakniavaisiu'], $catalog['beetroot'], [
                'plant_date' => $today->copy()->subDays(78)->toDateString(),
                'condition' => ConditionType::Dried,
                'plant_size' => 2.4,
                'rest_time_days' => 45,
                'recommended_temperature' => 17,
                'recommended_humidity' => 62,
            ]),
            'bean' => $this->createPlant($plots['namu_darzas'], $zones['pupiniu'], $catalog['bean'], [
                'plant_date' => $today->copy()->subDays(6)->toDateString(),
                'condition' => ConditionType::Germinating,
                'plant_size' => 4.0,
                'rest_time_days' => 28,
                'recommended_temperature' => 20,
                'recommended_humidity' => 68,
            ]),
            'lettuce' => $this->createPlant($plots['namu_darzas'], $zones['pupiniu'], $catalog['lettuce'], [
                'plant_date' => $today->copy()->subDays(12)->toDateString(),
                'condition' => ConditionType::Planted,
                'plant_size' => 3.2,
                'rest_time_days' => 20,
                'recommended_temperature' => 16,
                'recommended_humidity' => 72,
            ]),
            'tomato_early' => $this->createPlant($plots['siltnamis'], $zones['pomidoru'], $catalog['tomato'], [
                'name' => 'Pomidoras Cherry',
                'plant_date' => $today->copy()->subDays(38)->toDateString(),
                'condition' => ConditionType::Flowering,
                'plant_size' => 5.4,
                'rest_time_days' => 42,
                'recommended_temperature' => 23,
                'recommended_humidity' => 68,
                'disease' => true,
                'disease_notes' => 'Pastebeti pirmi miltliges pozymiai.',
            ]),
            'tomato_late' => $this->createPlant($plots['siltnamis'], $zones['pomidoru'], $catalog['tomato'], [
                'name' => 'Pomidoras Jaucio sirdis',
                'plant_date' => $today->copy()->subDays(52)->toDateString(),
                'condition' => ConditionType::Mature,
                'plant_size' => 5.8,
                'rest_time_days' => 42,
                'recommended_temperature' => 22,
                'recommended_humidity' => 66,
            ]),
            'cucumber_main' => $this->createPlant($plots['siltnamis'], $zones['agurku'], $catalog['cucumber'], [
                'plant_date' => $today->copy()->subDays(24)->toDateString(),
                'condition' => ConditionType::Growing,
                'plant_size' => 4.3,
                'rest_time_days' => 28,
                'recommended_temperature' => 22,
                'recommended_humidity' => 72,
            ]),
            'cucumber_new' => $this->createPlant($plots['siltnamis'], $zones['agurku'], $catalog['cucumber'], [
                'name' => 'Agurkas Atsarginis',
                'plant_date' => $today->copy()->subDays(2)->toDateString(),
                'condition' => ConditionType::Planted,
                'plant_size' => 2.1,
                'rest_time_days' => 28,
                'recommended_temperature' => 21,
                'recommended_humidity' => 72,
            ]),
            'strawberry' => $this->createPlant($plots['uogu_kampas'], $zones['uogu'], $catalog['strawberry'], [
                'plant_date' => $today->copy()->subDays(80)->toDateString(),
                'condition' => ConditionType::Growing,
                'plant_size' => 3.4,
                'rest_time_days' => 30,
                'recommended_temperature' => 19,
                'recommended_humidity' => 70,
                'reusable' => true,
            ]),
            'currant' => $this->createPlant($plots['uogu_kampas'], $zones['uogu'], $catalog['currant'], [
                'plant_date' => $today->copy()->subDays(240)->toDateString(),
                'condition' => ConditionType::Regenerating,
                'plant_size' => 4.1,
                'rest_time_days' => 60,
                'recommended_temperature' => 18,
                'recommended_humidity' => 68,
                'reusable' => true,
            ]),
            'basil' => $this->createPlant($plots['prieskoniu_lysve'], $zones['prieskoniu'], $catalog['basil'], [
                'plant_date' => $today->copy()->subDays(12)->toDateString(),
                'condition' => ConditionType::Growing,
                'plant_size' => 2.3,
                'rest_time_days' => 14,
                'recommended_temperature' => 21,
                'recommended_humidity' => 66,
            ]),
        ];

        return [
            'plots' => $plots,
            'zones' => $zones,
            'plants' => $plants,
        ];
    }

    private function seedInventory(GardenOwner $owner): void
    {
        $this->createInventoryItem($owner, 'Watering can', 1, InventoryItemType::Tool, InventoryUnit::Unit);
        $this->createInventoryItem($owner, 'Garden hose', 1, InventoryItemType::Tool, InventoryUnit::Unit);
        $this->createInventoryItem($owner, 'Sprayer', 1, InventoryItemType::Tool, InventoryUnit::Unit);
        $this->createInventoryItem($owner, 'Plant support', 1, InventoryItemType::Tool, InventoryUnit::Unit);
        $this->createInventoryItem($owner, 'Protective cover', 1, InventoryItemType::Tool, InventoryUnit::Unit);
        $this->createInventoryItem($owner, 'Fertilizer', 0.40, InventoryItemType::Material, InventoryUnit::Kilogram);
        $this->createInventoryItem($owner, 'Compost', 6.00, InventoryItemType::Material, InventoryUnit::Kilogram);
        $this->createInventoryItem($owner, 'Fungicide', 0.20, InventoryItemType::Material, InventoryUnit::Liter);
        $this->createInventoryItem($owner, 'Neem oil', 0.50, InventoryItemType::Material, InventoryUnit::Liter);
        $this->createInventoryItem($owner, 'Tomato seedlings', 8.00, InventoryItemType::Material, InventoryUnit::Unit);
        $this->createInventoryItem($owner, 'Lettuce seeds', 2.00, InventoryItemType::Material, InventoryUnit::Pack);
    }

    /**
     * @param  array<string, Plot>  $plots
     */
    private function seedSharing(GardenOwner $grantor, GardenOwner $viewer, GardenOwner $editor, array $plots): void
    {
        AccessRight::query()->create([
            'granted_at' => now()->subDays(9),
            'role' => AccessRole::Viewer,
            'plot_id' => $plots['uogu_kampas']->id,
            'fk_plot_id' => $plots['uogu_kampas']->id,
            'fk_grantor_owner_id' => $grantor->id_user,
            'fk_grantor_profile_id' => $grantor->fk_profile_id,
            'fk_recipient_owner_id' => $viewer->id_user,
            'fk_recipient_profile_id' => $viewer->fk_profile_id,
        ]);

        AccessRight::query()->create([
            'granted_at' => now()->subDays(4),
            'role' => AccessRole::Editor,
            'plot_id' => $plots['siltnamis']->id,
            'fk_plot_id' => $plots['siltnamis']->id,
            'fk_grantor_owner_id' => $grantor->id_user,
            'fk_grantor_profile_id' => $grantor->fk_profile_id,
            'fk_recipient_owner_id' => $editor->id_user,
            'fk_recipient_profile_id' => $editor->fk_profile_id,
        ]);
    }

    /**
     * @param  array<string, Plant>  $plants
     */
    private function seedConditionHistory(array $plants, Carbon $today): void
    {
        $this->recordCondition($plants['tomato_early'], ConditionType::Planted, $today->copy()->subDays(38)->setTime(9, 0), 'Persodintas i siltnami.');
        $this->recordCondition($plants['tomato_early'], ConditionType::Growing, $today->copy()->subDays(24)->setTime(8, 30), 'Stiebas stiprus, reikia pirmo pririsimo.');
        $this->recordCondition($plants['tomato_early'], ConditionType::Flowering, $today->copy()->subDays(8)->setTime(10, 15), 'Prasidejo zydejimas.');
        $this->recordCondition($plants['tomato_early'], ConditionType::Diseased, $today->copy()->subDays(2)->setTime(7, 45), 'Apatiniuose lapuose matomos demes.');

        $this->recordCondition($plants['lettuce'], ConditionType::Germinating, $today->copy()->subDays(10)->setTime(8, 0), 'Dygimas netolygus po saltesnes savaites.');
        $this->recordCondition($plants['lettuce'], ConditionType::Planted, $today->copy()->subDays(4)->setTime(8, 20), 'Augalai prigijo, bet dalis prieziuros duomenu dar neuzpildyta.');

        $this->recordCondition($plants['currant'], ConditionType::Mature, $today->copy()->subDays(20)->setTime(9, 0), 'Krume buvo daug uogu.');
        $this->recordCondition($plants['currant'], ConditionType::Regenerating, $today->copy()->subDays(6)->setTime(9, 20), 'Po derliaus atliktas lengvas genėjimas.');
    }

    /**
     * @param  array<string, Plot>  $plots
     * @param  array<string, PlantZone>  $zones
     * @param  array<string, Plant>  $plants
     */
    private function seedRotationHistory(array $plots, array $zones, array $plants, Carbon $today): void
    {
        RotationHistory::query()->create([
            'from_date' => $today->copy()->subDays(120)->toDateString(),
            'to_date' => $today->copy()->subDays(80)->toDateString(),
            'plant_zone_id' => $zones['sakniavaisiu']->id,
            'fk_plot_id' => $plots['namu_darzas']->id,
            'fk_plant_zone_id' => $zones['sakniavaisiu']->id,
            'fk_plot_via_zone' => $plots['namu_darzas']->id,
            'fk_plant_id' => $plants['carrot']->id,
        ]);

        RotationHistory::query()->create([
            'from_date' => $today->copy()->subDays(75)->toDateString(),
            'to_date' => $today->copy()->subDays(42)->toDateString(),
            'plant_zone_id' => $zones['pupiniu']->id,
            'fk_plot_id' => $plots['namu_darzas']->id,
            'fk_plant_zone_id' => $zones['pupiniu']->id,
            'fk_plot_via_zone' => $plots['namu_darzas']->id,
            'fk_plant_id' => $plants['bean']->id,
        ]);

        RotationHistory::query()->create([
            'from_date' => $today->copy()->subDays(60)->toDateString(),
            'to_date' => $today->copy()->subDays(4)->toDateString(),
            'plant_zone_id' => $zones['isvalyta']->id,
            'fk_plot_id' => $plots['uogu_kampas']->id,
            'fk_plant_zone_id' => $zones['isvalyta']->id,
            'fk_plot_via_zone' => $plots['uogu_kampas']->id,
            'fk_plant_id' => $plants['beetroot']->id,
        ]);
    }

    /**
     * @param  array<string, Plot>  $plots
     * @param  array<string, Plant>  $plants
     */
    private function seedHistoricalHarvests(GardenOwner $owner, array $plots, array $plants, Carbon $today): void
    {
        HarvestRecord::query()->create([
            'plot_id' => $plots['namu_darzas']->id,
            'plant_id' => $plants['beetroot']->id,
            'task_id' => null,
            'garden_owner_id' => $owner->id,
            'quantity' => 2.40,
            'harvested_on' => $today->copy()->subDays(4)->toDateString(),
            'notes' => 'Paskutiniai burokeliai nuimti po lietingos savaites.',
        ]);

        HarvestRecord::query()->create([
            'plot_id' => $plots['siltnamis']->id,
            'plant_id' => $plants['tomato_late']->id,
            'task_id' => null,
            'garden_owner_id' => $owner->id,
            'quantity' => 1.80,
            'harvested_on' => $today->copy()->subDays(2)->toDateString(),
            'notes' => 'Pirmasis prinokusiu vaisiu skynimas.',
        ]);
    }

    /**
     * @param  array<string, Plot>  $plots
     */
    private function seedPlotSnapshots(GardenOwner $owner, array $plots, Carbon $today): void
    {
        $snapshotService = app(PlotSnapshotService::class);

        $this->captureSnapshot(
            $snapshotService,
            $plots['namu_darzas'],
            'plot_created',
            $owner,
            $today->copy()->subDays(30)->setTime(8, 0),
            []
        );

        $this->captureSnapshot(
            $snapshotService,
            $plots['namu_darzas'],
            'plot_saved',
            $owner,
            $today->copy()->subDays(12)->setTime(19, 15),
            [
                'label' => 'Added root bed changes',
                'summary' => 'Expanded the root-vegetable bed and refreshed plant placement.',
            ]
        );

        $this->captureSnapshot(
            $snapshotService,
            $plots['namu_darzas'],
            'rotation_recorded',
            $owner,
            $today->copy()->subDays(5)->setTime(18, 10),
            []
        );

        $this->captureSnapshot(
            $snapshotService,
            $plots['siltnamis'],
            'plot_saved',
            $owner,
            $today->copy()->subDays(7)->setTime(20, 0),
            [
                'label' => 'Adjusted greenhouse layout',
                'summary' => 'Reserved more room for tomatoes and updated greenhouse geometry.',
            ]
        );

        $this->captureSnapshot(
            $snapshotService,
            $plots['uogu_kampas'],
            'plot_saved',
            $owner,
            $today->copy()->subDays(3)->setTime(17, 45),
            [
                'label' => 'Logged berry harvest cleanup',
                'summary' => 'Marked the cleared berry strip after the latest harvest.',
            ]
        );
    }

    /**
     * @param  array<string, Plot>  $plots
     * @param  array<string, Plant>  $plants
     */
    private function seedCalendarsAndWorkflow(GardenOwner $owner, array $plots, array $plants, Carbon $today): void
    {
        $historicalCalendar = $this->generateCalendarForPlot(
            $plots['prieskoniu_lysve'],
            $today->copy()->subDays(3),
            $today->copy()->subDays(3),
            [
                'Vilnius' => [
                    [
                        'date' => $today->copy()->subDays(3)->setTime(12, 0)->toDateTimeString(),
                        'temp_min' => 11,
                        'temp_max' => 19,
                        'rain' => 0,
                        'wind_kmh' => 8,
                        'condition' => 'clear',
                    ],
                ],
            ]
        );

        $workflow = app(TaskWorkflowService::class);

        $workflowCalendar = TaskCalendar::query()->create([
            'creation_date' => $today->copy()->subDays(5)->setTime(8, 0),
            'start_date' => $today->copy()->subDays(5)->toDateString(),
            'end_date' => $today->copy()->subDays(5)->toDateString(),
            'plot_id' => $plots['prieskoniu_lysve']->id,
            'fk_plot_id' => $plots['prieskoniu_lysve']->id,
        ]);

        $manualFertilizeTask = Task::query()->create([
            'date' => $today->copy()->subDays(5)->toDateString(),
            'name' => 'Feed Bazilikas after transplant shock',
            'task_type' => 'fertilize',
            'type' => 'fertilize',
            'priority' => TaskPriority::Medium,
            'reason' => 'Historical workflow seed for restock and unblock coverage.',
            'comment' => 'Completing this task should only be possible after restocking fertilizer.',
            'item' => 'Fertilizer',
            'item_quantity' => 1.00,
            'state' => TaskState::Pending,
            'status' => TaskState::Pending->value,
            'task_calendar_id' => $workflowCalendar->id,
            'fk_task_calendar_id' => $workflowCalendar->id,
            'plant_id' => $plants['basil']->id,
            'fk_plant_id' => $plants['basil']->id,
            'plant_zone_id' => $plants['basil']->plant_zone_id,
        ]);

        TaskResourceRequirement::query()->create([
            'task_id' => $manualFertilizeTask->id,
            'resource_name' => 'Fertilizer',
            'normalized_name' => 'fertilizer',
            'inventory_item_type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
            'required_quantity' => 1.00,
            'shortage_quantity' => 1.00,
            'is_consumed' => true,
        ]);

        $manualBuyTask = Task::query()->create([
            'date' => $today->copy()->subDays(5)->toDateString(),
            'name' => 'Buy Fertilizer',
            'task_type' => 'buy',
            'type' => 'buy',
            'priority' => TaskPriority::High,
            'reason' => 'Historical replenishment seed for blocked task coverage.',
            'comment' => 'Restock fertilizer so the blocked fertilize task can be completed.',
            'item' => 'Fertilizer',
            'item_quantity' => 1.20,
            'inventory_context' => [
                'status' => 'replenishment',
                'inventory_mode' => 'replenishment',
                'is_actionable' => true,
                'shortage_count' => 1,
                'shortage_quantity' => 1.20,
                'required_quantity' => 1.20,
                'available_quantity' => 0.40,
                'resource_key' => 'material|kg|fertilizer',
                'resource_mode' => 'consumable',
                'expected_item_type' => InventoryItemType::Material->value,
                'unit' => InventoryUnit::Kilogram->value,
                'buy_task_ids' => [],
            ],
            'state' => TaskState::Pending,
            'status' => TaskState::Pending->value,
            'task_calendar_id' => $workflowCalendar->id,
            'fk_task_calendar_id' => $workflowCalendar->id,
            'plant_id' => null,
            'fk_plant_id' => null,
            'plant_zone_id' => null,
        ]);

        TaskResourceRequirement::query()->create([
            'task_id' => $manualBuyTask->id,
            'resource_name' => 'Fertilizer',
            'normalized_name' => 'fertilizer',
            'inventory_item_type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
            'required_quantity' => 1.20,
            'shortage_quantity' => 1.20,
            'is_consumed' => false,
        ]);

        $workflow->complete($manualBuyTask);
        $workflow->complete($manualFertilizeTask);

        $buyTask = Task::query()
            ->where('task_calendar_id', $historicalCalendar->id)
            ->where('task_type', 'buy')
            ->where('item', 'Fertilizer')
            ->first();

        if ($buyTask) {
            $workflow->complete($buyTask);
        }

        $fertilizeTask = Task::query()
            ->where('task_calendar_id', $historicalCalendar->id)
            ->where('task_type', 'fertilize')
            ->where('plant_id', $plants['basil']->id)
            ->first();

        if ($fertilizeTask) {
            $workflow->complete($fertilizeTask);
        }

        $sprayTask = Task::query()
            ->where('task_calendar_id', $historicalCalendar->id)
            ->where('task_type', 'spray')
            ->where('plant_id', $plants['basil']->id)
            ->first();

        if ($sprayTask) {
            $workflow->reject($sprayTask, 'Task deferred after manual inspection.');
        }

        $berryCalendar = TaskCalendar::query()->create([
            'creation_date' => $today->copy()->subDays(1)->setTime(7, 30),
            'start_date' => $today->copy()->subDays(1)->toDateString(),
            'end_date' => $today->copy()->subDays(1)->toDateString(),
            'plot_id' => $plots['uogu_kampas']->id,
            'fk_plot_id' => $plots['uogu_kampas']->id,
        ]);

        $berryHarvestTask = Task::query()->create([
            'date' => $today->copy()->subDays(1)->toDateString(),
            'name' => 'Harvest Braske',
            'task_type' => 'harvest',
            'type' => 'harvest',
            'priority' => TaskPriority::Medium,
            'reason' => 'Berry row reached the planned harvest window.',
            'comment' => 'Collected ripe berries from the main row.',
            'state' => TaskState::Pending,
            'status' => TaskState::Pending->value,
            'task_calendar_id' => $berryCalendar->id,
            'fk_task_calendar_id' => $berryCalendar->id,
            'plant_id' => $plants['strawberry']->id,
            'fk_plant_id' => $plants['strawberry']->id,
            'plant_zone_id' => $plants['strawberry']->plant_zone_id,
        ]);

        $workflow->complete($berryHarvestTask, harvest: [
            'quantity' => 1.35,
            'harvested_on' => $today->copy()->subDays(1)->toDateString(),
            'notes' => 'Weekend basket from the main strawberry row.',
        ]);

        $this->generateCalendarForPlot(
            $plots['siltnamis'],
            $today,
            $today->copy()->addDays(4),
            [
                'Kaunas' => [
                    [
                        'date' => $today->copy()->setTime(12, 0)->toDateTimeString(),
                        'temp_min' => 8,
                        'temp_max' => 17,
                        'rain' => 0,
                        'wind_kmh' => 34,
                        'condition' => 'cloudy',
                    ],
                    [
                        'date' => $today->copy()->addDay()->setTime(12, 0)->toDateTimeString(),
                        'temp_min' => 17,
                        'temp_max' => 31,
                        'rain' => 0,
                        'wind_kmh' => 9,
                        'condition' => 'clear',
                    ],
                    [
                        'date' => $today->copy()->addDays(2)->setTime(12, 0)->toDateTimeString(),
                        'temp_min' => 12,
                        'temp_max' => 19,
                        'rain' => 13,
                        'wind_kmh' => 12,
                        'condition' => 'light-rain',
                    ],
                    [
                        'date' => $today->copy()->addDays(3)->setTime(12, 0)->toDateTimeString(),
                        'temp_min' => 11,
                        'temp_max' => 20,
                        'rain' => 0,
                        'wind_kmh' => 16,
                        'condition' => 'variable-cloudiness',
                    ],
                    [
                        'date' => $today->copy()->addDays(4)->setTime(12, 0)->toDateTimeString(),
                        'temp_min' => 13,
                        'temp_max' => 23,
                        'rain' => 2,
                        'wind_kmh' => 14,
                        'condition' => 'cloudy-with-sunny-intervals',
                    ],
                ],
            ]
        );

        $this->generateCalendarForPlot(
            $plots['uogu_kampas'],
            $today,
            $today->copy()->addDays(2),
            [
                'Utena' => [
                    [
                        'date' => $today->copy()->setTime(12, 0)->toDateTimeString(),
                        'temp_min' => -2,
                        'temp_max' => 5,
                        'rain' => 0,
                        'wind_kmh' => 10,
                        'condition' => 'clear',
                    ],
                    [
                        'date' => $today->copy()->addDay()->setTime(12, 0)->toDateTimeString(),
                        'temp_min' => 4,
                        'temp_max' => 12,
                        'rain' => 5,
                        'wind_kmh' => 18,
                        'condition' => 'cloudy-with-sunny-intervals',
                    ],
                    [
                        'date' => $today->copy()->addDays(2)->setTime(12, 0)->toDateTimeString(),
                        'temp_min' => 7,
                        'temp_max' => 16,
                        'rain' => 1,
                        'wind_kmh' => 12,
                        'condition' => 'light-rain',
                    ],
                ],
            ]
        );

        $this->refreshTaskContextsForOwner($owner);
    }

    private function seedRotationDraft(GardenOwner $owner, Plot $plot, Carbon $today): void
    {
        app(RotationPlannerService::class)->createDraft(
            $plot->fresh([
                'plantZones.plants.catalogPlant.plantCare',
                'plantZones.rotationHistory.plant',
                'plants.plantZone',
                'plants.catalogPlant.plantCare',
                'rotationHistory.plantZone',
                'rotationHistory.plant',
            ]),
            $today->copy()->addDay()->toDateString(),
            $owner,
        );
    }

    /**
     * @return array<string, int>
     */
    private function buildSummary(GardenOwner $owner): array
    {
        $plotIds = Plot::query()->where('garden_owner_id', $owner->id)->pluck('id');
        $zoneIds = PlantZone::query()->whereIn('plot_id', $plotIds)->pluck('id');
        $plantIds = Plant::query()->whereIn('fk_plot_id', $plotIds)->pluck('id');
        $calendarIds = TaskCalendar::query()->whereIn('plot_id', $plotIds)->pluck('id');

        return [
            'plots' => $plotIds->count(),
            'zones' => $zoneIds->count(),
            'plants' => $plantIds->count(),
            'catalog_plants' => CatalogPlant::query()
                ->where('source_provider', 'demo')
                ->whereIn('canonical_name', self::DEMO_CATALOG_CANONICAL_NAMES)
                ->count(),
            'inventory_items' => InventoryItem::query()->where('garden_owner_id', $owner->id)->count(),
            'tasks' => Task::query()->whereIn('task_calendar_id', $calendarIds)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCatalogPlant(array $attributes): CatalogPlant
    {
        $care = PlantCare::query()->create([
            'description' => $attributes['description'],
            'conditions' => $attributes['conditions'],
            'growing_duration_days' => $attributes['growing_duration_days'],
            'flowering_duration_days' => $attributes['flowering_duration_days'],
            'germinating_duration_days' => $attributes['germinating_duration_days'],
            'mature_duration_days' => $attributes['mature_duration_days'],
            'mature_duration_end_days' => $attributes['mature_duration_end_days'],
            'mature_end_duration_days' => $attributes['mature_end_duration_days'],
            'regenerating_duration_days' => $attributes['regenerating_duration_days'] ?? 0,
            'reusable' => $attributes['reusable'],
            'plant_name' => $attributes['name'],
            'canonical_name' => $attributes['canonical_name'],
            'task_type' => 'watering',
            'plant_type' => $attributes['plant_type'],
            'condition' => 'planted',
            'watering_interval_days' => $attributes['watering_interval_days'] ?? 3,
            'fertilizing_interval_days' => $attributes['fertilizing_interval_days'] ?? 21,
            'pest_check_interval_days' => $attributes['pest_check_interval_days'] ?? 10,
            'rain_skip_threshold_mm' => $attributes['rain_skip_threshold_mm'] ?? 50,
            'frost_temp_threshold_c' => $attributes['frost_temp_threshold_c'] ?? -15,
            'heat_extra_water_temp_c' => $attributes['heat_extra_water_temp_c'] ?? 45,
            'wind_protection_kmh' => $attributes['wind_protection_kmh'] ?? 120,
            'source_provider' => 'demo',
            'source_quality' => $attributes['source_quality'] ?? 'partial',
            'source_common_name' => $attributes['name'],
            'source_scientific_name' => $attributes['source_scientific_name'],
            'source_family' => $attributes['source_family'],
            'source_image_url' => null,
        ]);

        return CatalogPlant::query()->create([
            'name' => $attributes['name'],
            'canonical_name' => $attributes['canonical_name'],
            'plant_type' => $attributes['plant_type'],
            'fk_plant_care_id' => $care->id,
            'description' => $attributes['description'],
            'source_provider' => 'demo',
            'source_quality' => $attributes['source_quality'] ?? 'partial',
            'source_scientific_name' => $attributes['source_scientific_name'],
            'source_family' => $attributes['source_family'],
            'source_image_url' => null,
            'metadata' => [
                'seeded_for_demo' => true,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPlot(GardenOwner $owner, array $attributes): Plot
    {
        $plot = Plot::query()->create(array_merge($attributes, [
            'garden_owner_id' => $owner->id,
        ]));

        HasPlot::query()->create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return $plot;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createZone(Plot $plot, array $attributes): PlantZone
    {
        return PlantZone::query()->create(array_merge($attributes, [
            'plot_id' => $plot->id,
            'fk_plot_id' => $plot->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPlant(Plot $plot, PlantZone $zone, CatalogPlant $catalogPlant, array $attributes): Plant
    {
        $care = $catalogPlant->plantCare()->first();

        return Plant::query()->create(array_merge([
            'name' => $attributes['name'] ?? $catalogPlant->name,
            'growing_time_days' => $care?->growing_duration_days,
            'recommended_temperature' => null,
            'recommended_humidity' => null,
            'plant_date' => now()->subDays(10)->toDateString(),
            'disease_notes' => null,
            'disease' => false,
            'rest_time_days' => 30,
            'plant_size' => 2.5,
            'photo_url' => null,
            'reusable' => (bool) ($care?->reusable ?? false),
            'type' => $catalogPlant->plant_type,
            'condition' => ConditionType::Growing,
            'fk_catalog_plant_id' => $catalogPlant->id,
            'plant_zone_id' => $zone->id,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
        ], $attributes));
    }

    private function createInventoryItem(
        GardenOwner $owner,
        string $name,
        float $quantity,
        InventoryItemType $type,
        InventoryUnit $unit,
    ): InventoryItem {
        $item = InventoryItem::query()->create([
            'garden_owner_id' => $owner->id,
            'name' => $name,
            'normalized_name' => mb_strtolower(trim($name)),
            'quantity' => $quantity,
            'type' => $type,
            'inventory_item_type' => $type,
            'unit' => $unit,
        ]);

        HasInventory::query()->create([
            'fk_inventory_item_id' => $item->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return $item;
    }

    private function recordCondition(Plant $plant, ConditionType $condition, Carbon $measuredAt, string $notes): void
    {
        PlantConditionHistory::query()->create([
            'plant_id' => $plant->id,
            'fk_plant_id' => $plant->id,
            'measured_at' => $measuredAt,
            'notes' => $notes,
            'condition' => $condition,
            'condition_type' => $condition,
        ]);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $forecastByCity
     */
    private function generateCalendarForPlot(Plot $plot, Carbon $start, Carbon $end, array $forecastByCity): TaskCalendar
    {
        Http::fake(function ($request) use ($forecastByCity) {
            $url = $request->url();

            if (str_ends_with($url, '/places')) {
                return Http::response($this->buildMeteoPlaces(), 200);
            }

            if (! str_contains($url, '/forecasts/long-term')) {
                throw new \RuntimeException("Unexpected HTTP request [{$url}] while seeding demo account.");
            }

            preg_match('~/places/([^/]+)/forecasts/long-term$~', $url, $matches);
            $placeCode = $matches[1] ?? 'vilnius';
            $city = $this->cityForPlaceCode((string) $placeCode);
            $forecastDays = $forecastByCity[$city] ?? $forecastByCity['Vilnius'] ?? [];

            return Http::response($this->buildMeteoForecast($city, (string) $placeCode, $forecastDays), 200);
        });

        return app(TaskCalendarService::class)->generate(
            $plot->fresh([
                'gardenOwner',
                'plantZones.rotationHistory',
                'plantZones.plants.catalogPlant.plantCare',
                'plantZones.plants.conditionHistory',
                'plantZones.plants.harvestRecords',
            ]),
            $start->copy()->startOfDay(),
            $end->copy()->startOfDay(),
        );
    }

    private function refreshTaskContextsForOwner(GardenOwner $owner): void
    {
        $calendarIds = TaskCalendar::query()
            ->whereHas('plot', fn ($query) => $query->where('garden_owner_id', $owner->id))
            ->pluck('id');

        $tasks = Task::query()
            ->whereIn('task_calendar_id', $calendarIds)
            ->with('requiredResources')
            ->get();

        app(\App\Services\Inventory\InventoryService::class)->attachLiveTaskInventory($owner, $tasks);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function captureSnapshot(
        PlotSnapshotService $plotSnapshotService,
        Plot $plot,
        string $action,
        GardenOwner $owner,
        Carbon $timestamp,
        array $metadata,
    ): void {
        $previousNow = Carbon::getTestNow();
        Carbon::setTestNow($timestamp);

        try {
            $plotSnapshotService->capture(
                $plot->fresh([
                    'plantZones.plants',
                    'plants',
                ]),
                $action,
                $owner,
                $metadata,
            );
        } finally {
            Carbon::setTestNow($previousNow);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rectGeometry(float $x, float $y, float $width, float $height): array
    {
        return [
            'type' => 'polygon',
            'points' => [
                ['x' => $x, 'y' => $y],
                ['x' => $x + $width, 'y' => $y],
                ['x' => $x + $width, 'y' => $y + $height],
                ['x' => $x, 'y' => $y + $height],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMeteoPlaces(): array
    {
        return [
            [
                'code' => 'vilnius',
                'name' => 'Vilnius',
                'administrativeDivision' => 'Vilniaus miesto savivaldybe',
                'countryCode' => 'LT',
                'coordinates' => [
                    'latitude' => 54.6872,
                    'longitude' => 25.2797,
                ],
            ],
            [
                'code' => 'kaunas',
                'name' => 'Kaunas',
                'administrativeDivision' => 'Kauno miesto savivaldybe',
                'countryCode' => 'LT',
                'coordinates' => [
                    'latitude' => 54.8985,
                    'longitude' => 23.9036,
                ],
            ],
            [
                'code' => 'utena',
                'name' => 'Utena',
                'administrativeDivision' => 'Utenos rajono savivaldybe',
                'countryCode' => 'LT',
                'coordinates' => [
                    'latitude' => 55.4976,
                    'longitude' => 25.5992,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $forecastDays
     * @return array<string, mixed>
     */
    private function buildMeteoForecast(string $city, string $placeCode, array $forecastDays): array
    {
        return [
            'place' => [
                'code' => $placeCode,
                'name' => $city,
                'administrativeDivision' => $city.' regionas',
                'country' => 'Lietuva',
                'countryCode' => 'LT',
                'coordinates' => [
                    'latitude' => 54.6872,
                    'longitude' => 25.2797,
                ],
            ],
            'forecastType' => 'long-term',
            'forecastCreationTimeUtc' => now()->toDateTimeString(),
            'forecastTimestamps' => collect($forecastDays)
                ->flatMap(function (array $day): array {
                    $timestamp = Carbon::parse($day['date']);
                    $tempMin = (float) $day['temp_min'];
                    $tempMax = (float) $day['temp_max'];
                    $humidity = (float) ($day['humidity'] ?? 72);
                    $windSpeed = round((float) ($day['wind_kmh'] ?? 0) / 3.6, 3);
                    $condition = (string) ($day['condition'] ?? 'clear');
                    $midTemp = round(($tempMin + $tempMax) / 2, 2);
                    $rain = (float) ($day['rain'] ?? 0);

                    return [
                        [
                            'forecastTimeUtc' => $timestamp->copy()->setTime(6, 0)->toDateTimeString(),
                            'airTemperature' => $tempMin,
                            'relativeHumidity' => $humidity,
                            'totalPrecipitation' => 0,
                            'windSpeed' => $windSpeed,
                            'conditionCode' => $condition,
                        ],
                        [
                            'forecastTimeUtc' => $timestamp->copy()->setTime(12, 0)->toDateTimeString(),
                            'airTemperature' => $midTemp,
                            'relativeHumidity' => $humidity,
                            'totalPrecipitation' => $rain,
                            'windSpeed' => $windSpeed,
                            'conditionCode' => $condition,
                        ],
                        [
                            'forecastTimeUtc' => $timestamp->copy()->setTime(18, 0)->toDateTimeString(),
                            'airTemperature' => $tempMax,
                            'relativeHumidity' => $humidity,
                            'totalPrecipitation' => 0,
                            'windSpeed' => $windSpeed,
                            'conditionCode' => $condition,
                        ],
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    private function cityForPlaceCode(string $placeCode): string
    {
        return match ($placeCode) {
            'kaunas' => 'Kaunas',
            'utena' => 'Utena',
            default => 'Vilnius',
        };
    }
}
