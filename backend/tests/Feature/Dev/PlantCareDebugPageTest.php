<?php

namespace Tests\Feature\Dev;

use App\Models\User;
use App\Models\Plot;
use App\Models\TaskCalendar;
use App\Models\WeatherForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlantCareDebugPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_dev_search_returns_raw_payload_and_cache_source_metadata(): void
    {
        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::fake([
            'https://perenual.test/api/species-list*' => Http::response([
                'data' => [
                    [
                        'id' => 987,
                        'common_name' => 'Tomato',
                        'scientific_name' => ['Solanum lycopersicum'],
                        'family' => 'Solanaceae',
                        'sunlight' => ['full sun'],
                        'watering' => 'average',
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/api/dev/plant-care-test/search?q=tomato')
            ->assertOk()
            ->assertJsonPath('request.source', 'live_api')
            ->assertJsonPath('results.0.id', 987)
            ->assertJsonPath('results.0.family', 'Solanaceae')
            ->assertJsonPath('raw_response.0.common_name', 'Tomato');

        $this->getJson('/api/dev/plant-care-test/search?q=tomato')
            ->assertOk()
            ->assertJsonPath('request.source', 'cache');

        Http::assertSentCount(1);
    }

    public function test_dev_species_returns_mapping_trace_and_care_guide_payload(): void
    {
        Config::set('services.perenual.key', 'test-key');
        Config::set('services.perenual.base_url', 'https://perenual.test/api');

        Http::fake([
            'https://perenual.test/api/species/details/830*' => Http::response([
                'id' => 830,
                'common_name' => 'Mint',
                'scientific_name' => ['Mentha'],
                'watering_general_benchmark' => [
                    'value' => 3,
                ],
                'sunlight' => ['partial shade'],
            ], 200),
            'https://perenual.test/api/species-care-guide-list*' => Http::response([
                'data' => [
                    [
                        'section' => 'watering',
                        'description' => 'Keep the soil evenly moist.',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/dev/plant-care-test/species/830?plant_name=Mint&plant_type=herb&care_guide_type=watering');

        $response->assertOk()
            ->assertJsonPath('details.request.source', 'live_api')
            ->assertJsonPath('care_guides.request.type', 'watering')
            ->assertJsonPath('normalization.metadata.source_perenual_species_id', 830)
            ->assertJsonPath('normalization.metadata.source_common_name', 'Mint');

        $response->assertJsonPath('backend_debug_payload.normalized.trace.plant_type.value', 'herb')
            ->assertJsonPath('backend_debug_payload.normalized.trace.plant_type.status', 'global_fallback')
            ->assertJsonPath('backend_debug_payload.normalized.trace.watering_interval_days.value', 3)
            ->assertJsonPath('backend_debug_payload.normalized.trace.watering_interval_days.status', 'direct_api')
            ->assertJsonPath('backend_debug_payload.normalized.trace.description.status', 'guide_derived');
    }

    public function test_dev_weather_returns_live_meteo_lt_output(): void
    {
        Config::set('services.meteo_lt.base_url', 'https://api.meteo.lt/v1');

        Http::fake([
            'https://api.meteo.lt/v1/places' => Http::response([
                [
                    'code' => 'vilnius',
                    'name' => 'Vilnius',
                    'countryCode' => 'LT',
                ],
            ], 200),
            'https://api.meteo.lt/v1/places/vilnius/forecasts/long-term' => Http::response([
                'forecastTimestamps' => [
                    [
                        'forecastTimeUtc' => '2026-04-08 06:00:00',
                        'airTemperature' => 9,
                        'relativeHumidity' => 65,
                        'totalPrecipitation' => 0,
                        'windSpeed' => 1.5,
                    ],
                    [
                        'forecastTimeUtc' => '2026-04-08 12:00:00',
                        'airTemperature' => 14,
                        'relativeHumidity' => 60,
                        'totalPrecipitation' => 1.2,
                        'windSpeed' => 2.5,
                    ],
                ],
            ], 200),
        ]);

        $this->getJson('/api/dev/plant-care-test/weather?city=Vilnius')
            ->assertOk()
            ->assertJsonPath('source', 'live_meteo_lt')
            ->assertJsonPath('request.place.source', 'live_api')
            ->assertJsonPath('resolved_place.code', 'vilnius')
            ->assertJsonPath('normalized.current.source', 'live_meteo_lt');
    }

    public function test_dev_weather_falls_back_to_stored_weather_forecasts(): void
    {
        Config::set('services.meteo_lt.base_url', 'https://api.meteo.lt/v1');

        $plot = Plot::query()->create([
            'name' => 'Debug Plot',
            'city' => 'Vilnius',
            'plot_size' => 12,
            'creation_date' => '2026-04-08',
            'description' => 'Temporary debug plot',
            'share' => false,
        ]);

        $calendar = TaskCalendar::query()->create([
            'creation_date' => now(),
            'start_date' => '2026-04-08',
            'end_date' => '2026-04-08',
            'fk_plot_id' => $plot->id,
        ]);

        WeatherForecast::query()->create([
            'date' => '2026-04-08',
            'temperature' => 11.5,
            'temp_min' => 8.1,
            'temp_max' => 14.9,
            'precipitation' => 2.4,
            'humidity' => 74,
            'wind_kmh' => 12.6,
            'is_seasonal_fallback' => false,
            'city' => 'Vilnius',
            'fk_task_calendar_id' => $calendar->id,
        ]);

        Http::fake([
            'https://api.meteo.lt/v1/places' => Http::response([], 500),
        ]);

        $this->getJson('/api/dev/plant-care-test/weather?city=Vilnius')
            ->assertOk()
            ->assertJsonPath('source', 'stored_weather_forecasts')
            ->assertJsonPath('request.forecast.source', 'fallback')
            ->assertJsonPath('normalized.current.source', 'stored_weather_forecasts');
    }
}
