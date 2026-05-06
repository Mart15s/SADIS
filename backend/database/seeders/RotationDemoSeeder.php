<?php

namespace Database\Seeders;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Models\CatalogPlant;
use App\Models\GardenOwner;
use App\Models\HasPlot;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\RotationHistory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RotationDemoSeeder extends Seeder
{
    private const EMAIL = 'rotation.demo@example.com';

    private const CATALOG = [
        ['key' => 'tomato', 'name' => 'Tomato', 'family' => 'Solanaceae'],
        ['key' => 'pepper', 'name' => 'Pepper', 'family' => 'Solanaceae'],
        ['key' => 'potato', 'name' => 'Potato', 'family' => 'Solanaceae'],
        ['key' => 'cabbage', 'name' => 'Cabbage', 'family' => 'Brassicaceae'],
        ['key' => 'radish', 'name' => 'Radish', 'family' => 'Brassicaceae'],
        ['key' => 'bean', 'name' => 'Bean', 'family' => 'Fabaceae', 'type' => PlantType::Legume],
        ['key' => 'pea', 'name' => 'Pea', 'family' => 'Fabaceae', 'type' => PlantType::Legume],
        ['key' => 'carrot', 'name' => 'Carrot', 'family' => 'Apiaceae'],
        ['key' => 'lettuce', 'name' => 'Lettuce', 'family' => 'Asteraceae'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->cleanup();

            $user = User::query()->create([
                'email' => self::EMAIL,
                'password' => 'RotationDemo123!',
                'role' => 'owner',
            ]);
            $profile = Profile::query()->create([
                'user_id' => $user->id,
                'name' => 'Rotation',
                'surname' => 'Demo',
            ]);
            $owner = GardenOwner::query()->create([
                'id' => $user->id,
                'user_id' => $user->id,
                'id_user' => $user->id,
                'fk_profile_id' => $profile->id,
            ]);

            $catalog = $this->seedCatalog();
            $plot = Plot::query()->create([
                'garden_owner_id' => $owner->id,
                'name' => 'Rotation demo garden',
                'city' => 'Vilnius',
                'plot_size' => 120,
                'creation_date' => '2026-04-01',
                'description' => 'Demo plot for crop rotation checks.',
                'share' => false,
            ]);
            HasPlot::query()->create([
                'fk_plot_id' => $plot->id,
                'fk_owner_id' => $owner->id_user,
                'fk_profile_id' => $owner->fk_profile_id,
            ]);

            $zones = [
                'a' => $this->createZone($plot, 'Zone A - old nightshades', 0, 0),
                'b' => $this->createZone($plot, 'Zone B - brassicas', 260, 0),
                'c' => $this->createZone($plot, 'Zone C - legumes', 0, 160),
                'd' => $this->createZone($plot, 'Zone D - empty rotation', 260, 160),
            ];

            $plants = [
                'tomato_2025' => $this->createPlant($plot, $zones['a'], $catalog['tomato'], 'Tomato 2025', '2025-04-15'),
                'pepper_2026' => $this->createPlant($plot, $zones['d'], $catalog['pepper'], 'Pepper planned 2026', '2026-04-15'),
                'cabbage_2025' => $this->createPlant($plot, $zones['b'], $catalog['cabbage'], 'Cabbage 2025', '2025-04-20'),
                'bean_2025' => $this->createPlant($plot, $zones['c'], $catalog['bean'], 'Bean 2025', '2025-04-25'),
                'carrot_2024' => $this->createPlant($plot, $zones['d'], $catalog['carrot'], 'Carrot 2024', '2024-04-20'),
                'potato_2024' => $this->createPlant($plot, $zones['a'], $catalog['potato'], 'Potato 2024', '2024-04-15'),
                'lettuce_2026' => $this->createPlant($plot, $zones['d'], $catalog['lettuce'], 'Lettuce planned 2026', '2026-04-18'),
            ];

            $this->recordHistory($plot, $zones['a'], $plants['potato_2024'], '2024-04-15', '2024-09-01');
            $this->recordHistory($plot, $zones['a'], $plants['tomato_2025'], '2025-04-15', '2025-09-01');
            $this->recordHistory($plot, $zones['b'], $plants['cabbage_2025'], '2025-04-20', '2025-08-30');
            $this->recordHistory($plot, $zones['c'], $plants['bean_2025'], '2025-04-25', '2025-08-20');
            $this->recordHistory($plot, $zones['d'], $plants['carrot_2024'], '2024-04-20', '2024-08-15');

            if ($this->command) {
                $this->command->info('Rotation demo data prepared.');
                $this->command->line('Email: '.self::EMAIL);
                $this->command->line('Password: RotationDemo123!');
            }
        });
    }

    private function cleanup(): void
    {
        $user = User::query()->where('email', self::EMAIL)->first();

        if ($user) {
            $owner = GardenOwner::query()
                ->where('id_user', $user->id)
                ->orWhere('user_id', $user->id)
                ->first();
            $plotIds = $owner ? Plot::query()->where('garden_owner_id', $owner->id)->pluck('id') : collect();
            $zoneIds = PlantZone::query()->whereIn('plot_id', $plotIds)->pluck('id');
            $plantIds = Plant::query()->whereIn('fk_plot_id', $plotIds)->pluck('id');

            RotationHistory::query()
                ->whereIn('fk_plot_id', $plotIds)
                ->orWhereIn('fk_plant_zone_id', $zoneIds)
                ->orWhereIn('fk_plant_id', $plantIds)
                ->delete();
            Plant::query()->whereIn('id', $plantIds)->delete();
            PlantZone::query()->whereIn('id', $zoneIds)->delete();
            HasPlot::query()->whereIn('fk_plot_id', $plotIds)->delete();
            Plot::query()->whereIn('id', $plotIds)->delete();

            if ($owner) {
                Profile::query()->where('id', $owner->fk_profile_id)->delete();
                $owner->delete();
            }

            $user->delete();
        }

        $catalogIds = CatalogPlant::query()
            ->where('source_provider', 'rotation-demo')
            ->pluck('id');

        CatalogPlant::query()->whereIn('id', $catalogIds)->delete();
        PlantCare::query()->where('source_provider', 'rotation-demo')->delete();
    }

    /**
     * @return array<string, CatalogPlant>
     */
    private function seedCatalog(): array
    {
        $catalog = [];

        foreach (self::CATALOG as $entry) {
            $type = $entry['type'] ?? PlantType::Vegetable;
            $care = PlantCare::query()->create([
                'description' => $entry['name'].' rotation demo care profile.',
                'conditions' => 'well drained fertile soil',
                'plant_name' => $entry['name'],
                'canonical_name' => 'rotation-demo-'.strtolower($entry['key']),
                'task_type' => 'watering',
                'plant_type' => $type,
                'condition' => ConditionType::Growing,
                'watering_interval_days' => 3,
                'fertilizing_interval_days' => 14,
                'pest_check_interval_days' => 7,
                'source_provider' => 'rotation-demo',
                'source_quality' => 'demo',
                'source_common_name' => $entry['name'],
                'source_scientific_name' => $entry['name'],
                'source_family' => $entry['family'],
            ]);

            $catalog[$entry['key']] = CatalogPlant::query()->create([
                'name' => $entry['name'],
                'canonical_name' => 'rotation-demo-'.strtolower($entry['key']),
                'plant_type' => $type,
                'fk_plant_care_id' => $care->id,
                'description' => $care->description,
                'source_provider' => 'rotation-demo',
                'source_quality' => 'demo',
                'source_scientific_name' => $entry['name'],
                'source_family' => $entry['family'],
                'metadata' => ['seeded_for_rotation_demo' => true],
            ]);
        }

        return $catalog;
    }

    private function createZone(Plot $plot, string $name, int $x, int $y): PlantZone
    {
        return PlantZone::query()->create([
            'name' => $name,
            'zone_size' => 25,
            'soil_type' => SoilType::Clay,
            'rotation_stage' => 0,
            'last_planting_date' => null,
            'plot_id' => $plot->id,
            'fk_plot_id' => $plot->id,
            'geometry' => [
                'type' => 'polygon',
                'points' => [
                    ['x' => $x, 'y' => $y],
                    ['x' => $x + 220, 'y' => $y],
                    ['x' => $x + 220, 'y' => $y + 120],
                    ['x' => $x, 'y' => $y + 120],
                ],
            ],
        ]);
    }

    private function createPlant(Plot $plot, PlantZone $zone, CatalogPlant $catalogPlant, string $name, string $plantDate): Plant
    {
        return Plant::query()->create([
            'name' => $name,
            'plant_date' => $plantDate,
            'rest_time_days' => 30,
            'plant_size' => 2.5,
            'reusable' => false,
            'type' => $catalogPlant->plant_type,
            'condition' => ConditionType::Growing,
            'fk_catalog_plant_id' => $catalogPlant->id,
            'plant_zone_id' => $zone->id,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
        ]);
    }

    private function recordHistory(Plot $plot, PlantZone $zone, Plant $plant, string $fromDate, string $toDate): void
    {
        RotationHistory::query()->create([
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_via_zone' => $plot->id,
            'fk_plant_id' => $plant->id,
        ]);
    }
}
