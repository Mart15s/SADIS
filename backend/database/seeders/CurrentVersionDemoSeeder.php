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
use App\Enums\TaskType;
use App\Enums\UserRole;
use App\Models\AccessRight;
use App\Models\CatalogPlant;
use App\Models\CommunityPost;
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
use App\Models\UsedOn;
use App\Models\User;
use App\Models\WeatherForecast;
use App\Services\Plot\RotationPlannerService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrentVersionDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const SOURCE = 'current-version-demo';

    private const EMAILS = [
        'owner' => 'demo.owner@example.test',
        'editor' => 'demo.editor@example.test',
        'viewer' => 'demo.viewer@example.test',
        'neighbor' => 'demo.neighbor@example.test',
        'community' => 'demo.community@example.test',
    ];

    /** @var array<string, array{user: User, profile: Profile, owner: GardenOwner}> */
    private array $actors = [];

    /** @var array<string, CatalogPlant> */
    private array $catalog = [];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->cleanupDemoOwnedRecords();
            $this->seedCatalog();
            $this->actors = $this->seedUsers();

            $world = $this->seedGardenWorld();
            $this->seedInventory();
            $this->seedSharing($world['primary_plot']);
            $calendar = $this->seedCalendar($world);
            $this->seedHarvests($world, $calendar);
            $this->seedConditionHistory($world);
            $this->seedRotationAndPlanning($world);
            $this->seedCommunity($world);
        });

        $this->command?->info('Current version demo dataset seeded.');
        $this->command?->line('Demo password for all demo accounts: '.self::PASSWORD);
    }

    private function cleanupDemoOwnedRecords(): void
    {
        $userIds = User::query()->whereIn('email', array_values(self::EMAILS))->pluck('id');
        $owners = GardenOwner::query()->whereIn('user_id', $userIds)->get();
        $ownerIds = $owners->pluck('id');
        $profileIds = $owners->pluck('fk_profile_id');
        $plotIds = Plot::query()->whereIn('garden_owner_id', $ownerIds)->pluck('id');
        $zoneIds = PlantZone::query()->whereIn('plot_id', $plotIds)->pluck('id');
        $plantIds = Plant::query()->whereIn('fk_plot_id', $plotIds)->pluck('id');
        $calendarIds = TaskCalendar::query()->whereIn('plot_id', $plotIds)->pluck('id');
        $taskIds = Task::query()->whereIn('task_calendar_id', $calendarIds)->pluck('id');
        $inventoryIds = InventoryItem::query()->whereIn('garden_owner_id', $ownerIds)->pluck('id');

        InventoryUsageLog::query()
            ->whereIn('garden_owner_id', $ownerIds)
            ->orWhereIn('inventory_item_id', $inventoryIds)
            ->orWhereIn('task_id', $taskIds)
            ->delete();
        TaskResourceRequirement::query()->whereIn('task_id', $taskIds)->delete();
        HarvestRecord::query()
            ->whereIn('plot_id', $plotIds)
            ->orWhereIn('plant_id', $plantIds)
            ->orWhereIn('task_id', $taskIds)
            ->orWhereIn('garden_owner_id', $ownerIds)
            ->delete();
        PlantConditionHistory::query()->whereIn('plant_id', $plantIds)->delete();
        RotationHistory::query()
            ->whereIn('fk_plot_id', $plotIds)
            ->orWhereIn('fk_plot_via_zone', $plotIds)
            ->orWhereIn('fk_plant_zone_id', $zoneIds)
            ->orWhereIn('fk_plant_id', $plantIds)
            ->delete();
        RotationPlanDraft::query()
            ->whereIn('plot_id', $plotIds)
            ->orWhereIn('garden_owner_id', $ownerIds)
            ->delete();
        DB::table('plot_snapshots')
            ->whereIn('plot_id', $plotIds)
            ->orWhereIn('garden_owner_id', $ownerIds)
            ->delete();
        WeatherForecast::query()->whereIn('task_calendar_id', $calendarIds)->delete();
        UsedOn::query()
            ->whereIn('fk_task_id', $taskIds)
            ->orWhereIn('fk_plot_id', $plotIds)
            ->orWhereIn('fk_plant_zone_id', $zoneIds)
            ->delete();
        Task::query()->whereIn('id', $taskIds)->delete();
        TaskCalendar::query()->whereIn('id', $calendarIds)->delete();
        CommunityPost::query()
            ->whereIn('garden_owner_id', $ownerIds)
            ->orWhereIn('fk_owner_id', $ownerIds)
            ->orWhereIn('fk_profile_id', $profileIds)
            ->orWhereIn('plot_id', $plotIds)
            ->delete();
        AccessRight::query()
            ->whereIn('plot_id', $plotIds)
            ->orWhereIn('garden_owner_id', $ownerIds)
            ->orWhereIn('fk_grantor_owner_id', $ownerIds)
            ->orWhereIn('fk_recipient_owner_id', $ownerIds)
            ->delete();
        HasInventory::query()->whereIn('fk_inventory_item_id', $inventoryIds)->delete();
        InventoryItem::query()->whereIn('id', $inventoryIds)->delete();
        HasPlot::query()->whereIn('fk_plot_id', $plotIds)->delete();
        Plant::query()->whereIn('id', $plantIds)->delete();
        PlantZone::query()->whereIn('id', $zoneIds)->delete();
        Plot::query()->whereIn('id', $plotIds)->delete();
        GardenOwner::query()->whereIn('id', $ownerIds)->delete();
        Profile::query()->whereIn('id', $profileIds)->delete();
        DB::table('personal_access_tokens')->whereIn('tokenable_id', $userIds)->delete();
        User::query()->whereIn('id', $userIds)->delete();
    }

    private function seedCatalog(): void
    {
        foreach ($this->catalogDefinitions() as $definition) {
            $canonical = $this->canonical($definition['name']);
            $care = PlantCare::query()->updateOrCreate(
                ['canonical_name' => $canonical],
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
                    'source_quality' => 'curated-demo',
                    'source_common_name' => $definition['name'],
                    'source_scientific_name' => $definition['scientific'],
                    'source_family' => $definition['family'],
                    'source_image_url' => null,
                ],
            );

            $this->catalog[$definition['name']] = CatalogPlant::query()->updateOrCreate(
                ['canonical_name' => $canonical],
                [
                    'name' => $definition['name'],
                    'plant_type' => $definition['type'],
                    'fk_plant_care_id' => $care->id,
                    'description' => $definition['description'],
                    'source_provider' => self::SOURCE,
                    'source_quality' => 'curated-demo',
                    'source_scientific_name' => $definition['scientific'],
                    'source_family' => $definition['family'],
                    'source_image_url' => null,
                    'metadata' => [
                        'soil_ph' => $definition['soil_ph'],
                        'spacing_cm' => $definition['spacing_cm'],
                        'planting_depth_cm' => $definition['depth_cm'],
                        'companions' => $definition['companions'],
                        'avoid_after_family' => $definition['family'],
                        'fertilization' => $definition['fertilization'],
                        'care_notes' => $definition['notes'],
                    ],
                ],
            )->fresh('plantCare');
        }
    }

    /**
     * @return array<string, array{user: User, profile: Profile, owner: GardenOwner}>
     */
    private function seedUsers(): array
    {
        $users = [
            'owner' => ['Olivia', 'Harper', self::EMAILS['owner'], UserRole::Owner->value],
            'editor' => ['Ethan', 'Walker', self::EMAILS['editor'], UserRole::Owner->value],
            'viewer' => ['Maya', 'Brooks', self::EMAILS['viewer'], UserRole::Owner->value],
            'neighbor' => ['Noah', 'Reed', self::EMAILS['neighbor'], UserRole::Owner->value],
            'community' => ['Grace', 'Morgan', self::EMAILS['community'], UserRole::Owner->value],
        ];

        $actors = [];
        foreach ($users as $key => [$name, $surname, $email, $role]) {
            $user = User::query()->create([
                'email' => $email,
                'password' => self::PASSWORD,
                'role' => $role,
                'created_at' => now()->subMonths(10),
                'updated_at' => now(),
            ]);
            $profile = Profile::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'surname' => $surname,
                'last_login' => now()->subHours($key === 'owner' ? 2 : 16),
            ]);
            $owner = GardenOwner::query()->create([
                'id' => $user->id,
                'user_id' => $user->id,
                'id_user' => $user->id,
                'fk_profile_id' => $profile->id,
            ]);

            $actors[$key] = ['user' => $user, 'profile' => $profile, 'owner' => $owner];
        }

        return $actors;
    }

    /**
     * @return array<string, mixed>
     */
    private function seedGardenWorld(): array
    {
        $owner = $this->actors['owner']['owner'];

        $primary = $this->createPlot($owner, [
            'name' => 'Oakridge Kitchen Garden',
            'city' => 'Vilnius',
            'plot_size' => 186.00,
            'creation_date' => now()->subYear()->toDateString(),
            'description' => 'Veikiantis virtuvinis daržas su pakeltomis daržovių lysvėmis, prieskoninių augalų juostomis, uogomis ir augalais palydovais.',
            'share' => true,
            'geometry' => $this->rect(0.04, 0.06, 0.96, 0.92),
        ]);

        $secondary = $this->createPlot($owner, [
            'name' => 'South Fence Berry and Orchard Strip',
            'city' => 'Vilnius',
            'plot_size' => 74.00,
            'creation_date' => now()->subMonths(9)->toDateString(),
            'description' => 'Mažesnė daugiametė juosta uogoms, mėtų dėžei ir jaunai obeliai.',
            'share' => false,
            'geometry' => $this->rect(0.06, 0.10, 0.94, 0.86),
        ]);

        $zones = [
            'tomatoes' => $this->createZone($primary, 'Pomidorų ir bazilikų lysvė', 20, SoilType::Peaty->value, 2, $this->rect(0.08, 0.10, 0.25, 0.30)),
            'cucurbits' => $this->createZone($primary, 'Agurkų atramų lysvė', 18, SoilType::Sandy->value, 1, $this->rect(0.30, 0.10, 0.47, 0.30)),
            'roots' => $this->createZone($primary, 'Šakniavaisių lysvė', 18, SoilType::Clay->value, 3, $this->rect(0.52, 0.10, 0.69, 0.30)),
            'greens' => $this->createZone($primary, 'Lapinių daržovių lysvė', 18, SoilType::Peaty->value, 1, $this->rect(0.74, 0.10, 0.91, 0.30)),
            'brassicas' => $this->createZone($primary, 'Kopūstinių lysvė', 18, SoilType::Clay->value, 2, $this->rect(0.08, 0.36, 0.25, 0.56)),
            'legumes' => $this->createZone($primary, 'Žirnių ir pupelių lysvė', 18, SoilType::Sandy->value, 4, $this->rect(0.30, 0.36, 0.47, 0.56)),
            'nightshades' => $this->createZone($primary, 'Paprikų lysvė', 18, SoilType::Peaty->value, 2, $this->rect(0.52, 0.36, 0.69, 0.56)),
            'herbs' => $this->createZone($primary, 'Daugiamečių prieskonių juosta', 18, SoilType::Rocky->value, 5, $this->rect(0.74, 0.36, 0.91, 0.56)),
            'flowers' => $this->createZone($primary, 'Augalų palydovų gėlių kraštas', 18, SoilType::Sandy->value, 1, $this->rect(0.08, 0.62, 0.25, 0.82)),
            'squash' => $this->createZone($primary, 'Moliūginių kauburys', 18, SoilType::Peaty->value, 3, $this->rect(0.30, 0.62, 0.47, 0.82)),
            'corn' => $this->createZone($primary, 'Kukurūzų blokas', 18, SoilType::Clay->value, 1, $this->rect(0.52, 0.62, 0.69, 0.82)),
            'strawberries' => $this->createZone($primary, 'Braškių eilė', 18, SoilType::Sandy->value, 4, $this->rect(0.74, 0.62, 0.91, 0.82)),
            'raspberry' => $this->createZone($secondary, 'Aviečių zona', 18, SoilType::Sandy->value, 6, $this->rect(0.10, 0.16, 0.32, 0.76)),
            'blueberry' => $this->createZone($secondary, 'Rūgščią dirvą mėgstančių šilauogių lysvė', 16, SoilType::Peaty->value, 5, $this->rect(0.38, 0.16, 0.58, 0.76)),
            'apple' => $this->createZone($secondary, 'Jaunos obels zona', 20, SoilType::Clay->value, 7, $this->rect(0.64, 0.16, 0.86, 0.56)),
            'mint' => $this->createZone($secondary, 'Mėtų dėžė', 8, SoilType::Peaty->value, 3, $this->rect(0.64, 0.62, 0.86, 0.78)),
        ];

        $plants = [
            'tomato' => $this->createPlant($primary, $zones['tomatoes'], 'Tomato', 'Pomidoras „Sungold“', -42, ConditionType::Flowering->value, 3.4, false, 'Apatiniai lapai pašalinti geresnei oro cirkuliacijai.'),
            'basil' => $this->createPlant($primary, $zones['tomatoes'], 'Basil', 'Bazilikas „Genovese“', -28, ConditionType::Growing->value, 1.1, false),
            'cucumber' => $this->createPlant($primary, $zones['cucurbits'], 'Cucumber', 'Agurkas „Marketmore“', -31, ConditionType::Growing->value, 2.7, false),
            'carrot' => $this->createPlant($primary, $zones['roots'], 'Carrot', 'Morka „Nantes“', -39, ConditionType::Growing->value, 1.8, false),
            'beetroot' => $this->createPlant($primary, $zones['roots'], 'Beetroot', 'Burokėlis „Detroit Dark Red“', -36, ConditionType::Growing->value, 1.5, false),
            'lettuce' => $this->createPlant($primary, $zones['greens'], 'Lettuce', 'Salota „Little Gem“', -24, ConditionType::Mature->value, 1.2, false),
            'spinach' => $this->createPlant($primary, $zones['greens'], 'Spinach', 'Špinatas „Space“', -21, ConditionType::Mature->value, 1.0, false),
            'cabbage' => $this->createPlant($primary, $zones['brassicas'], 'Cabbage', 'Kopūstas „Golden Acre“', -48, ConditionType::Growing->value, 2.1, false),
            'broccoli' => $this->createPlant($primary, $zones['brassicas'], 'Broccoli', 'Brokolis „Calabrese“', -45, ConditionType::Growing->value, 2.2, false),
            'pea' => $this->createPlant($primary, $zones['legumes'], 'Pea', 'Žirnis „Sugar Ann“', -51, ConditionType::Flowering->value, 2.0, false),
            'bean' => $this->createPlant($primary, $zones['legumes'], 'Bean', 'Pupelė „Provider“', -18, ConditionType::Germinating->value, 1.0, false),
            'pepper' => $this->createPlant($primary, $zones['nightshades'], 'Pepper', 'Saldžioji paprika „California Wonder“', -40, ConditionType::Growing->value, 2.3, false),
            'chili' => $this->createPlant($primary, $zones['nightshades'], 'Chili Pepper', 'Aitrioji paprika „Jalapeno“', -40, ConditionType::Growing->value, 1.9, false),
            'thyme' => $this->createPlant($primary, $zones['herbs'], 'Thyme', 'Paprastasis čiobrelis', -70, ConditionType::Regenerating->value, 0.8, true),
            'rosemary' => $this->createPlant($primary, $zones['herbs'], 'Rosemary', 'Rozmarinas', -80, ConditionType::Growing->value, 1.2, true),
            'parsley' => $this->createPlant($primary, $zones['herbs'], 'Parsley', 'Lapinės petražolės', -35, ConditionType::Growing->value, 1.0, true),
            'marigold' => $this->createPlant($primary, $zones['flowers'], 'Marigold', 'Gvazdikinis serenčių kraštas', -32, ConditionType::Flowering->value, 1.3, false),
            'calendula' => $this->createPlant($primary, $zones['flowers'], 'Calendula', 'Medetkų kraštas', -33, ConditionType::Flowering->value, 1.2, false),
            'zucchini' => $this->createPlant($primary, $zones['squash'], 'Zucchini', 'Cukinija „Black Beauty“', -27, ConditionType::Flowering->value, 3.0, false),
            'pumpkin' => $this->createPlant($primary, $zones['squash'], 'Pumpkin', 'Moliūgas „Small Sugar“', -26, ConditionType::Growing->value, 3.5, false),
            'corn' => $this->createPlant($primary, $zones['corn'], 'Corn', 'Saldusis kukurūzas „Golden Bantam“', -30, ConditionType::Growing->value, 2.8, false),
            'strawberry' => $this->createPlant($primary, $zones['strawberries'], 'Strawberry', 'Braškė „Honeoye“', -260, ConditionType::Flowering->value, 2.4, true),
            'raspberry' => $this->createPlant($secondary, $zones['raspberry'], 'Raspberry', 'Avietė „Glen Ample“', -300, ConditionType::Growing->value, 5.0, true),
            'blueberry' => $this->createPlant($secondary, $zones['blueberry'], 'Blueberry', 'Šilauogė „Bluecrop“', -310, ConditionType::Flowering->value, 3.2, true),
            'apple' => $this->createPlant($secondary, $zones['apple'], 'Apple Tree', 'Obelis „Auksis“', -720, ConditionType::Growing->value, 9.0, true),
            'mint' => $this->createPlant($secondary, $zones['mint'], 'Apple Mint', 'Obuolinė mėta', -120, ConditionType::Regenerating->value, 1.6, true),
        ];

        $this->snapshot($primary, 'plot_created', now()->subMonths(12), 'Sukurta pradinė sklypo riba ir savininko ryšys.');
        $this->snapshot($primary, 'plot_saved', now()->subMonths(2), 'Pakeltos lysvės perkeltos į keturių stulpelių išdėstymą su platesniais takais.');
        $this->snapshot($primary, 'plot_saved', now()->subWeeks(3), 'Išsauginti pavasariniai sodinimai ir augalų palydovų gėlių kraštas.');
        $this->snapshot($secondary, 'plot_created', now()->subMonths(9), 'Sukurta daugiametė uogų ir sodo juosta.');

        return [
            'primary_plot' => $primary,
            'secondary_plot' => $secondary,
            'zones' => $zones,
            'plants' => $plants,
        ];
    }

    private function seedInventory(): void
    {
        $owner = $this->actors['owner']['owner'];
        $items = [
            ['Kompostas', 5.5, InventoryItemType::Material->value, InventoryUnit::Bag->value],
            ['Organinės pomidorų trąšos', 1.2, InventoryItemType::Material->value, InventoryUnit::Kilogram->value],
            ['Skystos jūros dumblių trąšos', 0.4, InventoryItemType::Material->value, InventoryUnit::Liter->value],
            ['Šiaudų mulčias', 2.0, InventoryItemType::Material->value, InventoryUnit::Bag->value],
            ['Biofungicidas be vario', 0.1, InventoryItemType::Material->value, InventoryUnit::Liter->value],
            ['Nimbamedžio aliejaus purškalas', 0.3, InventoryItemType::Material->value, InventoryUnit::Liter->value],
            ['Morkų sėklų pakelis', 1, InventoryItemType::Material->value, InventoryUnit::Pack->value],
            ['Laistymo žarna', 1, InventoryItemType::Tool->value, InventoryUnit::Unit->value],
            ['Rankinis sodinimo kastuvėlis', 2, InventoryItemType::Tool->value, InventoryUnit::Unit->value],
            ['Genėjimo žirklės', 1, InventoryItemType::Tool->value, InventoryUnit::Unit->value],
            ['Apsauginė danga', 1, InventoryItemType::Tool->value, InventoryUnit::Unit->value],
            ['Augalų raiščiai', 12, InventoryItemType::Material->value, InventoryUnit::Unit->value],
        ];

        foreach ($items as [$name, $quantity, $type, $unit]) {
            $item = InventoryItem::query()->create([
                'garden_owner_id' => $owner->id,
                'name' => $name,
                'quantity' => $quantity,
                'inventory_item_type' => $type,
                'type' => $type,
                'unit' => $unit,
            ]);
            HasInventory::query()->create([
                'fk_inventory_item_id' => $item->id,
                'fk_owner_id' => $owner->id_user,
                'fk_profile_id' => $owner->fk_profile_id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $world
     */
    private function seedSharing(Plot $primary): void
    {
        $grantor = $this->actors['owner']['owner'];

        foreach ([['editor', AccessRole::Editor->value], ['viewer', AccessRole::Viewer->value]] as [$actorKey, $role]) {
            $recipient = $this->actors[$actorKey]['owner'];
            AccessRight::query()->create([
                'granted_at' => now()->subWeeks($role === AccessRole::Editor->value ? 8 : 6),
                'role' => $role,
                'garden_owner_id' => $recipient->id,
                'plot_id' => $primary->id,
                'fk_plot_id' => $primary->id,
                'fk_grantor_owner_id' => $grantor->id_user,
                'fk_grantor_profile_id' => $grantor->fk_profile_id,
                'fk_recipient_owner_id' => $recipient->id_user,
                'fk_recipient_profile_id' => $recipient->fk_profile_id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $world
     * @return array<string, Task>
     */
    private function seedCalendar(array $world): array
    {
        $primary = $world['primary_plot'];
        $calendar = TaskCalendar::query()->create([
            'creation_date' => now()->subDay(),
            'start_date' => now()->subWeeks(3)->toDateString(),
            'end_date' => now()->addWeeks(4)->toDateString(),
            'plot_id' => $primary->id,
            'fk_plot_id' => $primary->id,
        ]);

        $this->seedWeather($calendar, 'Vilnius');

        $tasks = [];
        $definitions = [
            ['water_tomato_done', -14, 'Giliai palaistyti pomidorus ir bazilikus', TaskType::Watering, TaskState::Completed, TaskPriority::Medium, 'Tomato', 'tomatoes', 'Kompostas', 0.2],
            ['buy_compost_done', -13, 'Nupirkti komposto pavasariniam papildomam tręšimui', TaskType::Buy, TaskState::Completed, TaskPriority::Medium, 'Tomato', 'tomatoes', 'Kompostas', 2.0],
            ['feed_tomato_done', -12, 'Patręšti žydinčius pomidorus', TaskType::Fertilize, TaskState::Completed, TaskPriority::High, 'Tomato', 'tomatoes', 'Organinės pomidorų trąšos', 0.25],
            ['harvest_lettuce_done', -10, 'Nuskinti išorinius salotų lapus', TaskType::Harvest, TaskState::Completed, TaskPriority::Medium, 'Lettuce', 'greens', null, null],
            ['pest_cabbage_done', -8, 'Patikrinti kopūstinius dėl vikšrų', TaskType::Spray, TaskState::Completed, TaskPriority::Medium, 'Cabbage', 'brassicas', 'Nimbamedžio aliejaus purškalas', 0.05],
            ['frost_cover_canceled', -6, 'Uždengti paprikas dėl šalnos perspėjimo', TaskType::Rest, TaskState::Canceled, TaskPriority::Low, 'Pepper', 'nightshades', 'Apsauginė danga', 1],
            ['mulch_strawberry_overdue', -2, 'Atnaujinti braškių mulčią po lietaus', TaskType::Rest, TaskState::Pending, TaskPriority::High, 'Strawberry', 'strawberries', 'Šiaudų mulčias', 1.5],
            ['water_cucumbers_today', 0, 'Palaistyti agurkų atramas ties dirvos paviršiumi', TaskType::Watering, TaskState::Pending, TaskPriority::High, 'Cucumber', 'cucurbits', null, null],
            ['buy_biofungicide_today', 0, 'Nupirkti biofungicido pomidorų lapų dėmėtumo reakcijai', TaskType::Buy, TaskState::Pending, TaskPriority::High, 'Tomato', 'tomatoes', 'Biofungicidas be vario', 0.9],
            ['tie_tomatoes_tomorrow', 1, 'Pririšti pomidorus prie atramų prieš vėjuotą vakarą', TaskType::Rest, TaskState::Pending, TaskPriority::High, 'Tomato', 'tomatoes', 'Augalų raiščiai', 4],
            ['feed_peppers_soon', 3, 'Papildomai patręšti paprikas kompostu', TaskType::Fertilize, TaskState::Pending, TaskPriority::Medium, 'Pepper', 'nightshades', 'Kompostas', 1.0],
            ['harvest_spinach_soon', 5, 'Nuimti špinatus prieš karščio stresą', TaskType::Harvest, TaskState::Pending, TaskPriority::Medium, 'Spinach', 'greens', null, null],
            ['spray_apple_soon', 6, 'Patikrinti obelį dėl amarų ir lapų garbanojimosi', TaskType::Spray, TaskState::Pending, TaskPriority::Medium, null, null, 'Nimbamedžio aliejaus purškalas', 0.1],
            ['water_blueberry_soon', 8, 'Giliai palaistyti šilauogių lysvę', TaskType::Watering, TaskState::Pending, TaskPriority::Medium, null, null, null, null],
            ['plant_bean_followup', 9, 'Praretinti sudygusias pupeles iki galutinio atstumo', TaskType::Transplant, TaskState::Pending, TaskPriority::Low, 'Bean', 'legumes', null, null],
            ['harvest_peas_future', 13, 'Nuskinti pirmąsias cukrinių žirnių ankštis', TaskType::Harvest, TaskState::Pending, TaskPriority::Medium, 'Pea', 'legumes', null, null],
            ['buy_mulch_future', 15, 'Nupirkti šiaudų mulčio uogų lysvėms', TaskType::Buy, TaskState::Pending, TaskPriority::Medium, 'Strawberry', 'strawberries', 'Šiaudų mulčias', 2.0],
            ['corn_water_future', 18, 'Giliai palaistyti kukurūzų bloką', TaskType::Watering, TaskState::Pending, TaskPriority::Medium, 'Corn', 'corn', null, null],
        ];

        foreach ($definitions as [$key, $offset, $name, $type, $state, $priority, $catalogName, $zoneKey, $item, $quantity]) {
            $plant = $catalogName ? $this->findPlantByCatalog($world['plants'], $catalogName) : null;
            $zone = $zoneKey ? $world['zones'][$zoneKey] : ($plant?->plantZone);
            $inventoryContext = $this->inventoryContext($item, $quantity, $type->value);
            $tasks[$key] = $this->createTask($calendar, $plant, $zone, [
                'date' => now()->addDays($offset)->toDateString(),
                'name' => $name,
                'task_type' => $type->value,
                'state' => $state->value,
                'priority' => $priority->value,
                'reason' => $this->taskReason($type->value, $offset),
                'comment' => $this->taskComment($type->value),
                'item' => $item,
                'item_quantity' => $quantity,
                'inventory_context' => $inventoryContext,
                'weather_context' => $this->weatherContext($offset),
                'workflow_context' => ['seeded_by' => self::SOURCE, 'demo_key' => $key],
            ]);

            if ($item && $quantity) {
                $this->createRequirementAndUsage($tasks[$key], $item, $quantity, $state->value, $type->value);
            }
        }

        return $tasks;
    }

    private function seedWeather(TaskCalendar $calendar, string $city): void
    {
        foreach (CarbonPeriod::create(now()->subWeeks(3), now()->addWeeks(4)) as $date) {
            $offset = now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
            $precipitation = in_array($offset, [-16, -2, 4, 11], true) ? 12.4 : (in_array($offset, [0, 8, 19], true) ? 2.2 : 0.4);
            $tempMax = match (true) {
                $offset === 7 => 31.5,
                $offset === -18 => 3.0,
                $offset > 12 => 24.0,
                default => 20.0 + (($offset % 5) * 1.2),
            };
            $tempMin = $offset === -18 ? -1.0 : max(6.0, $tempMax - 10.0);
            WeatherForecast::query()->create([
                'task_calendar_id' => $calendar->id,
                'fk_task_calendar_id' => $calendar->id,
                'date' => $date->toDateString(),
                'temperature' => round(($tempMin + $tempMax) / 2, 1),
                'temp_min' => $tempMin,
                'temp_max' => $tempMax,
                'precipitation' => $precipitation,
                'humidity' => $precipitation > 5 ? 84 : 58,
                'wind_kmh' => $offset === 1 ? 48 : (18 + abs($offset % 4) * 4),
                'condition_code' => $precipitation > 5 ? 'rain' : ($tempMax > 30 ? 'hot' : 'partly-cloudy'),
                'is_seasonal_fallback' => false,
                'source' => self::SOURCE,
                'source_date' => now()->toDateString(),
                'source_city' => $city,
                'city' => $city,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  array<string, Task>  $tasks
     */
    private function seedHarvests(array $world, array $tasks): void
    {
        $owner = $this->actors['owner']['owner'];
        $records = [
            ['lettuce', 'harvest_lettuce_done', -10, 1.8, 'Crisp outer leaves harvested for two meals.'],
            ['spinach', null, -7, 1.2, 'Second small spinach cut before warmer weather.'],
            ['parsley', null, -6, 0.3, 'Fresh parsley bunches for the kitchen.'],
            ['thyme', null, -5, 0.1, 'Light trim to keep the plant compact.'],
            ['strawberry', null, -3, 0.7, 'First early berries from protected blossoms.'],
            ['pea', null, -1, 0.5, 'Trial picking from the earliest flowers.'],
            ['mint', null, -18, 0.4, 'Mint cut back for tea and to prevent spreading.'],
            ['raspberry', null, -25, 0.6, 'Frozen berries from last canes cleanup.'],
        ];

        foreach ($records as [$plantKey, $taskKey, $offset, $quantity, $notes]) {
            $plant = $world['plants'][$plantKey];
            HarvestRecord::query()->create([
                'plot_id' => $plant->fk_plot_id,
                'plant_id' => $plant->id,
                'task_id' => $taskKey ? $tasks[$taskKey]->id : null,
                'garden_owner_id' => $owner->id,
                'quantity' => $quantity,
                'harvested_on' => now()->addDays($offset)->toDateString(),
                'notes' => $notes,
                'created_at' => now()->addDays($offset)->setTime(18, 0),
                'updated_at' => now()->addDays($offset)->setTime(18, 0),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $world
     */
    private function seedConditionHistory(array $world): void
    {
        $entries = [
            ['tomato', -20, ConditionType::Growing->value, 'Good transplant recovery after compost top dressing.'],
            ['tomato', -9, ConditionType::Diseased->value, 'Small lower leaf spots found after wet nights.'],
            ['tomato', -4, ConditionType::Flowering->value, 'Leaf spots stabilized after pruning and better airflow.'],
            ['cucumber', -12, ConditionType::Growing->value, 'Strong new tendrils attached to trellis.'],
            ['cucumber', -2, ConditionType::Dried->value, 'Slight drought stress on one corner before deep watering.'],
            ['cucumber', 0, ConditionType::Growing->value, 'Leaves recovered after morning watering.'],
            ['cabbage', -16, ConditionType::Growing->value, 'Heads forming evenly.'],
            ['cabbage', -8, ConditionType::Diseased->value, 'Chewed leaves and caterpillar eggs found under outer leaves.'],
            ['cabbage', -3, ConditionType::Growing->value, 'Pest pressure reduced after hand removal and spray.'],
            ['pepper', -10, ConditionType::Growing->value, 'Slow growth but color is good.'],
            ['pepper', -1, ConditionType::Growing->value, 'New flower buds visible.'],
            ['strawberry', -15, ConditionType::Flowering->value, 'Heavy bloom, mulch still thin near path.'],
            ['blueberry', -6, ConditionType::Flowering->value, 'Blossoms healthy; soil is staying evenly moist.'],
            ['apple', -30, ConditionType::Regenerating->value, 'Winter pruning wounds healed cleanly.'],
            ['apple', -4, ConditionType::Growing->value, 'New shoots are balanced after tying the central leader.'],
        ];

        foreach ($entries as [$plantKey, $offset, $condition, $notes]) {
            $plant = $world['plants'][$plantKey];
            PlantConditionHistory::query()->create([
                'plant_id' => $plant->id,
                'fk_plant_id' => $plant->id,
                'measured_at' => now()->addDays($offset)->setTime(9, 30),
                'condition' => $condition,
                'condition_type' => $condition,
                'notes' => $notes,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $world
     */
    private function seedRotationAndPlanning(array $world): void
    {
        $owner = $this->actors['owner']['owner'];
        $primary = $world['primary_plot'];
        $records = [
            ['tomatoes', 'carrot', '2024-04-20', '2024-09-01'],
            ['tomatoes', 'pea', '2025-04-22', '2025-08-20'],
            ['tomatoes', 'tomato', now()->subDays(42)->toDateString(), null],
            ['roots', 'cabbage', '2024-04-18', '2024-09-05'],
            ['roots', 'beetroot', now()->subDays(36)->toDateString(), null],
            ['legumes', 'potato', '2024-04-15', '2024-09-12'],
            ['legumes', 'pea', now()->subDays(51)->toDateString(), null],
            ['nightshades', 'bean', '2025-05-01', '2025-08-30'],
            ['nightshades', 'pepper', now()->subDays(40)->toDateString(), null],
        ];

        foreach ($records as [$zoneKey, $plantKey, $from, $to]) {
            $plant = $world['plants'][$plantKey] ?? $this->createHistoricalPlant($primary, $world['zones'][$zoneKey], $plantKey, $from);
            RotationHistory::query()->create([
                'plant_zone_id' => $world['zones'][$zoneKey]->id,
                'from_date' => $from,
                'to_date' => $to,
                'fk_plot_id' => $primary->id,
                'fk_plant_zone_id' => $world['zones'][$zoneKey]->id,
                'fk_plot_via_zone' => $primary->id,
                'fk_plant_id' => $plant->id,
            ]);
        }

        app(RotationPlannerService::class)->createDraft(
            $primary,
            now()->addMonths(7)->toDateString(),
            $owner
        );
    }

    /**
     * @param  array<string, mixed>  $world
     */
    private function seedCommunity(array $world): void
    {
        $posts = [
            ['neighbor', 'Agurkų laistymo laikas po lietaus', 'Po daugiau nei 10 mm lietaus agurkų nelaistau, bet vis tiek patikrinu dėžes, nes pakeltų lysvių kraštai išdžiūsta greičiau.', true, -16, $world['primary_plot']],
            ['community', 'Pirmasis braškių mulčio papildymas', 'Plonas šiaudų sluoksnis po uogomis šią savaitę padėjo išlaikyti vaisius švaresnius. Prieš kitą lietingą laikotarpį jo dar papildysiu.', true, -13, $world['primary_plot']],
            ['owner', 'Pavasarinis išdėstymo atnaujinimas', 'Keturių pakeltų lysvių eilių išdėstymas pasiteisina. Takai pakankamai platūs karučiui, o kiekviena lysvė pasiekiama iš abiejų pusių.', true, -11, $world['primary_plot']],
            ['editor', 'Pastaba apie pomidorų vėdinimą', 'Po drėgno laikotarpio padėjo pašalinti žemiausius pomidorų lapus. Augalai atrodo gyvybingesni, o po jais augantiems bazilikams dar pakanka vietos.', true, -8, $world['primary_plot']],
            ['viewer', 'Klausimas apie šilauogių drėgmę', 'Ar per karštas savaites kas nors mulčiuoja šilauoges pušų žieve? Noriu išlaikyti drėgmę, bet nepermirkyti dirvos.', true, -7, null],
            ['neighbor', 'Sėklų mainai: krūminės pupelės', 'Turiu papildomų patikimos partijos krūminių pupelių sėklų pakelių. Mielai išmainyčiau į krapų arba medetkų sėklas.', true, -6, null],
            ['community', 'Komposto inventoriaus patarimas', 'Kompostą pradėjau skaičiuoti maišais, o ne kilogramais. Taip planuoti lysvių papildomą tręšimą tapo daug paprasčiau.', true, -5, null],
            ['owner', 'Privati pastaba sklypo pagalbininkams', 'Prieš vakarinę vėjo prognozę pirmiausia pasirūpinkite agurkų laistymu ir pomidorų pririšimu.', false, -2, $world['primary_plot']],
            ['editor', 'Kopūstų kenkėjų patikros rezultatas', 'Vikšrų kiaušinėliai daugiausia buvo apatinėje išorinių lapų pusėje. Penkių minučių patikra padėjo apsaugoti lysvę.', true, -1, $world['primary_plot']],
            ['community', 'Augalų draugijos juostos nauda', 'Serenčiai ir medetkos apdulkintojus į moliūginių lysvę pritraukia anksčiau nei pernai.', true, 0, $world['primary_plot']],
            ['neighbor', 'Derliaus pavyzdys', 'Šiandien nuskyniau pirmą mažą dubenėlį žirnių. Svarbiausia buvo po lietaus išlaikyti ankstyvąsias atramas tvirtai pririštas.', true, 1, null],
            ['owner', 'Kita rotacijos idėja', 'Kitą pavasarį po pomidorų planuoju ankštinius, o paprikas perkelsiu į dabartinę žirnių lysvę, kad sumažėtų bulvinių apkrova.', true, 2, $world['primary_plot']],
        ];

        foreach ($posts as [$actorKey, $name, $text, $share, $offset, $plot]) {
            $actor = $this->actors[$actorKey];
            CommunityPost::query()->create([
                'garden_owner_id' => $actor['owner']->id,
                'plot_id' => $plot?->id,
                'name' => $name,
                'text' => $text,
                'share' => $share,
                'created_at' => now()->addDays($offset)->setTime(17, 20),
                'fk_owner_id' => $actor['owner']->id_user,
                'fk_profile_id' => $actor['profile']->id,
                'fk_plot_id' => $plot?->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createPlot(GardenOwner $owner, array $data): Plot
    {
        $plot = Plot::query()->create([...$data, 'garden_owner_id' => $owner->id]);
        HasPlot::query()->create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return $plot;
    }

    /**
     * @param  array<string, mixed>  $geometry
     */
    private function createZone(Plot $plot, string $name, float $size, string $soil, int $rotationStage, array $geometry): PlantZone
    {
        return PlantZone::query()->create([
            'plot_id' => $plot->id,
            'fk_plot_id' => $plot->id,
            'name' => $name,
            'zone_size' => $size,
            'soil_type' => $soil,
            'rotation_stage' => $rotationStage,
            'last_planting_date' => now()->subWeeks(max(1, 10 - $rotationStage))->toDateString(),
            'geometry' => $geometry,
        ]);
    }

    private function createPlant(Plot $plot, PlantZone $zone, string $catalogName, string $name, int $daysFromToday, string $condition, float $size, bool $reusable, ?string $notes = null): Plant
    {
        $catalog = $this->catalog[$catalogName];
        $care = $catalog->plantCare;

        return Plant::query()->create([
            'name' => $name,
            'growing_time_days' => $care?->growing_duration_days,
            'recommended_temperature' => $care?->heat_extra_water_temp_c ? min(24, (float) $care->heat_extra_water_temp_c - 6) : 20,
            'recommended_humidity' => 62,
            'plant_date' => now()->addDays($daysFromToday)->toDateString(),
            'disease_notes' => $notes,
            'disease' => $condition === ConditionType::Diseased->value,
            'rest_time_days' => $care?->regenerating_duration_days ?? 30,
            'plant_size' => $size,
            'photo_url' => null,
            'reusable' => $reusable,
            'type' => $catalog->plant_type?->value ?? $catalog->plant_type,
            'condition' => $condition,
            'fk_catalog_plant_id' => $catalog->id,
            'plant_zone_id' => $zone->id,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
        ]);
    }

    private function createHistoricalPlant(Plot $plot, PlantZone $zone, string $plantKey, string $date): Plant
    {
        $name = str_replace('_', ' ', $plantKey);
        $catalog = $this->catalog[ucwords($name)] ?? $this->catalog['Carrot'];

        return Plant::query()->create([
            'name' => ucwords($name).' historical crop',
            'plant_date' => $date,
            'type' => $catalog->plant_type?->value ?? $catalog->plant_type,
            'condition' => ConditionType::Mature->value,
            'plant_zone_id' => $zone->id,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
            'fk_catalog_plant_id' => $catalog->id,
            'reusable' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTask(TaskCalendar $calendar, ?Plant $plant, ?PlantZone $zone, array $data): Task
    {
        $task = Task::query()->create([
            ...$data,
            'task_calendar_id' => $calendar->id,
            'fk_task_calendar_id' => $calendar->id,
            'plant_id' => $plant?->id,
            'fk_plant_id' => $plant?->id,
            'plant_zone_id' => $zone?->id,
        ]);

        if ($zone) {
            UsedOn::query()->create([
                'fk_plant_zone_id' => $zone->id,
                'fk_plot_id' => $zone->plot_id,
                'fk_task_id' => $task->id,
            ]);
        }

        return $task;
    }

    private function createRequirementAndUsage(Task $task, string $item, float $quantity, string $state, string $type): void
    {
        $inventoryItem = InventoryItem::query()
            ->where('garden_owner_id', $this->actors['owner']['owner']->id)
            ->where('name', $item)
            ->first();

        $shortage = $inventoryItem ? max(0, $quantity - (float) $inventoryItem->quantity) : $quantity;
        $requirement = TaskResourceRequirement::query()->create([
            'task_id' => $task->id,
            'resource_name' => $item,
            'inventory_item_type' => $type === TaskType::Buy->value ? InventoryItemType::Material->value : ($inventoryItem?->inventory_item_type?->value ?? InventoryItemType::Material->value),
            'unit' => $inventoryItem?->unit?->value ?? InventoryUnit::Unit->value,
            'required_quantity' => $quantity,
            'shortage_quantity' => $shortage,
            'is_consumed' => $type !== TaskType::Rest->value,
        ]);

        if ($state === TaskState::Completed->value && $inventoryItem) {
            InventoryUsageLog::query()->create([
                'inventory_item_id' => $inventoryItem->id,
                'task_id' => $task->id,
                'task_resource_requirement_id' => $requirement->id,
                'garden_owner_id' => $this->actors['owner']['owner']->id,
                'change_type' => 'consume',
                'quantity_before' => (float) $inventoryItem->quantity + $quantity,
                'quantity_delta' => -$quantity,
                'quantity_after' => (float) $inventoryItem->quantity,
                'unit' => $inventoryItem->unit?->value ?? InventoryUnit::Unit->value,
                'metadata' => ['seeded_by' => self::SOURCE],
                'created_at' => $task->date?->toDateTimeString() ?? now(),
            ]);
        }
    }

    private function snapshot(Plot $plot, string $action, CarbonImmutable|CarbonInterface $date, string $summary): void
    {
        $plot->load('plantZones.plants', 'plants');
        DB::table('plot_snapshots')->insert([
            'plot_id' => $plot->id,
            'garden_owner_id' => $plot->garden_owner_id,
            'action' => $action,
            'snapshot' => json_encode([
                'plot' => $plot->toArray(),
                'zones' => $plot->plantZones->toArray(),
                'plants' => $plot->plants->toArray(),
                'metadata' => [
                    'label' => $action === 'plot_created' ? 'Sukurtas demonstracinis sklypas' : 'Išsaugota demonstracinė sklypo versija',
                    'summary' => $summary,
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => $date,
        ]);
    }

    /**
     * @return array{points: array<int, array{x: float, y: float}>}
     */
    private function rect(float $left, float $top, float $right, float $bottom): array
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

    private function canonical(string $name): string
    {
        return str($name)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    /**
     * @param  array<string, Plant>  $plants
     */
    private function findPlantByCatalog(array $plants, string $catalogName): ?Plant
    {
        return collect($plants)->first(function (Plant $plant) use ($catalogName): bool {
            return $plant->catalogPlant?->name === $catalogName || str_contains($plant->name, $catalogName);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryContext(?string $item, ?float $quantity, string $taskType): array
    {
        if (! $item || ! $quantity) {
            return ['status' => 'not_required', 'inventory_mode' => 'not_required', 'is_actionable' => true];
        }

        $inventory = InventoryItem::query()
            ->where('garden_owner_id', $this->actors['owner']['owner']->id)
            ->where('name', $item)
            ->first();
        $available = (float) ($inventory?->quantity ?? 0);
        $shortage = max(0, $quantity - $available);

        return [
            'status' => $shortage > 0 ? 'shortage' : 'available',
            'inventory_mode' => $taskType === TaskType::Buy->value ? 'replenishment' : ($shortage > 0 ? 'blocked' : 'available'),
            'is_actionable' => $taskType === TaskType::Buy->value || $shortage <= 0,
            'missing_resources' => $shortage > 0 ? [[
                'resource_name' => $item,
                'shortage_quantity' => $shortage,
                'unit' => $inventory?->unit?->value ?? InventoryUnit::Unit->value,
            ]] : [],
            'shortage_quantity' => $shortage,
            'unit' => $inventory?->unit?->value ?? InventoryUnit::Unit->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function weatherContext(int $offset): array
    {
        return match (true) {
            $offset === -2 => ['rule' => 'rain_skip', 'message' => 'Po neseno lietaus laistymo galima sumažinti.'],
            $offset === 1 => ['rule' => 'wind_protection', 'message' => 'Prognozuojamas vėjas; sutvirtinkite aukštus augalus.'],
            $offset === 5 => ['rule' => 'heat_watch', 'message' => 'Laukiamas šiltas laikotarpis; jautrius lapinius augalus skinkite anksčiau.'],
            default => ['rule' => 'normal', 'message' => 'Įprastas sezoninės priežiūros darbas.'],
        };
    }

    private function taskReason(string $type, int $offset): string
    {
        return match ($type) {
            TaskType::Watering->value => $offset < 0 ? 'Atlikta pagal įprastą laistymo intervalą.' : 'Rekomenduojama pagal augalo priežiūros intervalą ir dabartinius orus.',
            TaskType::Fertilize->value => 'Rekomenduojama pagal tręšimo intervalą ir aktyvaus augimo etapą.',
            TaskType::Harvest->value => 'Augalo branda ir derliaus langas rodo, kad verta atlikti derliaus patikrą.',
            TaskType::Spray->value => 'Atėjo kenkėjų ir ligų stebėsenos patikros laikas.',
            TaskType::Buy->value => 'Aptiktas inventoriaus trūkumas būsimiems priežiūros darbams.',
            default => 'Rekomenduojamas sezoninės priežiūros darbas.',
        };
    }

    private function taskComment(string $type): string
    {
        return match ($type) {
            TaskType::Watering->value => 'Laistykite ties dirvos paviršiumi ir dienos pabaigoje nešlapinkite lapų.',
            TaskType::Fertilize->value => 'Tręškite saikingai ir po tręšimo palaistykite.',
            TaskType::Harvest->value => 'Užbaigę įrašykite derliaus kiekį.',
            TaskType::Spray->value => 'Prieš taikydami priemonę patikrinkite abi lapų puses.',
            TaskType::Buy->value => 'Papildykite atsargas prieš atlikdami blokuojamas užduotis.',
            default => 'Patikrinkite augalo būklę ir pažymėkite tolesnius darbus.',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogDefinitions(): array
    {
        $base = [
            ['Tomato', PlantType::Vegetable->value, 'Solanaceae', 'Solanum lycopersicum', 85, 7, 28, 70, 45, 0, 2, 14, 5, 8.0, 8.0, 30.0, 45.0, '6.0-6.8', 50, 0.6, ['Basil', 'Marigold'], 'Feed when first flowers set; keep moisture even.', 'Prune lower leaves and monitor blight after rain.'],
            ['Cucumber', PlantType::Vegetable->value, 'Cucurbitaceae', 'Cucumis sativus', 60, 5, 25, 50, 35, 0, 2, 14, 5, 8.0, 10.0, 30.0, 40.0, '6.0-7.0', 45, 2.0, ['Dill', 'Bean'], 'Compost before planting and feed when vines run.', 'Use trellis and water consistently.'],
            ['Carrot', PlantType::Vegetable->value, 'Apiaceae', 'Daucus carota', 75, 14, 0, 65, 30, 0, 4, 21, 10, 10.0, -2.0, 28.0, 45.0, '6.0-6.8', 5, 1.0, ['Onion', 'Lettuce'], 'Avoid excess nitrogen to prevent forked roots.', 'Keep seedbed evenly moist during germination.'],
            ['Potato', PlantType::Vegetable->value, 'Solanaceae', 'Solanum tuberosum', 105, 14, 25, 90, 35, 0, 4, 21, 7, 12.0, 1.0, 29.0, 45.0, '5.0-6.5', 35, 10.0, ['Bean', 'Calendula'], 'Hill with compost-rich soil as stems grow.', 'Avoid following tomatoes or peppers.'],
            ['Onion', PlantType::Vegetable->value, 'Amaryllidaceae', 'Allium cepa', 110, 10, 0, 90, 30, 0, 5, 28, 10, 10.0, -3.0, 29.0, 45.0, '6.0-7.0', 10, 2.0, ['Carrot', 'Beetroot'], 'Light feeding early, then reduce near bulb maturity.', 'Keep weeds down around shallow roots.'],
            ['Garlic', PlantType::Vegetable->value, 'Amaryllidaceae', 'Allium sativum', 240, 14, 0, 210, 35, 0, 7, 30, 14, 12.0, -12.0, 28.0, 45.0, '6.0-7.0', 12, 5.0, ['Strawberry', 'Carrot'], 'Feed in spring when leaves resume growth.', 'Stop watering as leaves yellow before harvest.'],
            ['Lettuce', PlantType::Vegetable->value, 'Asteraceae', 'Lactuca sativa', 45, 5, 0, 35, 21, 0, 3, 14, 7, 8.0, -2.0, 26.0, 35.0, '6.0-7.0', 25, 0.5, ['Carrot', 'Radish'], 'Lengvas azoto tręšimas palaiko lapų augimą.', 'Išorinius lapus nuskinkite prieš karščius.'],
            ['Spinach', PlantType::Vegetable->value, 'Amaranthaceae', 'Spinacia oleracea', 45, 7, 0, 35, 18, 0, 3, 14, 7, 8.0, -4.0, 25.0, 35.0, '6.5-7.5', 20, 1.0, ['Pea', 'Strawberry'], 'Use compost and avoid high heat stress.', 'Best in cool weather with steady moisture.'],
            ['Radish', PlantType::Vegetable->value, 'Brassicaceae', 'Raphanus sativus', 30, 4, 0, 25, 10, 0, 3, 14, 7, 8.0, -2.0, 27.0, 35.0, '6.0-7.0', 5, 1.0, ['Lettuce', 'Pea'], 'Dažniausiai pakanka komposto.', 'Nuimkite laiku, kad šaknys nesumedėtų.'],
            ['Beetroot', PlantType::Vegetable->value, 'Amaranthaceae', 'Beta vulgaris', 60, 8, 0, 50, 25, 0, 4, 21, 7, 9.0, -2.0, 28.0, 40.0, '6.0-7.5', 10, 2.0, ['Onion', 'Lettuce'], 'Compost before sowing; avoid overfeeding.', 'Thin seedlings for larger roots.'],
            ['Cabbage', PlantType::Vegetable->value, 'Brassicaceae', 'Brassica oleracea var. capitata', 95, 5, 0, 80, 30, 0, 3, 18, 5, 10.0, -4.0, 27.0, 45.0, '6.5-7.5', 45, 1.0, ['Dill', 'Calendula'], 'Feed regularly during head formation.', 'Inspect for caterpillars weekly.'],
            ['Broccoli', PlantType::Vegetable->value, 'Brassicaceae', 'Brassica oleracea var. italica', 80, 5, 0, 65, 25, 21, 3, 18, 5, 10.0, -3.0, 27.0, 45.0, '6.5-7.5', 45, 1.0, ['Dill', 'Calendula'], 'Saikingas tręšimas palaiko pagrindinę galvutę ir šoninius ūglius.', 'Nuimkite, kol žiedpumpuriai nepradėjo skleistis.'],
            ['Pepper', PlantType::Vegetable->value, 'Solanaceae', 'Capsicum annuum', 85, 8, 30, 75, 45, 0, 3, 18, 7, 8.0, 10.0, 31.0, 40.0, '6.0-6.8', 45, 0.6, ['Basil', 'Marigold'], 'Use balanced feed after fruit set.', 'Protect from cold nights and wind.'],
            ['Chili Pepper', PlantType::Vegetable->value, 'Solanaceae', 'Capsicum annuum', 95, 8, 35, 80, 50, 0, 3, 18, 7, 8.0, 10.0, 32.0, 40.0, '6.0-6.8', 45, 0.6, ['Basil', 'Calendula'], 'Feed lightly but consistently during fruiting.', 'Do not overwater while fruit ripens.'],
            ['Zucchini', PlantType::Vegetable->value, 'Cucurbitaceae', 'Cucurbita pepo', 55, 6, 25, 45, 45, 0, 2, 14, 5, 8.0, 8.0, 30.0, 40.0, '6.0-7.0', 90, 2.5, ['Marigold', 'Corn'], 'Prieš sodinimą gausiai įterpkite komposto.', 'Mažus vaisius skinkite dažnai.'],
            ['Pumpkin', PlantType::Vegetable->value, 'Cucurbitaceae', 'Cucurbita pepo', 110, 6, 35, 95, 45, 0, 3, 21, 7, 10.0, 8.0, 31.0, 40.0, '6.0-7.0', 120, 3.0, ['Corn', 'Bean'], 'Feed when vines start running.', 'Give vines space and keep fruit off wet soil.'],
            ['Strawberry', PlantType::Berry->value, 'Rosaceae', 'Fragaria x ananassa', 120, 21, 25, 90, 35, 30, 3, 21, 7, 10.0, -6.0, 29.0, 40.0, '5.8-6.5', 35, 0.5, ['Garlic', 'Spinach'], 'Feed after harvest and renew mulch.', 'Keep fruit clean with straw mulch.'],
            ['Raspberry', PlantType::Berry->value, 'Rosaceae', 'Rubus idaeus', 180, 21, 35, 120, 50, 45, 5, 30, 7, 12.0, -15.0, 30.0, 45.0, '5.8-6.8', 60, 2.0, ['Calendula', 'Garlic'], 'Compost canes in spring.', 'Prune spent canes after fruiting.'],
            ['Blueberry', PlantType::Shrub->value, 'Ericaceae', 'Vaccinium corymbosum', 180, 30, 30, 120, 45, 45, 4, 30, 10, 10.0, -15.0, 30.0, 45.0, '4.5-5.5', 90, 1.0, ['Thyme', 'Strawberry'], 'Use acid-loving fertilizer only.', 'Mulch with bark and keep soil moist.'],
            ['Apple Mint', PlantType::Herb->value, 'Lamiaceae', 'Mentha suaveolens', 80, 15, 50, 60, 30, 21, 3, 21, 10, 8.0, -10.0, 30.0, 35.0, '6.0-7.0', 35, 0.5, ['Cabbage', 'Tomato'], 'Light compost in spring is enough.', 'Keep contained and cut frequently.'],
            ['Basil', PlantType::Herb->value, 'Lamiaceae', 'Ocimum basilicum', 50, 7, 35, 35, 21, 14, 3, 21, 7, 6.0, 10.0, 29.0, 30.0, '6.0-7.0', 30, 0.5, ['Tomato', 'Pepper'], 'Light feeding supports leaf growth.', 'Pinch flowers for more leaves.'],
            ['Parsley', PlantType::Herb->value, 'Apiaceae', 'Petroselinum crispum', 90, 21, 0, 75, 40, 21, 4, 21, 7, 7.0, -4.0, 29.0, 35.0, '6.0-7.0', 25, 1.0, ['Tomato', 'Asparagus'], 'Kompostu turtinga dirva palaiko pakartotinį skynimą.', 'Pirmiausia skinkite išorinius stiebus.'],
            ['Dill', PlantType::Herb->value, 'Apiaceae', 'Anethum graveolens', 55, 10, 40, 45, 20, 0, 4, 21, 7, 7.0, 2.0, 28.0, 35.0, '5.5-7.0', 25, 0.5, ['Cucumber', 'Cabbage'], 'Avoid heavy feeding.', 'Sow succession crops because it bolts.'],
            ['Thyme', PlantType::Herb->value, 'Lamiaceae', 'Thymus vulgaris', 100, 14, 60, 70, 45, 30, 7, 45, 14, 6.0, -12.0, 32.0, 45.0, '6.0-8.0', 30, 0.3, ['Cabbage', 'Strawberry'], 'Very light feeding only.', 'Prefers dry, well-drained soil.'],
            ['Rosemary', PlantType::Shrub->value, 'Lamiaceae', 'Salvia rosmarinus', 160, 21, 90, 120, 60, 45, 7, 45, 14, 6.0, -5.0, 32.0, 45.0, '6.0-7.5', 80, 0.5, ['Cabbage', 'Carrot'], 'Avoid rich wet soil.', 'Prune lightly after active growth.'],
            ['Pea', PlantType::Legume->value, 'Fabaceae', 'Pisum sativum', 65, 7, 25, 55, 25, 0, 4, 30, 7, 8.0, -3.0, 27.0, 40.0, '6.0-7.5', 8, 3.0, ['Carrot', 'Radish'], 'Usually does not need nitrogen fertilizer.', 'Provide support early.'],
            ['Bean', PlantType::Legume->value, 'Fabaceae', 'Phaseolus vulgaris', 60, 7, 25, 50, 35, 0, 4, 30, 7, 8.0, 6.0, 30.0, 35.0, '6.0-7.0', 20, 3.0, ['Corn', 'Cucumber'], 'Avoid heavy nitrogen feed.', 'Pick young pods regularly.'],
            ['Corn', PlantType::Cereal->value, 'Poaceae', 'Zea mays', 90, 7, 30, 75, 30, 0, 3, 21, 7, 10.0, 5.0, 32.0, 45.0, '6.0-7.0', 30, 3.0, ['Bean', 'Pumpkin'], 'Needs nitrogen during rapid growth.', 'Plant in blocks for pollination.'],
            ['Marigold', PlantType::Flower->value, 'Asteraceae', 'Tagetes patula', 70, 5, 60, 45, 60, 0, 4, 30, 10, 7.0, 2.0, 32.0, 35.0, '6.0-7.5', 25, 0.5, ['Tomato', 'Cucumber'], 'Little feeding needed.', 'Deadhead for continued bloom.'],
            ['Calendula', PlantType::Flower->value, 'Asteraceae', 'Calendula officinalis', 70, 7, 60, 45, 60, 0, 4, 30, 10, 7.0, -2.0, 30.0, 35.0, '6.0-7.0', 25, 0.5, ['Cabbage', 'Raspberry'], 'Compost is usually enough.', 'Self-seeds and attracts beneficial insects.'],
            ['Apple Tree', PlantType::Tree->value, 'Rosaceae', 'Malus domestica', 240, 21, 20, 160, 60, 60, 7, 45, 10, 12.0, -18.0, 32.0, 55.0, '6.0-7.0', 400, 5.0, ['Thyme', 'Calendula'], 'Feed lightly in spring if growth is weak.', 'Prune in dormancy and monitor scab.'],
        ];

        return array_map(function (array $item): array {
            [$name, $type, $family, $scientific, $growing, $germinating, $flowering, $mature, $harvestWindow, $regenerating, $watering, $fertilizing, $pestCheck, $rainSkip, $frost, $heat, $wind, $soilPh, $spacing, $depth, $companions, $fertilization, $notes] = $item;

            return [
                'name' => $name,
                'type' => $type,
                'family' => $family,
                'scientific' => $scientific,
                'description' => "{$name} is included in the demo catalog with complete local care defaults for calendar planning.",
                'conditions' => "Best with appropriate spacing, soil pH {$soilPh}, and care matched to the {$family} crop family.",
                'growing' => $growing,
                'germinating' => $germinating,
                'flowering' => $flowering,
                'mature' => $mature,
                'harvest_window' => $harvestWindow,
                'regenerating' => $regenerating,
                'reusable' => in_array($type, [PlantType::Berry->value, PlantType::Herb->value, PlantType::Shrub->value, PlantType::Tree->value], true),
                'watering' => $watering,
                'fertilizing' => $fertilizing,
                'pest_check' => $pestCheck,
                'rain_skip' => $rainSkip,
                'frost' => $frost,
                'heat' => $heat,
                'wind' => $wind,
                'soil_ph' => $soilPh,
                'spacing_cm' => $spacing,
                'depth_cm' => $depth,
                'companions' => $companions,
                'fertilization' => $fertilization,
                'notes' => $notes,
            ];
        }, $base);
    }
}
