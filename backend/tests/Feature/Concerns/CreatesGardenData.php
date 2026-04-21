<?php

namespace Tests\Feature\Concerns;

use App\Enums\AccessRole;
use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Models\AccessRight;
use App\Models\CatalogPlant;
use App\Models\GardenOwner;
use App\Models\HasPlot;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantConditionHistory;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\RotationHistory;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\User;
use App\Support\PlantCareName;

trait CreatesGardenData
{
    protected function createGardenOwner(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $profile = Profile::query()->create([
            'name' => 'Vardenis',
            'surname' => 'Pavardenis',
        ]);

        $owner = GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        return [$user, $owner, $profile];
    }

    protected function createPlotForOwner(GardenOwner $owner, array $overrides = []): Plot
    {
        $plot = Plot::query()->create(array_merge([
            'garden_owner_id' => $owner->id,
            'name' => 'Testinis sklypas',
            'city' => 'Vilnius',
            'plot_size' => 120,
            'creation_date' => '2026-03-20',
            'description' => 'Bandymu sklypas',
            'share' => true,
        ], $overrides));

        HasPlot::query()->create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return $plot;
    }

    protected function createZoneForPlot(Plot $plot, array $overrides = []): PlantZone
    {
        return PlantZone::query()->create(array_merge([
            'name' => 'Zona A',
            'zone_size' => 25,
            'soil_type' => SoilType::Clay,
            'rotation_stage' => 0,
            'last_planting_date' => '2026-03-19',
            'fk_plot_id' => $plot->id,
        ], $overrides));
    }

    protected function createPlantForPlot(Plot $plot, PlantZone $zone, array $overrides = []): Plant
    {
        if (array_key_exists('fk_plant_care_id', $overrides)) {
            $careId = $overrides['fk_plant_care_id'];
            unset($overrides['fk_plant_care_id']);

            if ($careId !== null) {
                $care = PlantCare::query()->findOrFail($careId);
                $catalogPlantId = $overrides['fk_catalog_plant_id'] ?? null;

                if ($catalogPlantId !== null) {
                    $catalogPlant = CatalogPlant::query()->findOrFail($catalogPlantId);

                    if ((int) $catalogPlant->fk_plant_care_id !== (int) $care->id) {
                        $catalogPlant->forceFill(['fk_plant_care_id' => $care->id])->save();
                    }
                } else {
                    $catalogPlant = CatalogPlant::query()->firstOrCreate(
                        ['canonical_name' => $care->canonical_name ?? PlantCareName::normalize($care->plant_name ?? ($overrides['name'] ?? null)) ?? 'plant'],
                        [
                            'name' => $care->plant_name ?? ($overrides['name'] ?? 'Plant'),
                            'plant_type' => $care->plant_type?->value ?? $care->plant_type,
                            'fk_plant_care_id' => $care->id,
                            'description' => $care->description,
                            'source_provider' => $care->source_provider,
                            'source_quality' => $care->source_quality,
                            'source_scientific_name' => $care->source_scientific_name,
                            'source_family' => $care->source_family,
                            'source_image_url' => $care->source_image_url,
                            'metadata' => null,
                        ]
                    );

                    if ((int) $catalogPlant->fk_plant_care_id !== (int) $care->id) {
                        $catalogPlant->forceFill(['fk_plant_care_id' => $care->id])->save();
                    }
                }

                $overrides['fk_catalog_plant_id'] = $catalogPlant->id;
                $overrides['name'] ??= $catalogPlant->name;
                $overrides['type'] ??= $catalogPlant->plant_type?->value ?? $catalogPlant->plant_type;
            }
        }

        return Plant::query()->create(array_merge([
            'name' => 'Pomidoras',
            'plant_date' => '2026-03-20',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Growing,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
        ], $overrides));
    }

    protected function createAccessRight(
        GardenOwner $grantor,
        Plot $plot,
        GardenOwner $recipient,
        AccessRole $role
    ): AccessRight {
        return AccessRight::query()->create([
            'granted_at' => now(),
            'role' => $role->value,
            'fk_plot_id' => $plot->id,
            'fk_grantor_owner_id' => $grantor->id_user,
            'fk_grantor_profile_id' => $grantor->fk_profile_id,
            'fk_recipient_owner_id' => $recipient->id_user,
            'fk_recipient_profile_id' => $recipient->fk_profile_id,
        ]);
    }

    protected function createCalendarForPlot(Plot $plot, array $overrides = []): TaskCalendar
    {
        return TaskCalendar::query()->create(array_merge([
            'creation_date' => now(),
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-27',
            'fk_plot_id' => $plot->id,
        ], $overrides));
    }

    protected function createTaskForCalendar(TaskCalendar $calendar, array $overrides = []): Task
    {
        return Task::query()->create(array_merge([
            'date' => '2026-03-20',
            'name' => 'Testine uzduotis',
            'type' => 'watering',
            'status' => 'pending',
            'fk_task_calendar_id' => $calendar->id,
            'fk_plant_id' => null,
        ], $overrides));
    }

    protected function createConditionHistoryForPlant(Plant $plant, array $overrides = []): PlantConditionHistory
    {
        return PlantConditionHistory::query()->create(array_merge([
            'measured_at' => now(),
            'notes' => 'Bukle stebima',
            'condition' => ConditionType::Growing,
            'fk_plant_id' => $plant->id,
        ], $overrides));
    }

    protected function createRotationHistoryForPlot(
        Plot $plot,
        ?PlantZone $zone = null,
        ?Plant $plant = null,
        array $overrides = []
    ): RotationHistory {
        return RotationHistory::query()->create(array_merge([
            'from_date' => '2026-03-01',
            'to_date' => '2026-03-20',
            'fk_plot_id' => $plot->id,
            'fk_plant_zone_id' => $zone?->id,
            'fk_plot_via_zone' => $plot->id,
            'fk_plant_id' => $plant?->id,
        ], $overrides));
    }
}
