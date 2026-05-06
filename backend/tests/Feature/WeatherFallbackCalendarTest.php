<?php

namespace Tests\Feature;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Models\GardenOwner;
use App\Models\HasPlot;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\User;
use App\Models\WeatherForecast;
use App\Services\Calendar\TaskCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherFallbackCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-24 09:00:00');
        config([
            'services.meteo_lt.base_url' => 'https://api.meteo.lt/v1',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_calendar_generation_uses_stored_forecast_source_date_when_meteo_lt_times_out(): void
    {
        [, $owner, $plot, $zone] = $this->createOwnedPlotContext();
        $care = PlantCare::query()->create([
            'description' => 'Fallback weather care',
            'conditions' => 'Rich soil, full sun.',
            'germinating_duration_days' => 1,
            'growing_duration_days' => 10,
            'flowering_duration_days' => 2,
            'mature_duration_days' => 4,
            'mature_duration_end_days' => 2,
            'mature_end_duration_days' => 2,
            'regenerating_duration_days' => 0,
            'reusable' => false,
            'plant_name' => 'Pomidoras',
            'canonical_name' => 'fallback-pomidoras',
            'task_type' => 'watering',
            'plant_type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'watering_interval_days' => 1,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'rain_skip_threshold_mm' => 6,
            'frost_temp_threshold_c' => 2,
            'heat_extra_water_temp_c' => 30,
            'wind_protection_kmh' => 35,
            'source_provider' => 'local',
            'source_quality' => 'partial',
        ]);

        Plant::query()->create([
            'name' => 'Pomidoras',
            'plant_date' => '2026-04-20',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Growing,
            'fk_plant_zone_id' => $zone->id,
            'plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
            'fk_catalog_plant_id' => \App\Models\CatalogPlant::query()->create([
                'name' => 'Pomidoras',
                'canonical_name' => 'fallback-catalog-pomidoras',
                'plant_type' => PlantType::Vegetable,
                'fk_plant_care_id' => $care->id,
                'description' => 'Fallback weather care',
                'source_provider' => 'local',
                'source_quality' => 'partial',
                'metadata' => null,
            ])->id,
        ]);

        $sourceCalendar = TaskCalendar::query()->create([
            'creation_date' => now()->subDay(),
            'start_date' => '2026-04-24',
            'end_date' => '2026-04-24',
            'plot_id' => $plot->id,
            'fk_plot_id' => $plot->id,
        ]);

        WeatherForecast::query()->create([
            'task_calendar_id' => $sourceCalendar->id,
            'fk_task_calendar_id' => $sourceCalendar->id,
            'date' => '2026-04-24',
            'temperature' => 12,
            'temp_min' => 8,
            'temp_max' => 16,
            'precipitation' => 0,
            'humidity' => 71,
            'wind_kmh' => 6,
            'condition_code' => 'clear',
            'is_seasonal_fallback' => false,
            'source' => 'api',
            'source_date' => '2026-04-23',
            'source_city' => 'Vilnius',
            'city' => 'Vilnius',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.meteo.lt')) {
                throw new ConnectionException('Timed out while contacting Meteo.lt');
            }

            throw new \RuntimeException('Unexpected HTTP request in weather fallback test.');
        });

        $calendar = app(TaskCalendarService::class)->generate(
            $plot->fresh([
                'gardenOwner',
                'plantZones.rotationHistory',
                'plantZones.plants.catalogPlant.plantCare',
                'plantZones.plants.conditionHistory',
                'plantZones.plants.harvestRecords',
            ]),
            Carbon::parse('2026-04-24')->startOfDay(),
            Carbon::parse('2026-04-24')->startOfDay(),
        );

        $generatedForecast = WeatherForecast::query()
            ->where('task_calendar_id', $calendar->id)
            ->firstOrFail();

        $this->assertSame('stored_city_date', $generatedForecast->source);
        $this->assertSame('2026-04-23', $generatedForecast->source_date?->toDateString());
        $this->assertSame('Vilnius', $generatedForecast->source_city);
        $this->assertTrue(
            Task::query()
                ->where('task_calendar_id', $calendar->id)
                ->where('task_type', 'watering')
                ->exists()
        );
        $this->assertSame($owner->id, $plot->fresh()->garden_owner_id);
    }

    /**
     * @return array{0: User, 1: GardenOwner, 2: Plot, 3: PlantZone}
     */
    private function createOwnedPlotContext(): array
    {
        $user = User::factory()->create();
        $profile = Profile::query()->create([
            'user_id' => $user->id,
            'name' => 'Milda',
            'surname' => 'Oriene',
        ]);

        $owner = GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        $plot = Plot::query()->create([
            'garden_owner_id' => $owner->id,
            'name' => 'Fallback plot',
            'city' => 'Vilnius',
            'plot_size' => 42,
            'creation_date' => '2026-04-01',
            'description' => 'Plot for offline weather fallback testing.',
            'share' => false,
        ]);

        HasPlot::query()->create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        $zone = PlantZone::query()->create([
            'name' => 'Fallback zone',
            'zone_size' => 18,
            'soil_type' => 'greasy',
            'rotation_stage' => 0,
            'last_planting_date' => '2026-04-20',
            'plot_id' => $plot->id,
            'fk_plot_id' => $plot->id,
        ]);

        return [$user, $owner, $plot, $zone];
    }
}
