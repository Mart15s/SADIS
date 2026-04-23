<?php

namespace Database\Seeders;

use App\Enums\ConditionType;
use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Enums\TaskPriority;
use App\Enums\TaskState;
use App\Enums\TaskType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CatalogPlant;
use App\Models\CommunityPost;
use App\Models\GardenOwner;
use App\Models\HasInventory;
use App\Models\HasPlot;
use App\Models\InventoryItem;
use App\Models\Plant;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\RotationHistory;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\TaskResourceRequirement;
use App\Models\User;
use App\Models\WeatherForecast;
use App\Services\AccessService;
use App\Services\PlantConditionHistoryService;
use App\Services\TaskCalendarService;
use App\Services\TaskWorkflowService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'DemoGarden2026!';

    private AccessService $accessService;

    private PlantConditionHistoryService $plantConditionHistoryService;

    private TaskCalendarService $taskCalendarService;

    private TaskWorkflowService $taskWorkflowService;

    /** @var array<string, array{user: User, profile: Profile, owner: GardenOwner}> */
    private array $actors = [];

    /** @var array<string, CatalogPlant> */
    private array $catalog = [];

    /** @var array<string, mixed> */
    private array $weatherPlaces = [
        'vilnius' => [
            'code' => 'vilnius',
            'name' => 'Vilnius',
            'administrativeDivision' => 'Vilniaus miesto savivaldybė',
            'country' => 'Lietuva',
            'countryCode' => 'LT',
            'coordinates' => [
                'latitude' => 54.6872,
                'longitude' => 25.2797,
            ],
        ],
        'kaunas' => [
            'code' => 'kaunas',
            'name' => 'Kaunas',
            'administrativeDivision' => 'Kauno miesto savivaldybė',
            'country' => 'Lietuva',
            'countryCode' => 'LT',
            'coordinates' => [
                'latitude' => 54.8985,
                'longitude' => 23.9036,
            ],
        ],
        'trakai' => [
            'code' => 'trakai',
            'name' => 'Trakai',
            'administrativeDivision' => 'Trakų rajono savivaldybė',
            'country' => 'Lietuva',
            'countryCode' => 'LT',
            'coordinates' => [
                'latitude' => 54.6370,
                'longitude' => 24.9341,
            ],
        ],
    ];

    public function run(): void
    {
        $this->accessService = app(AccessService::class);
        $this->plantConditionHistoryService = app(PlantConditionHistoryService::class);
        $this->taskCalendarService = app(TaskCalendarService::class);
        $this->taskWorkflowService = app(TaskWorkflowService::class);

        $this->resetMutableTables();
        $this->call(LocalPlantCatalogSeeder::class);
        $this->catalog = CatalogPlant::query()
            ->with('plantCare')
            ->get()
            ->keyBy(fn (CatalogPlant $plant): string => (string) $plant->name)
            ->all();

        $this->fakeExternalApis();
        Carbon::setTestNow(Carbon::parse('2026-04-22 08:30:00', 'Europe/Vilnius'));

        try {
            $this->actors = $this->createActors();

            $world = $this->createPrimaryGardenWorld();
            $community = $this->createCommunityGardenWorld();

            $this->createSharedAccess($world);
            $this->seedHistoricHarvestBackfill($world, $community);
            $this->seedConditionHistory($world, $community);
            $this->seedHistoricalWorkflowCalendar($world);
            $this->generateLiveCalendars($world);
            $this->seedCommunityPosts($world, $community);
            $this->seedAdminAuditTrail();
        } finally {
            Carbon::setTestNow();
        }
    }

    private function resetMutableTables(): void
    {
        $tables = [
            'access_rights',
            'audit_logs',
            'community_posts',
            'harvest_records',
            'has_inventory',
            'has_plot',
            'inventory_items',
            'inventory_usage_logs',
            'personal_access_tokens',
            'plant_condition_history',
            'plants',
            'plant_zones',
            'plot_snapshots',
            'plots',
            'profiles',
            'rotation_history',
            'rotation_plan_drafts',
            'task_resource_requirements',
            'tasks',
            'task_calendars',
            'used_on',
            'weather_forecasts',
            'garden_owners',
            'users',
            'catalog_plants',
            'plant_care',
        ];

        DB::statement(
            'TRUNCATE TABLE '.collect($tables)
                ->map(fn (string $table): string => "\"{$table}\"")
                ->implode(', ')
                .' RESTART IDENTITY CASCADE'
        );
    }

    private function fakeExternalApis(): void
    {
        Http::preventStrayRequests();

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_ends_with($url, '/places')) {
                return Http::response(array_values($this->weatherPlaces), 200);
            }

            if (preg_match('#/places/([^/]+)/forecasts/long-term$#', $url, $matches) === 1) {
                $placeCode = urldecode($matches[1]);

                return Http::response($this->buildMeteoForecastPayload($placeCode), 200);
            }

            if (str_contains($url, 'perenual.com')) {
                return Http::response([
                    'data' => [],
                ], 200);
            }

            throw new RuntimeException("Unexpected HTTP request during demo seeding [{$url}]");
        });
    }

    /**
     * @return array<string, array{user: User, profile: Profile, owner: GardenOwner}>
     */
    private function createActors(): array
    {
        return [
            'owner' => $this->createActor([
                'email' => 'aiste@demo.sad.lt',
                'role' => UserRole::Owner,
                'name' => 'Aistė',
                'surname' => 'Petrauskaitė',
                'created_at' => '2025-01-18 09:20:00',
                'last_login' => '2026-04-22 07:55:00',
            ]),
            'editor' => $this->createActor([
                'email' => 'mantas@demo.sad.lt',
                'role' => UserRole::Owner,
                'name' => 'Mantas',
                'surname' => 'Petrauskas',
                'created_at' => '2025-02-03 18:10:00',
                'last_login' => '2026-04-21 20:18:00',
            ]),
            'viewer' => $this->createActor([
                'email' => 'rasa@demo.sad.lt',
                'role' => UserRole::Owner,
                'name' => 'Rasa',
                'surname' => 'Petrauskienė',
                'created_at' => '2025-02-11 08:15:00',
                'last_login' => '2026-04-20 19:42:00',
            ]),
            'admin' => $this->createActor([
                'email' => 'admin@demo.sad.lt',
                'role' => UserRole::Admin,
                'name' => 'Indrė',
                'surname' => 'Jankauskienė',
                'created_at' => '2024-11-07 10:00:00',
                'last_login' => '2026-04-22 08:05:00',
            ]),
            'community_owner' => $this->createActor([
                'email' => 'lina@demo.sad.lt',
                'role' => UserRole::Owner,
                'name' => 'Lina',
                'surname' => 'Kazlauskienė',
                'created_at' => '2025-03-14 16:45:00',
                'last_login' => '2026-04-21 18:05:00',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{user: User, profile: Profile, owner: GardenOwner}
     */
    private function createActor(array $attributes): array
    {
        $timestamp = Carbon::parse((string) $attributes['created_at'], 'Europe/Vilnius');
        $lastLogin = Carbon::parse((string) $attributes['last_login'], 'Europe/Vilnius');

        $user = User::query()->create([
            'email' => $attributes['email'],
            'password' => self::DEMO_PASSWORD,
            'role' => $attributes['role'],
            'created_at' => $timestamp,
            'updated_at' => $lastLogin,
        ]);

        $profile = Profile::query()->create([
            'user_id' => $user->id,
            'name' => $attributes['name'],
            'surname' => $attributes['surname'],
            'last_login' => $lastLogin,
        ]);

        $owner = GardenOwner::query()->create([
            'id' => $user->id,
            'user_id' => $user->id,
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        return [
            'user' => $user->fresh(),
            'profile' => $profile->fresh(),
            'owner' => $owner->fresh(['user', 'profile']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createPrimaryGardenWorld(): array
    {
        $owner = $this->actors['owner']['owner'];

        $namuDarzas = $this->createPlot($owner, [
            'name' => 'Namų daržas',
            'city' => 'Vilnius',
            'plot_size' => 126.00,
            'creation_date' => '2025-03-30',
            'description' => 'Pagrindinis šeimos daržas už namo: mišrios lysvės, braškių juosta ir vieta sezoninėms daržovėms.',
            'share' => true,
            'geometry' => $this->rectGeometry(0.06, 0.08, 0.94, 0.92),
        ]);

        $siltnamis = $this->createPlot($owner, [
            'name' => 'Šiltnamis',
            'city' => 'Vilnius',
            'plot_size' => 42.00,
            'creation_date' => '2025-09-12',
            'description' => 'Pavasarinis polikarbonatinis šiltnamis ankstyviems pomidorams, paprikoms ir bazilikams.',
            'share' => false,
            'geometry' => $this->rectGeometry(0.08, 0.10, 0.92, 0.90),
        ]);

        $this->insertPlotSnapshot($namuDarzas, 'plot_created', '2025-03-30 09:30:00', $owner);
        $this->insertPlotSnapshot($siltnamis, 'plot_created', '2025-09-12 11:00:00', $owner);

        $zones = [
            'tomatoes' => $this->createZone($namuDarzas, [
                'name' => 'Pomidorų lysvė',
                'zone_size' => 26.00,
                'soil_type' => SoilType::Peaty,
                'rotation_stage' => 2,
                'last_planting_date' => '2026-03-30',
                'geometry' => $this->rectGeometry(0.08, 0.12, 0.40, 0.44),
            ]),
            'cucumbers' => $this->createZone($namuDarzas, [
                'name' => 'Agurkų lysvė',
                'zone_size' => 24.00,
                'soil_type' => SoilType::Sandy,
                'rotation_stage' => 1,
                'last_planting_date' => '2026-04-10',
                'geometry' => $this->rectGeometry(0.44, 0.12, 0.88, 0.44),
            ]),
            'root' => $this->createZone($namuDarzas, [
                'name' => 'Šakniavaisių kampas',
                'zone_size' => 22.00,
                'soil_type' => SoilType::Clay,
                'rotation_stage' => 3,
                'last_planting_date' => '2026-04-03',
                'geometry' => $this->rectGeometry(0.08, 0.48, 0.38, 0.82),
            ]),
            'herbs' => $this->createZone($namuDarzas, [
                'name' => 'Prieskoninių žolelių juosta',
                'zone_size' => 12.00,
                'soil_type' => SoilType::Peaty,
                'rotation_stage' => 1,
                'last_planting_date' => '2026-04-12',
                'geometry' => $this->rectGeometry(0.42, 0.52, 0.62, 0.82),
            ]),
            'berries' => $this->createZone($namuDarzas, [
                'name' => 'Braškių juosta',
                'zone_size' => 18.00,
                'soil_type' => SoilType::Sandy,
                'rotation_stage' => 4,
                'last_planting_date' => '2025-08-15',
                'geometry' => $this->rectGeometry(0.66, 0.52, 0.88, 0.82),
            ]),
            'greenhouse_left' => $this->createZone($siltnamis, [
                'name' => 'Šiltnamio kairė pusė',
                'zone_size' => 12.00,
                'soil_type' => SoilType::Peaty,
                'rotation_stage' => 5,
                'last_planting_date' => '2026-03-24',
                'geometry' => $this->rectGeometry(0.10, 0.14, 0.42, 0.82),
            ]),
            'greenhouse_right' => $this->createZone($siltnamis, [
                'name' => 'Šiltnamio dešinė pusė',
                'zone_size' => 12.00,
                'soil_type' => SoilType::Peaty,
                'rotation_stage' => 3,
                'last_planting_date' => '2026-03-20',
                'geometry' => $this->rectGeometry(0.46, 0.14, 0.78, 0.82),
            ]),
            'greenhouse_end' => $this->createZone($siltnamis, [
                'name' => 'Agurkų galas',
                'zone_size' => 8.00,
                'soil_type' => SoilType::Sandy,
                'rotation_stage' => 2,
                'last_planting_date' => '2026-04-08',
                'geometry' => $this->rectGeometry(0.80, 0.14, 0.90, 0.82),
            ]),
            'greenhouse_herbs' => $this->createZone($siltnamis, [
                'name' => 'Žolelių lentyna',
                'zone_size' => 4.00,
                'soil_type' => SoilType::Peaty,
                'rotation_stage' => 1,
                'last_planting_date' => '2026-04-05',
                'geometry' => $this->rectGeometry(0.18, 0.04, 0.72, 0.12),
            ]),
        ];

        $this->insertPlotSnapshot($namuDarzas->fresh(['plantZones', 'plants']), 'zone_created', '2026-03-05 10:15:00', $owner);
        $this->insertPlotSnapshot($siltnamis->fresh(['plantZones', 'plants']), 'zone_created', '2026-02-28 09:40:00', $owner);

        $plants = [];

        $plants['tomato_golden'] = $this->createPlant($namuDarzas, $zones['tomatoes'], 'Tomato', [
            'name' => "Pomidoras 'Auksė'",
            'plant_date' => '2026-03-30',
            'condition' => ConditionType::Growing,
            'rest_time_days' => 120,
            'plant_size' => 3.20,
            'recommended_temperature' => 22.00,
            'recommended_humidity' => 62.00,
            'disease_notes' => 'Po vėsesnės savaitės patręštas ir pririštas prie atramos.',
        ]);
        $plants['onion'] = $this->createPlant($namuDarzas, $zones['root'], 'Onion', [
            'name' => 'Svogūnai iš sodinukų',
            'plant_date' => '2026-03-22',
            'condition' => ConditionType::Mature,
            'rest_time_days' => 30,
            'plant_size' => 2.10,
            'recommended_temperature' => 17.00,
            'recommended_humidity' => 55.00,
        ]);
        $plants['parsley'] = $this->createPlant($namuDarzas, $zones['herbs'], 'Parsley', [
            'name' => 'Petražolės',
            'plant_date' => '2026-03-25',
            'condition' => ConditionType::Growing,
            'rest_time_days' => 21,
            'plant_size' => 1.20,
            'recommended_temperature' => 16.00,
            'recommended_humidity' => 68.00,
            'reusable' => true,
        ]);
        $plants['strawberry_1'] = $this->createPlant($namuDarzas, $zones['berries'], 'Strawberry', [
            'name' => "Braškė 'Marmolada' 1",
            'plant_date' => '2025-08-15',
            'condition' => ConditionType::Regenerating,
            'rest_time_days' => 30,
            'plant_size' => 1.80,
            'recommended_temperature' => 18.00,
            'recommended_humidity' => 66.00,
            'reusable' => true,
        ]);
        $plants['strawberry_2'] = $this->createPlant($namuDarzas, $zones['berries'], 'Strawberry', [
            'name' => "Braškė 'Marmolada' 2",
            'plant_date' => '2025-08-15',
            'condition' => ConditionType::Growing,
            'rest_time_days' => 30,
            'plant_size' => 1.80,
            'recommended_temperature' => 18.00,
            'recommended_humidity' => 66.00,
            'reusable' => true,
        ]);

        $this->insertPlotSnapshot($namuDarzas->fresh(['plantZones', 'plants']), 'plant_created', '2026-03-30 18:20:00', $owner, [
            'note' => 'Pirmasis pavasarinis sodinimo etapas.',
        ]);

        $plants['tomato_vilma'] = $this->createPlant($namuDarzas, $zones['tomatoes'], 'Tomato', [
            'name' => "Pomidoras 'Vilma'",
            'plant_date' => '2026-04-06',
            'condition' => ConditionType::Growing,
            'rest_time_days' => 120,
            'plant_size' => 2.70,
            'recommended_temperature' => 22.00,
            'recommended_humidity' => 62.00,
            'disease_notes' => 'Po ankstyvos miltligės požymių toliau stebima lapija.',
        ]);
        $plants['cucumber_main'] = $this->createPlant($namuDarzas, $zones['cucumbers'], 'Cucumber', [
            'name' => "Agurkas 'Mirabelle'",
            'plant_date' => '2026-04-10',
            'condition' => ConditionType::Growing,
            'rest_time_days' => 60,
            'plant_size' => 2.60,
            'recommended_temperature' => 21.00,
            'recommended_humidity' => 72.00,
        ]);
        $plants['cucumber_young'] = $this->createPlant($namuDarzas, $zones['cucumbers'], 'Cucumber', [
            'name' => "Agurkas 'Ponia'",
            'plant_date' => '2026-04-14',
            'condition' => ConditionType::Planted,
            'rest_time_days' => 60,
            'plant_size' => 1.40,
            'recommended_temperature' => 21.00,
            'recommended_humidity' => 72.00,
        ]);
        $plants['carrot'] = $this->createPlant($namuDarzas, $zones['root'], 'Carrot', [
            'name' => "Morka 'Nantes'",
            'plant_date' => '2026-04-03',
            'condition' => ConditionType::Growing,
            'rest_time_days' => 45,
            'plant_size' => 1.70,
            'recommended_temperature' => 16.00,
            'recommended_humidity' => 58.00,
        ]);
        $plants['dill'] = $this->createPlant($namuDarzas, $zones['herbs'], 'Dill', [
            'name' => 'Krapai',
            'plant_date' => '2026-04-12',
            'condition' => ConditionType::Germinating,
            'rest_time_days' => 20,
            'plant_size' => 0.90,
            'recommended_temperature' => 15.00,
            'recommended_humidity' => 62.00,
        ]);
        $plants['lettuce'] = $this->createPlant($namuDarzas, $zones['herbs'], 'Lettuce', [
            'name' => "Salota 'Lollo Bionda'",
            'plant_date' => '2026-01-20',
            'condition' => ConditionType::Mature,
            'rest_time_days' => 30,
            'plant_size' => 1.10,
            'recommended_temperature' => 15.00,
            'recommended_humidity' => 65.00,
        ]);

        $plants['greenhouse_tomato'] = $this->createPlant($siltnamis, $zones['greenhouse_left'], 'Tomato', [
            'name' => "Šiltnamio pomidoras 'Jurgiai'",
            'plant_date' => '2026-03-24',
            'condition' => ConditionType::Flowering,
            'rest_time_days' => 120,
            'plant_size' => 3.60,
            'recommended_temperature' => 23.00,
            'recommended_humidity' => 64.00,
        ]);
        $plants['greenhouse_tomato_2'] = $this->createPlant($siltnamis, $zones['greenhouse_left'], 'Tomato', [
            'name' => "Šiltnamio pomidoras 'Sakura'",
            'plant_date' => '2026-03-28',
            'condition' => ConditionType::Growing,
            'rest_time_days' => 120,
            'plant_size' => 3.10,
            'recommended_temperature' => 23.00,
            'recommended_humidity' => 64.00,
        ]);
        $plants['pepper'] = $this->createPlant($siltnamis, $zones['greenhouse_right'], 'Bell Pepper', [
            'name' => "Paprika 'California Wonder'",
            'plant_date' => '2026-03-24',
            'condition' => ConditionType::Growing,
            'rest_time_days' => 90,
            'plant_size' => 2.20,
            'recommended_temperature' => 24.00,
            'recommended_humidity' => 60.00,
        ]);
        $plants['greenhouse_cucumber'] = $this->createPlant($siltnamis, $zones['greenhouse_end'], 'Cucumber', [
            'name' => "Šiltnamio agurkas 'Meringue'",
            'plant_date' => '2026-04-08',
            'condition' => ConditionType::Growing,
            'rest_time_days' => 60,
            'plant_size' => 2.40,
            'recommended_temperature' => 22.00,
            'recommended_humidity' => 72.00,
        ]);
        $plants['basil'] = $this->createPlant($siltnamis, $zones['greenhouse_herbs'], 'Basil', [
            'name' => 'Bazilikas',
            'plant_date' => '2026-04-05',
            'condition' => ConditionType::Planted,
            'rest_time_days' => 14,
            'plant_size' => 0.80,
            'recommended_temperature' => 21.00,
            'recommended_humidity' => 67.00,
        ]);

        $this->insertPlotSnapshot($namuDarzas->fresh(['plantZones', 'plants']), 'plant_created', '2026-04-10 18:40:00', $owner, [
            'note' => 'Antras sodinimo etapas prieš Velykų savaitę.',
        ]);
        $this->insertPlotSnapshot($siltnamis->fresh(['plantZones', 'plants']), 'plant_created', '2026-03-29 17:10:00', $owner, [
            'note' => 'Pagrindinis šiltnamio užpildymas po dirvos paruošimo.',
        ]);

        $inventory = [
            'fertilizer' => $this->createInventoryItem($owner, [
                'name' => 'Fertilizer',
                'quantity' => 1.40,
                'type' => InventoryItemType::Material,
                'unit' => InventoryUnit::Kilogram,
            ]),
            'fungicide' => $this->createInventoryItem($owner, [
                'name' => 'Fungicide',
                'quantity' => 1.40,
                'type' => InventoryItemType::Material,
                'unit' => InventoryUnit::Liter,
            ]),
            'sprayer' => $this->createInventoryItem($owner, [
                'name' => 'Sprayer',
                'quantity' => 1,
                'type' => InventoryItemType::Tool,
                'unit' => InventoryUnit::Unit,
            ]),
            'protective_cover' => $this->createInventoryItem($owner, [
                'name' => 'Protective cover',
                'quantity' => 1,
                'type' => InventoryItemType::Tool,
                'unit' => InventoryUnit::Unit,
            ]),
            'plant_support' => $this->createInventoryItem($owner, [
                'name' => 'Plant support',
                'quantity' => 6,
                'type' => InventoryItemType::Tool,
                'unit' => InventoryUnit::Unit,
            ]),
            'harvest_box' => $this->createInventoryItem($owner, [
                'name' => 'Harvest box',
                'quantity' => 2,
                'type' => InventoryItemType::Tool,
                'unit' => InventoryUnit::Unit,
            ]),
            'seedling_tray' => $this->createInventoryItem($owner, [
                'name' => 'Daigyklos padėklas',
                'quantity' => 3,
                'type' => InventoryItemType::Tool,
                'unit' => InventoryUnit::Unit,
            ]),
            'compost' => $this->createInventoryItem($owner, [
                'name' => 'Kompostas',
                'quantity' => 0.60,
                'type' => InventoryItemType::Material,
                'unit' => InventoryUnit::CubicMeter,
            ]),
        ];

        $this->createCurrentRotationRecords($namuDarzas, $plants, [
            'tomato_golden',
            'tomato_vilma',
            'cucumber_main',
            'cucumber_young',
            'carrot',
            'onion',
            'parsley',
            'dill',
            'lettuce',
            'strawberry_1',
            'strawberry_2',
        ]);
        $this->createCurrentRotationRecords($siltnamis, $plants, [
            'greenhouse_tomato',
            'greenhouse_tomato_2',
            'pepper',
            'greenhouse_cucumber',
            'basil',
        ]);
        $this->createHistoricalRotationRecord($siltnamis, $zones['greenhouse_left'], $plants['greenhouse_tomato'], '2025-05-03', '2025-09-28');
        $this->createHistoricalRotationRecord($namuDarzas, $zones['tomatoes'], $plants['tomato_golden'], '2025-06-01', '2025-09-15');

        return [
            'plots' => [
                'namu_darzas' => $namuDarzas,
                'siltnamis' => $siltnamis,
            ],
            'zones' => $zones,
            'plants' => $plants,
            'inventory' => $inventory,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createCommunityGardenWorld(): array
    {
        $owner = $this->actors['community_owner']['owner'];

        $plot = $this->createPlot($owner, [
            'name' => 'Uogų kampas',
            'city' => 'Trakai',
            'plot_size' => 36.00,
            'creation_date' => '2024-08-20',
            'description' => 'Mažas savaitgalio sklypas prie terasos su braškėmis, mėtomis ir viena obelimi.',
            'share' => true,
            'geometry' => $this->rectGeometry(0.10, 0.10, 0.90, 0.90),
        ]);

        $zones = [
            'berries' => $this->createZone($plot, [
                'name' => 'Braškių kampas',
                'zone_size' => 16.00,
                'soil_type' => SoilType::Sandy,
                'rotation_stage' => 3,
                'last_planting_date' => '2025-08-20',
                'geometry' => $this->rectGeometry(0.12, 0.16, 0.54, 0.84),
            ]),
            'apple' => $this->createZone($plot, [
                'name' => 'Obels vieta',
                'zone_size' => 10.00,
                'soil_type' => SoilType::Clay,
                'rotation_stage' => 6,
                'last_planting_date' => '2024-08-20',
                'geometry' => $this->rectGeometry(0.58, 0.20, 0.84, 0.54),
            ]),
            'mint' => $this->createZone($plot, [
                'name' => 'Mėtų kraštas',
                'zone_size' => 6.00,
                'soil_type' => SoilType::Peaty,
                'rotation_stage' => 2,
                'last_planting_date' => '2025-05-01',
                'geometry' => $this->rectGeometry(0.60, 0.60, 0.86, 0.84),
            ]),
        ];

        $plants = [
            'strawberry' => $this->createPlant($plot, $zones['berries'], 'Strawberry', [
                'name' => "Braškė 'Asia'",
                'plant_date' => '2025-08-20',
                'condition' => ConditionType::Growing,
                'rest_time_days' => 30,
                'plant_size' => 1.90,
                'recommended_temperature' => 18.00,
                'recommended_humidity' => 66.00,
                'reusable' => true,
            ]),
            'apple_tree' => $this->createPlant($plot, $zones['apple'], 'Apple Tree', [
                'name' => 'Obelis ant M9 poskiepio',
                'plant_date' => '2024-08-20',
                'condition' => ConditionType::Diseased,
                'rest_time_days' => 180,
                'plant_size' => 4.20,
                'recommended_temperature' => 17.00,
                'recommended_humidity' => 60.00,
                'disease' => true,
                'disease_notes' => 'Po lietingos savaitės atsirado pirmi rauplių požymiai.',
                'reusable' => true,
            ]),
            'mint' => $this->createPlant($plot, $zones['mint'], 'Mint', [
                'name' => 'Mėta šaltai arbatai',
                'plant_date' => '2025-05-01',
                'condition' => ConditionType::Regenerating,
                'rest_time_days' => 21,
                'plant_size' => 1.10,
                'recommended_temperature' => 17.00,
                'recommended_humidity' => 70.00,
                'reusable' => true,
            ]),
        ];

        return [
            'plot' => $plot,
            'zones' => $zones,
            'plants' => $plants,
        ];
    }

    private function createSharedAccess(array $world): void
    {
        $owner = $this->actors['owner']['owner'];
        $editor = $this->actors['editor']['owner'];
        $viewer = $this->actors['viewer']['owner'];
        $namuDarzas = $world['plots']['namu_darzas'];
        $siltnamis = $world['plots']['siltnamis'];

        $accessEditorMain = $this->accessService->sharePlot($owner, $namuDarzas, $editor, 'editor');
        $accessEditorGreenhouse = $this->accessService->sharePlot($owner, $siltnamis, $editor, 'editor');
        $accessViewerMain = $this->accessService->sharePlot($owner, $namuDarzas, $viewer, 'viewer');

        $accessEditorMain->forceFill([
            'granted_at' => Carbon::parse('2026-04-18 18:05:00', 'Europe/Vilnius'),
        ])->saveQuietly();
        $accessEditorGreenhouse->forceFill([
            'granted_at' => Carbon::parse('2026-04-18 18:10:00', 'Europe/Vilnius'),
        ])->saveQuietly();
        $accessViewerMain->forceFill([
            'granted_at' => Carbon::parse('2026-04-19 10:25:00', 'Europe/Vilnius'),
        ])->saveQuietly();

        $this->insertPlotSnapshot($namuDarzas->fresh(['plantZones', 'plants']), 'plot_access_granted', '2026-04-18 18:12:00', $owner, [
            'shared_with' => [
                $this->actors['editor']['user']->email,
                $this->actors['viewer']['user']->email,
            ],
        ]);
    }

    private function seedHistoricHarvestBackfill(array $world, array $community): void
    {
        DB::table('harvest_records')->insert([
            [
                'plot_id' => $world['plots']['namu_darzas']->id,
                'plant_id' => $world['plants']['strawberry_1']->id,
                'task_id' => null,
                'garden_owner_id' => $this->actors['owner']['owner']->id,
                'quantity' => 1.25,
                'harvested_on' => '2025-06-18',
                'notes' => 'Pirmas pernykštis braškių rinkimas po lietaus.',
                'created_at' => '2025-06-18 19:10:00',
                'updated_at' => '2025-06-18 19:10:00',
            ],
            [
                'plot_id' => $world['plots']['namu_darzas']->id,
                'plant_id' => $world['plants']['strawberry_2']->id,
                'task_id' => null,
                'garden_owner_id' => $this->actors['owner']['owner']->id,
                'quantity' => 1.05,
                'harvested_on' => '2025-06-25',
                'notes' => 'Antra banga prieš savaitgalio uogienei.',
                'created_at' => '2025-06-25 20:05:00',
                'updated_at' => '2025-06-25 20:05:00',
            ],
            [
                'plot_id' => $community['plot']->id,
                'plant_id' => $community['plants']['strawberry']->id,
                'task_id' => null,
                'garden_owner_id' => $this->actors['community_owner']['owner']->id,
                'quantity' => 0.95,
                'harvested_on' => '2025-06-22',
                'notes' => 'Linos sklypo braškės pirmam desertui.',
                'created_at' => '2025-06-22 17:45:00',
                'updated_at' => '2025-06-22 17:45:00',
            ],
        ]);
    }

    private function seedConditionHistory(array $world, array $community): void
    {
        $this->recordCondition($world['plants']['tomato_golden'], '2026-03-30 18:30:00', ConditionType::Planted, 'Pasodintas ir palaistytas vakarop.');
        $this->recordCondition($world['plants']['tomato_golden'], '2026-04-06 11:20:00', ConditionType::Growing, 'Po pirmo patręšimo jau leidžia naujus lapus.');

        $this->recordCondition($world['plants']['tomato_vilma'], '2026-04-06 19:10:00', ConditionType::Planted, 'Pasodintas vėliau į atsilaisvinusią vietą lysvėje.');
        $this->recordCondition($world['plants']['tomato_vilma'], '2026-04-17 08:50:00', ConditionType::Growing, 'Augimas tolygus, po lietaus lapija sausa.');

        $this->recordCondition($world['plants']['cucumber_main'], '2026-04-10 18:00:00', ConditionType::Planted, 'Persodintas į lysvę po atšilimo.');
        $this->recordCondition($world['plants']['cucumber_main'], '2026-04-15 07:40:00', ConditionType::Diseased, 'Ant kelių lapų pastebėtos dėmės po šalto lietaus.');

        $this->recordCondition($world['plants']['cucumber_young'], '2026-04-14 18:20:00', ConditionType::Planted, 'Jauniausias sodinukas dar tik pratinamas prie lauko.');

        $this->recordCondition($world['plants']['onion'], '2026-03-24 08:15:00', ConditionType::Growing, 'Svogūnai gerai prigijo po mulčiavimo.');
        $this->recordCondition($world['plants']['onion'], '2026-04-18 09:00:00', ConditionType::Mature, 'Lapai jau gula, galima planuoti pirmą rinkimą.');

        $this->recordCondition($world['plants']['parsley'], '2026-03-28 12:10:00', ConditionType::Growing, 'Lapija sutankėjo po švelnaus pavasario savaitės.');
        $this->recordCondition($world['plants']['dill'], '2026-04-16 07:55:00', ConditionType::Germinating, 'Pasirodė pirmi daigeliai palei žymėjimo virvę.');

        $this->recordCondition($world['plants']['lettuce'], '2026-04-07 09:25:00', ConditionType::Mature, 'Galvos jau tvirtos, tinkamos skinti demonstracijai.');

        $this->recordCondition($world['plants']['strawberry_1'], '2026-04-18 14:15:00', ConditionType::Regenerating, 'Po pernykščio derliaus atsigauna nauja lapija.');
        $this->recordCondition($world['plants']['strawberry_2'], '2026-04-18 14:20:00', ConditionType::Growing, 'Antroji eilė pradeda krauti žiedynus.');

        $this->recordCondition($world['plants']['greenhouse_tomato'], '2026-03-28 16:00:00', ConditionType::Growing, 'Po šiltos savaitės stiebas sutvirtėjo.');
        $this->recordCondition($world['plants']['greenhouse_tomato'], '2026-04-18 09:45:00', ConditionType::Flowering, 'Pasirodė pirmi žiedai ant antros kekės.');

        $this->recordCondition($world['plants']['greenhouse_tomato_2'], '2026-04-16 09:10:00', ConditionType::Growing, 'Šoninės atžalos dar trumpinamos kartą per savaitę.');
        $this->recordCondition($world['plants']['pepper'], '2026-04-14 10:30:00', ConditionType::Growing, 'Paprika auga lėčiau, bet lapai tvirti.');
        $this->recordCondition($world['plants']['greenhouse_cucumber'], '2026-04-16 12:00:00', ConditionType::Growing, 'Pirmi ūseliai jau lipa atrama.');

        $this->recordCondition($world['plants']['basil'], '2026-04-05 18:15:00', ConditionType::Planted, 'Naujas bazilikas dar laikomas arčiau šilumos.');

        $this->recordCondition($community['plants']['strawberry'], '2026-04-18 11:15:00', ConditionType::Growing, 'Linos braškės po žiemos atrodo tvarkingai.');
        $this->recordCondition($community['plants']['apple_tree'], '2026-04-20 08:10:00', ConditionType::Diseased, 'Po drėgnos savaitės reikia stebėti rauples.', null, true);
        $this->recordCondition($community['plants']['mint'], '2026-04-19 09:30:00', ConditionType::Regenerating, 'Mėta greitai atsinaujina po pirmo pjovimo.');
    }

    private function seedHistoricalWorkflowCalendar(array $world): void
    {
        $calendar = TaskCalendar::query()->create([
            'creation_date' => '2026-04-04 18:40:00',
            'start_date' => '2026-04-05',
            'end_date' => '2026-04-21',
            'plot_id' => $world['plots']['namu_darzas']->id,
            'fk_plot_id' => $world['plots']['namu_darzas']->id,
        ]);

        $this->attachWeatherToCalendar($calendar, 'vilnius');

        $fertilizeTask = $this->createTaskWithRequirements($calendar, $world['plants']['tomato_golden'], [
            'date' => '2026-04-05',
            'name' => "Patręšti pomidorą 'Auksė'",
            'type' => TaskType::Fertilize,
            'priority' => TaskPriority::Medium,
            'reason' => 'Po šaknijimosi pomidorui reikėjo pirmo papildomo maitinimo.',
            'comment' => 'Sąmoningai palikta istorijoje, kad matytųsi sunaudotų medžiagų pėdsakas.',
            'requirements' => [
                $this->requirement('Fertilizer', InventoryItemType::Material, InventoryUnit::Kilogram, 1, true),
            ],
        ]);

        $reviewTask = $this->createTaskWithRequirements($calendar, $world['plants']['basil'], [
            'date' => '2026-04-08',
            'name' => 'Peržiūrėti baziliko būklę',
            'type' => TaskType::Rest,
            'priority' => TaskPriority::Medium,
            'reason' => 'Po persodinimo reikėjo patvirtinti, kad bazilikas pradėjo aktyviai augti.',
            'workflow_context' => [
                'kind' => 'lifecycle_review',
                'review' => [
                    'current_condition' => ConditionType::Planted->value,
                    'from_phase' => ConditionType::Planted->value,
                    'target_condition' => ConditionType::Growing->value,
                    'expected_on' => '2026-04-08',
                    'is_overdue' => false,
                ],
            ],
        ]);

        $harvestTask = $this->createTaskWithRequirements($calendar, $world['plants']['lettuce'], [
            'date' => '2026-04-11',
            'name' => "Nuimti derlių iš salotos 'Lollo Bionda'",
            'type' => TaskType::Harvest,
            'priority' => TaskPriority::High,
            'reason' => 'Ankstyvoji salota subrendo demonstraciniam skynimui.',
            'workflow_context' => [
                'kind' => 'harvest',
                'harvest' => [
                    'expected_on' => '2026-04-11',
                    'is_overdue' => false,
                    'post_harvest_condition' => ConditionType::Dried->value,
                ],
            ],
            'requirements' => [
                $this->requirement('Harvest box', InventoryItemType::Tool, InventoryUnit::Unit, 1, false),
            ],
        ]);

        $sprayTask = $this->createTaskWithRequirements($calendar, $world['plants']['cucumber_main'], [
            'date' => '2026-04-18',
            'name' => "Nupurkšti agurką 'Mirabelle'",
            'type' => TaskType::Spray,
            'priority' => TaskPriority::High,
            'reason' => 'Po staigaus atšalimo reikėjo sustabdyti dėmėtumo plitimą.',
            'requirements' => [
                $this->requirement('Fungicide', InventoryItemType::Material, InventoryUnit::Liter, 1, true),
                $this->requirement('Sprayer', InventoryItemType::Tool, InventoryUnit::Unit, 1, false),
            ],
        ]);

        $canceledTask = $this->createTaskWithRequirements($calendar, $world['plants']['strawberry_2'], [
            'date' => '2026-04-19',
            'name' => "Papildomai ravėti braškę 'Marmolada' 2",
            'type' => TaskType::Rest,
            'priority' => TaskPriority::Low,
            'reason' => 'Planuotas kosmetinis lysvės aptvarkymas prieš savaitgalį.',
            'comment' => 'Užduotis palikta kaip atšaukta demonstracijai.',
        ]);

        $overdueProtectionTask = $this->createTaskWithRequirements($calendar, $world['plants']['onion'], [
            'date' => '2026-04-21',
            'name' => 'Paruošti apsauginę dangą svogūnams',
            'type' => TaskType::Rest,
            'priority' => TaskPriority::Medium,
            'reason' => 'Sinoptikai rodė naktinę šalną ir vėją.',
            'requirements' => [
                $this->requirement('Protective cover', InventoryItemType::Tool, InventoryUnit::Unit, 2, false),
            ],
        ]);

        $buyTask = $this->createTaskWithRequirements($calendar, null, [
            'date' => '2026-04-21',
            'name' => 'Nupirkti Protective cover',
            'type' => TaskType::Buy,
            'priority' => TaskPriority::High,
            'reason' => 'Vienos turimos dangos neužtenka visai svogūnų eilei.',
            'comment' => 'Palikta atvira, kad dabartinis kalendorius nepasiūlytų dublikato tai pačiai dangai.',
            'item' => 'Protective cover',
            'item_quantity' => 1,
            'requirements' => [
                $this->requirement('Protective cover', InventoryItemType::Tool, InventoryUnit::Unit, 1, false),
            ],
        ]);

        $this->taskWorkflowService->complete($fertilizeTask);
        $this->taskWorkflowService->complete($reviewTask, null, [
            'action' => 'confirm',
            'measured_at' => '2026-04-08',
            'notes' => 'Bazilikas sėkmingai perėjo į aktyvaus augimo fazę.',
        ]);
        $this->taskWorkflowService->complete($harvestTask, null, null, [
            'quantity' => 1.40,
            'harvested_on' => '2026-04-11',
            'notes' => 'Nupjauta demonstracinei vakarienei.',
        ]);
        $this->taskWorkflowService->complete($sprayTask);
        $this->taskWorkflowService->reject($canceledTask, 'Po lietaus ravėjimas perkeltas į kitą savaitę.');

        $this->recordCondition($world['plants']['cucumber_main'], '2026-04-19 10:10:00', ConditionType::Growing, 'Po purškimo lapų būklė stabilizavosi.');
        $this->recordCondition($world['plants']['basil'], '2026-04-12 09:00:00', ConditionType::Growing, 'Bazilikas prigijo ir išleido pirmą tankesnę lapiją.');

        $this->insertPlotSnapshot($world['plots']['namu_darzas']->fresh(['plantZones', 'plants']), 'plant_updated', '2026-04-20 19:05:00', $this->actors['owner']['owner'], [
            'note' => 'Po savaitgalio atnaujinta augalų būklė ir užfiksuoti darbų rezultatai.',
            'task_ids' => [
                $fertilizeTask->id,
                $reviewTask->id,
                $harvestTask->id,
                $sprayTask->id,
                $canceledTask->id,
                $overdueProtectionTask->id,
                $buyTask->id,
            ],
        ]);
    }

    private function generateLiveCalendars(array $world): void
    {
        $namuDarzasCalendar = $this->taskCalendarService->generate(
            $world['plots']['namu_darzas']->fresh(),
            Carbon::parse('2026-04-22', 'Europe/Vilnius')->startOfDay(),
            Carbon::parse('2026-05-05', 'Europe/Vilnius')->startOfDay(),
        );

        $siltnamisCalendar = $this->taskCalendarService->generate(
            $world['plots']['siltnamis']->fresh(),
            Carbon::parse('2026-04-22', 'Europe/Vilnius')->startOfDay(),
            Carbon::parse('2026-05-02', 'Europe/Vilnius')->startOfDay(),
        );

        TaskCalendar::query()
            ->whereKey($namuDarzasCalendar->id)
            ->update([
                'creation_date' => Carbon::parse('2026-04-22 08:35:00', 'Europe/Vilnius'),
            ]);

        TaskCalendar::query()
            ->whereKey($siltnamisCalendar->id)
            ->update([
                'creation_date' => Carbon::parse('2026-04-22 08:40:00', 'Europe/Vilnius'),
            ]);
    }

    private function seedCommunityPosts(array $world, array $community): void
    {
        $owner = $this->actors['owner']['owner'];
        $ownerProfile = $this->actors['owner']['profile'];
        $communityOwner = $this->actors['community_owner']['owner'];
        $communityProfile = $this->actors['community_owner']['profile'];

        CommunityPost::query()->create([
            'garden_owner_id' => $owner->id,
            'plot_id' => $world['plots']['namu_darzas']->id,
            'name' => 'Braškių juosta atsigavo po šalnų',
            'text' => 'Po savaitės su vėsiomis naktimis palikau šiaudų mulčią, o šiandien braškės vėl atrodo gyvos. Patogu parodyti ir žemėlapyje, ir kalendoriuje.',
            'share' => true,
            'created_at' => Carbon::parse('2026-04-20 20:15:00', 'Europe/Vilnius'),
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $ownerProfile->id,
            'fk_plot_id' => $world['plots']['namu_darzas']->id,
        ]);

        CommunityPost::query()->create([
            'garden_owner_id' => $owner->id,
            'plot_id' => $world['plots']['siltnamis']->id,
            'name' => 'Pirmi žiedai šiltnamyje',
            'text' => 'Šiltnamio kairėje pusėje pomidorai jau pražydo, todėl demonstracijoje patogu parodyti ir augalų istoriją, ir ateinančius darbus kalendoriuje.',
            'share' => true,
            'created_at' => Carbon::parse('2026-04-21 18:40:00', 'Europe/Vilnius'),
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $ownerProfile->id,
            'fk_plot_id' => $world['plots']['siltnamis']->id,
        ]);

        // Paliekamas vienas privatus įrašas, kad šeimininko bendruomenės vaizdas būtų ne tik viešas.
        CommunityPost::query()->create([
            'garden_owner_id' => $owner->id,
            'plot_id' => $world['plots']['namu_darzas']->id,
            'name' => 'Privati pastaba dėl fungicido',
            'text' => 'Prieš savaitgalio demonstraciją reikėtų papildyti fungicido atsargas ir dar kartą peržiūrėti agurkų lapus.',
            'share' => false,
            'created_at' => Carbon::parse('2026-04-22 07:15:00', 'Europe/Vilnius'),
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $ownerProfile->id,
            'fk_plot_id' => $world['plots']['namu_darzas']->id,
        ]);

        CommunityPost::query()->create([
            'garden_owner_id' => $communityOwner->id,
            'plot_id' => $community['plot']->id,
            'name' => 'Braškės po šiaudais peržiemojo puikiai',
            'text' => 'Linos uogų kampe braškės po lengvu mulčiu išsaugojo drėgmę geriau nei pernai, todėl šįkart derliaus tikimasi tolygesnio.',
            'share' => true,
            'created_at' => Carbon::parse('2026-04-19 17:30:00', 'Europe/Vilnius'),
            'fk_owner_id' => $communityOwner->id_user,
            'fk_profile_id' => $communityProfile->id,
            'fk_plot_id' => $community['plot']->id,
        ]);
    }

    private function seedAdminAuditTrail(): void
    {
        $admin = $this->actors['admin']['user'];
        $editor = $this->actors['editor']['user'];
        $communityUser = $this->actors['community_owner']['user'];

        AuditLog::query()->create([
            'admin_user_id' => $admin->id,
            'action' => 'role_changed',
            'target_user_id' => $communityUser->id,
            'context' => [
                'from' => UserRole::Owner->value,
                'to' => UserRole::Owner->value,
                'note' => 'Demonstracinis audito įrašas administravimo ekranui.',
            ],
            'created_at' => Carbon::parse('2026-04-18 09:00:00', 'Europe/Vilnius'),
        ]);

        AuditLog::query()->create([
            'admin_user_id' => $admin->id,
            'action' => 'role_changed',
            'target_user_id' => $editor->id,
            'context' => [
                'from' => UserRole::Owner->value,
                'to' => UserRole::Owner->value,
                'note' => 'Patikrintos bendradarbio teisės prieš demonstraciją.',
            ],
            'created_at' => Carbon::parse('2026-04-21 08:30:00', 'Europe/Vilnius'),
        ]);
    }

    private function createPlot(GardenOwner $owner, array $attributes): Plot
    {
        $plot = Plot::query()->create([
            'garden_owner_id' => $owner->id,
            'name' => $attributes['name'],
            'city' => $attributes['city'],
            'plot_size' => $attributes['plot_size'],
            'creation_date' => $attributes['creation_date'],
            'description' => $attributes['description'],
            'share' => $attributes['share'],
            'geometry' => $attributes['geometry'],
        ]);

        HasPlot::query()->create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return $plot->fresh();
    }

    private function createZone(Plot $plot, array $attributes): PlantZone
    {
        return PlantZone::query()->create([
            'plot_id' => $plot->id,
            'fk_plot_id' => $plot->id,
            'name' => $attributes['name'],
            'zone_size' => $attributes['zone_size'],
            'soil_type' => $attributes['soil_type'],
            'rotation_stage' => $attributes['rotation_stage'],
            'last_planting_date' => $attributes['last_planting_date'],
            'geometry' => $attributes['geometry'],
        ])->fresh();
    }

    private function createPlant(Plot $plot, PlantZone $zone, string $catalogName, array $attributes): Plant
    {
        $catalogPlant = $this->catalog[$catalogName] ?? null;

        if (! $catalogPlant) {
            throw new RuntimeException("Catalog plant [{$catalogName}] is missing for demo data.");
        }

        return Plant::query()->create([
            'name' => $attributes['name'],
            'growing_time_days' => (int) ($catalogPlant->plantCare?->growing_duration_days ?? 0),
            'recommended_temperature' => $attributes['recommended_temperature'] ?? 20,
            'recommended_humidity' => $attributes['recommended_humidity'] ?? 65,
            'plant_date' => $attributes['plant_date'],
            'disease_notes' => $attributes['disease_notes'] ?? null,
            'disease' => (bool) ($attributes['disease'] ?? false),
            'rest_time_days' => $attributes['rest_time_days'] ?? 30,
            'plant_size' => $attributes['plant_size'] ?? 1,
            'photo_url' => null,
            'reusable' => (bool) ($attributes['reusable'] ?? ($catalogPlant->plantCare?->reusable ?? false)),
            'type' => $attributes['type'] ?? ($catalogPlant->plant_type?->value ?? PlantType::Vegetable->value),
            'condition' => $attributes['condition'] ?? ConditionType::Planted,
            'fk_catalog_plant_id' => $catalogPlant->id,
            'plant_zone_id' => $zone->id,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
        ])->fresh(['plot', 'plantZone', 'catalogPlant.plantCare']);
    }

    private function createInventoryItem(GardenOwner $owner, array $attributes): InventoryItem
    {
        $item = InventoryItem::query()->create([
            'garden_owner_id' => $owner->id,
            'name' => $attributes['name'],
            'quantity' => $attributes['quantity'],
            'type' => $attributes['type'],
            'inventory_item_type' => $attributes['type'],
            'unit' => $attributes['unit'],
        ]);

        HasInventory::query()->create([
            'fk_inventory_item_id' => $item->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return $item->fresh();
    }

    private function recordCondition(
        Plant $plant,
        string $measuredAt,
        ConditionType $condition,
        ?string $notes = null,
        ?string $photoUrl = null,
        ?bool $disease = null,
    ): void {
        $this->plantConditionHistoryService->record(
            $plant,
            $condition,
            Carbon::parse($measuredAt, 'Europe/Vilnius'),
            $notes,
            $photoUrl,
            $disease,
        );
    }

    private function createCurrentRotationRecords(Plot $plot, array $plants, array $keys): void
    {
        foreach ($keys as $key) {
            $plant = $plants[$key];
            $zoneId = (int) ($plant->plant_zone_id ?? $plant->fk_plant_zone_id);

            RotationHistory::query()->create([
                'plant_zone_id' => $zoneId,
                'fk_plant_zone_id' => $zoneId,
                'from_date' => $plant->plant_date,
                'to_date' => null,
                'fk_plot_id' => $plot->id,
                'fk_plot_via_zone' => $plot->id,
                'fk_plant_id' => $plant->id,
            ]);
        }
    }

    private function createHistoricalRotationRecord(
        Plot $plot,
        PlantZone $zone,
        Plant $plant,
        string $fromDate,
        string $toDate
    ): void {
        RotationHistory::query()->create([
            'plant_zone_id' => $zone->id,
            'fk_plant_zone_id' => $zone->id,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'fk_plot_id' => $plot->id,
            'fk_plot_via_zone' => $plot->id,
            'fk_plant_id' => $plant->id,
        ]);
    }

    private function insertPlotSnapshot(
        Plot $plot,
        string $action,
        string $createdAt,
        ?GardenOwner $owner,
        array $metadata = []
    ): void {
        $plot->loadMissing([
            'plantZones.plants',
            'plants',
        ]);

        DB::table('plot_snapshots')->insert([
            'plot_id' => $plot->id,
            'garden_owner_id' => $owner?->id,
            'action' => $action,
            'snapshot' => json_encode([
                'plot' => $plot->toArray(),
                'zones' => $plot->plantZones->toArray(),
                'plants' => $plot->plants->toArray(),
                'metadata' => $metadata,
            ], JSON_THROW_ON_ERROR),
            'created_at' => Carbon::parse($createdAt, 'Europe/Vilnius'),
        ]);
    }

    private function attachWeatherToCalendar(TaskCalendar $calendar, string $placeCode): void
    {
        $startDate = Carbon::parse($calendar->start_date)->startOfDay();
        $endDate = Carbon::parse($calendar->end_date)->startOfDay();

        foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
            $profile = $this->forecastProfile($placeCode, $date->toDateString());

            WeatherForecast::query()->create([
                'date' => $date->toDateString(),
                'temperature' => round(($profile['temp_min'] + $profile['temp_max']) / 2, 2),
                'temp_min' => $profile['temp_min'],
                'temp_max' => $profile['temp_max'],
                'precipitation' => $profile['rain'],
                'humidity' => $profile['humidity'],
                'wind_kmh' => $profile['wind_kmh'],
                'condition_code' => $profile['condition'],
                'is_seasonal_fallback' => false,
                'source' => 'api',
                'source_date' => $date->toDateString(),
                'source_city' => $this->weatherPlaces[$placeCode]['name'] ?? ucfirst($placeCode),
                'city' => $this->weatherPlaces[$placeCode]['name'] ?? ucfirst($placeCode),
                'task_calendar_id' => $calendar->id,
                'fk_task_calendar_id' => $calendar->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function createTaskWithRequirements(TaskCalendar $calendar, ?Plant $plant, array $definition): Task
    {
        $zoneId = $plant?->plant_zone_id ?? $plant?->fk_plant_zone_id;

        $task = Task::query()->create([
            'date' => $definition['date'],
            'name' => $definition['name'],
            'task_type' => ($definition['type'] instanceof TaskType ? $definition['type']->value : $definition['type']),
            'type' => ($definition['type'] instanceof TaskType ? $definition['type']->value : $definition['type']),
            'priority' => ($definition['priority'] ?? TaskPriority::Medium) instanceof TaskPriority
                ? $definition['priority']->value
                : ($definition['priority'] ?? TaskPriority::Medium->value),
            'reason' => $definition['reason'] ?? null,
            'comment' => $definition['comment'] ?? null,
            'item' => $definition['item'] ?? ($definition['requirements'][0]['resource_name'] ?? null),
            'item_quantity' => $definition['item_quantity'] ?? ($definition['requirements'][0]['required_quantity'] ?? null),
            'weather_context' => $definition['weather_context'] ?? null,
            'inventory_context' => $definition['inventory_context'] ?? null,
            'simulated_state' => $definition['simulated_state'] ?? null,
            'workflow_context' => $definition['workflow_context'] ?? null,
            'state' => TaskState::Pending,
            'status' => TaskState::Pending->value,
            'task_calendar_id' => $calendar->id,
            'fk_task_calendar_id' => $calendar->id,
            'plant_id' => $plant?->id,
            'fk_plant_id' => $plant?->id,
            'plant_zone_id' => $zoneId,
        ]);

        foreach ($definition['requirements'] ?? [] as $requirement) {
            TaskResourceRequirement::query()->create([
                'task_id' => $task->id,
                'resource_name' => $requirement['resource_name'],
                'normalized_name' => mb_strtolower(trim((string) $requirement['resource_name'])),
                'inventory_item_type' => $requirement['inventory_item_type'],
                'unit' => $requirement['unit'],
                'required_quantity' => $requirement['required_quantity'],
                'shortage_quantity' => $requirement['shortage_quantity'] ?? 0,
                'is_consumed' => $requirement['is_consumed'],
            ]);
        }

        return $task->fresh(['requiredResources', 'taskCalendar.plot.gardenOwner', 'plant.catalogPlant.plantCare', 'plant.conditionHistory', 'plant.harvestRecords']);
    }

    /**
     * @return array<string, mixed>
     */
    private function requirement(
        string $resourceName,
        InventoryItemType $type,
        InventoryUnit $unit,
        float $requiredQuantity,
        bool $isConsumed
    ): array {
        return [
            'resource_name' => $resourceName,
            'inventory_item_type' => $type->value,
            'unit' => $unit->value,
            'required_quantity' => $requiredQuantity,
            'shortage_quantity' => 0,
            'is_consumed' => $isConsumed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMeteoForecastPayload(string $placeCode): array
    {
        $place = $this->weatherPlaces[$placeCode] ?? null;

        if (! $place) {
            throw new RuntimeException("Unsupported demo weather place [{$placeCode}]");
        }

        $timestamps = [];

        foreach (CarbonPeriod::create('2026-04-01', '2026-05-10') as $date) {
            $profile = $this->forecastProfile($placeCode, $date->toDateString());
            $timestamps[] = [
                'forecastTimeUtc' => $date->copy()->setTime(6, 0)->toDateTimeString(),
                'airTemperature' => $profile['temp_min'],
                'relativeHumidity' => $profile['humidity'],
                'totalPrecipitation' => 0,
                'windSpeed' => round($profile['wind_kmh'] / 3.6, 3),
                'conditionCode' => $profile['condition'],
            ];
            $timestamps[] = [
                'forecastTimeUtc' => $date->copy()->setTime(12, 0)->toDateTimeString(),
                'airTemperature' => round(($profile['temp_min'] + $profile['temp_max']) / 2, 2),
                'relativeHumidity' => $profile['humidity'],
                'totalPrecipitation' => $profile['rain'],
                'windSpeed' => round($profile['wind_kmh'] / 3.6, 3),
                'conditionCode' => $profile['condition'],
            ];
            $timestamps[] = [
                'forecastTimeUtc' => $date->copy()->setTime(18, 0)->toDateTimeString(),
                'airTemperature' => $profile['temp_max'],
                'relativeHumidity' => max(52, $profile['humidity'] - 4),
                'totalPrecipitation' => 0,
                'windSpeed' => round($profile['wind_kmh'] / 3.6, 3),
                'conditionCode' => $profile['condition'],
            ];
        }

        return [
            'place' => $place,
            'forecastType' => 'long-term',
            'forecastCreationTimeUtc' => '2026-04-22 08:30:00',
            'forecastTimestamps' => $timestamps,
        ];
    }

    /**
     * @return array{temp_min: float, temp_max: float, rain: float, humidity: float, wind_kmh: float, condition: string}
     */
    private function forecastProfile(string $placeCode, string $date): array
    {
        $defaults = [
            'vilnius' => [
                'temp_min' => 6.0,
                'temp_max' => 15.0,
                'rain' => 1.2,
                'humidity' => 69.0,
                'wind_kmh' => 14.0,
                'condition' => 'cloudy-with-sunny-intervals',
            ],
            'kaunas' => [
                'temp_min' => 7.0,
                'temp_max' => 16.0,
                'rain' => 1.0,
                'humidity' => 67.0,
                'wind_kmh' => 13.0,
                'condition' => 'variable-cloudiness',
            ],
            'trakai' => [
                'temp_min' => 5.0,
                'temp_max' => 14.0,
                'rain' => 1.4,
                'humidity' => 71.0,
                'wind_kmh' => 15.0,
                'condition' => 'cloudy-with-sunny-intervals',
            ],
        ];

        $profile = $defaults[$placeCode] ?? $defaults['vilnius'];

        $overrides = [
            'vilnius' => [
                '2026-04-10' => ['temp_min' => 3.0, 'temp_max' => 12.0, 'rain' => 0.0, 'wind_kmh' => 11.0, 'condition' => 'clear'],
                '2026-04-11' => ['temp_min' => 4.0, 'temp_max' => 14.0, 'rain' => 0.0, 'wind_kmh' => 10.0, 'condition' => 'clear'],
                '2026-04-14' => ['temp_min' => 5.0, 'temp_max' => 11.0, 'rain' => 12.0, 'wind_kmh' => 18.0, 'condition' => 'light-rain'],
                '2026-04-18' => ['temp_min' => 8.0, 'temp_max' => 17.0, 'rain' => 0.0, 'wind_kmh' => 12.0, 'condition' => 'clear'],
                '2026-04-21' => ['temp_min' => 1.0, 'temp_max' => 8.0, 'rain' => 0.0, 'wind_kmh' => 31.0, 'condition' => 'cloudy'],
                '2026-04-22' => ['temp_min' => 7.0, 'temp_max' => 16.0, 'rain' => 0.0, 'wind_kmh' => 9.0, 'condition' => 'clear'],
                '2026-04-23' => ['temp_min' => 8.0, 'temp_max' => 19.0, 'rain' => 0.0, 'wind_kmh' => 37.0, 'condition' => 'cloudy'],
                '2026-04-24' => ['temp_min' => 9.0, 'temp_max' => 16.0, 'rain' => 14.0, 'wind_kmh' => 20.0, 'condition' => 'light-rain'],
                '2026-04-27' => ['temp_min' => 15.0, 'temp_max' => 31.0, 'rain' => 0.0, 'wind_kmh' => 12.0, 'condition' => 'clear'],
                '2026-04-30' => ['temp_min' => -1.0, 'temp_max' => 6.0, 'rain' => 0.0, 'wind_kmh' => 24.0, 'condition' => 'snow'],
                '2026-05-02' => ['temp_min' => 11.0, 'temp_max' => 22.0, 'rain' => 0.0, 'wind_kmh' => 10.0, 'condition' => 'clear'],
                '2026-05-05' => ['temp_min' => 10.0, 'temp_max' => 18.0, 'rain' => 2.0, 'wind_kmh' => 16.0, 'condition' => 'light-rain'],
            ],
            'trakai' => [
                '2026-04-19' => ['temp_min' => 4.0, 'temp_max' => 13.0, 'rain' => 0.0, 'wind_kmh' => 17.0, 'condition' => 'clear'],
                '2026-04-20' => ['temp_min' => 5.0, 'temp_max' => 14.0, 'rain' => 4.0, 'wind_kmh' => 19.0, 'condition' => 'light-rain'],
            ],
        ];

        return array_merge($profile, $overrides[$placeCode][$date] ?? []);
    }

    /**
     * @return array{points: array<int, array{x: float, y: float}>}
     */
    private function rectGeometry(float $left, float $top, float $right, float $bottom): array
    {
        return [
            'points' => [
                ['x' => $left, 'y' => $top],
                ['x' => $right, 'y' => $top],
                ['x' => $right, 'y' => $bottom],
                ['x' => $left, 'y' => $bottom],
            ],
        ];
    }
}
