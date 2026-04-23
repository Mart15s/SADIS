<?php

namespace Tests\Feature;

use App\Enums\ConditionType;
use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Models\GardenOwner;
use App\Models\HasInventory;
use App\Models\HasPlot;
use App\Models\InventoryItem;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\TaskResourceRequirement;
use App\Models\User;
use App\Services\TaskCalendarService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CalendarGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-20 09:00:00');
        Cache::flush();
        config([
            'services.perenual.key' => 'test-perenual-key',
            'services.meteo_lt.base_url' => 'https://api.meteo.lt/v1',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_plot_with_no_plants_returns_contract_message(): void
    {
        [$user, , $plot] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/plots/{$plot->id}/calendars", [
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-21',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Šiam planui generuoti rekomendacinio veiksmų kalendoriaus negalima — plane nėra augalų.');
    }

    public function test_growth_stage_uses_generation_date_instead_of_today(): void
    {
        Carbon::setTestNow('2026-05-01 10:00:00');

        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'germinating_duration_days' => 2,
            'growing_duration_days' => 2,
            'flowering_duration_days' => 1,
            'mature_duration_days' => 1,
            'mature_duration_end_days' => 1,
            'regenerating_duration_days' => 0,
            'reusable' => false,
        ]);
        $plant = $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-20',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-21 12:00:00', 'temp_min' => 10, 'temp_max' => 18, 'rain' => 0, 'wind_kmh' => 8],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-21', '2026-03-21');

        $this->assertTrue(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('fk_plant_id', $plant->id)
                ->whereDate('date', '2026-03-21')
                ->where('type', 'watering')
                ->exists()
        );
    }

    public function test_disease_flag_forces_diseased_stage(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
        ]);
        $plant = $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-20',
            'disease' => true,
            'disease_notes' => 'miltlige',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 10, 'temp_max' => 18, 'rain' => 0, 'wind_kmh' => 8],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertTrue(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('fk_plant_id', $plant->id)
                ->where('type', 'spray')
                ->exists()
        );
    }

    public function test_generation_date_before_plant_date_creates_no_tasks_for_that_day(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare(['watering_interval_days' => 1]);
        $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-25',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 9, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 6],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertSame(0, $calendar->tasks()->count());
    }

    public function test_non_reusable_plant_becomes_dried_after_lifecycle_and_has_no_active_tasks(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'germinating_duration_days' => 1,
            'growing_duration_days' => 1,
            'flowering_duration_days' => 0,
            'mature_duration_days' => 1,
            'mature_duration_end_days' => 1,
            'regenerating_duration_days' => 0,
            'reusable' => false,
        ]);
        $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-01',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-15 12:00:00', 'temp_min' => 8, 'temp_max' => 14, 'rain' => 0, 'wind_kmh' => 5],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-15', '2026-03-15');

        $this->assertSame(0, $calendar->tasks()->count());
    }

    public function test_reusable_plant_cycles_instead_of_becoming_permanently_dried(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'germinating_duration_days' => 1,
            'growing_duration_days' => 1,
            'flowering_duration_days' => 0,
            'mature_duration_days' => 1,
            'mature_duration_end_days' => 1,
            'regenerating_duration_days' => 1,
            'reusable' => true,
        ]);
        $plant = $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-01',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-15 12:00:00', 'temp_min' => 8, 'temp_max' => 14, 'rain' => 0, 'wind_kmh' => 5],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-15', '2026-03-15');

        $this->assertTrue(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('fk_plant_id', $plant->id)
                ->exists()
        );
    }

    public function test_rain_threshold_suppresses_watering(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'rain_skip_threshold_mm' => 5,
        ]);
        $plant = $this->createPlant($plot, $zone, [
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 10, 'temp_max' => 16, 'rain' => 10, 'wind_kmh' => 6],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertFalse(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('fk_plant_id', $plant->id)
                ->where('type', 'watering')
                ->exists()
        );
    }

    public function test_frost_threshold_adds_protection_task(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'frost_temp_threshold_c' => 1,
        ]);
        $this->createPlant($plot, $zone, [
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => -1, 'temp_max' => 4, 'rain' => 0, 'wind_kmh' => 4],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertTrue(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('type', 'rest')
                ->exists()
        );
    }

    public function test_heat_threshold_adds_extra_watering_task(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'heat_extra_water_temp_c' => 25,
            'rain_skip_threshold_mm' => 100,
        ]);
        $this->createPlant($plot, $zone, [
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 14, 'temp_max' => 31, 'rain' => 0, 'wind_kmh' => 3],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertTrue(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('type', 'watering')
                ->exists()
        );
    }

    public function test_wind_threshold_adds_protection_task(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'wind_protection_kmh' => 20,
        ]);
        $this->createPlant($plot, $zone, [
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 8, 'temp_max' => 15, 'rain' => 0, 'wind_kmh' => 31],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertTrue(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('type', 'rest')
                ->exists()
        );
    }

    public function test_plant_specific_tasks_set_fk_plant_id(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
        ]);
        $plant = $this->createPlant($plot, $zone, [
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 11, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 5],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertTrue(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('type', '!=', 'material_acquisition')
                ->where('fk_plant_id', $plant->id)
                ->exists()
        );
    }

    public function test_inventory_shortage_generates_buy_action(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 1,
            'pest_check_interval_days' => 99,
            'germinating_duration_days' => 0,
        ]);
        $plant = $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 9, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 6],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertDatabaseHas('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'fertilize',
            'fk_plant_id' => $plant->id,
        ]);

        $this->assertDatabaseHas('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'buy',
            'plant_id' => null,
            'item' => 'Fertilizer',
        ]);
    }

    public function test_inventory_material_enough_keeps_calendar_without_buy_action(): void
    {
        [, $owner, $plot, $zone] = $this->createOwnedPlotContext();
        $this->createInventoryItemForOwner($owner, [
            'name' => 'Fertilizer',
            'quantity' => 3,
            'type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
        ]);

        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 1,
            'pest_check_interval_days' => 99,
            'germinating_duration_days' => 0,
        ]);

        $plant = $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 9, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 6],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertDatabaseHas('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'fertilize',
            'fk_plant_id' => $plant->id,
        ]);

        $this->assertDatabaseMissing('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'buy',
            'item' => 'Fertilizer',
        ]);
    }

    public function test_reusable_tool_in_inventory_does_not_generate_buy_action(): void
    {
        [, $owner, $plot, $zone] = $this->createOwnedPlotContext();
        $this->createInventoryItemForOwner($owner, [
            'name' => 'Plant support',
            'quantity' => 1,
            'type' => InventoryItemType::Tool,
            'unit' => InventoryUnit::Unit,
        ]);

        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'wind_protection_kmh' => 20,
        ]);

        $this->createPlant($plot, $zone, [
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 8, 'temp_max' => 15, 'rain' => 0, 'wind_kmh' => 31],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertDatabaseHas('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'rest',
            'item' => 'Plant support',
        ]);

        $this->assertDatabaseMissing('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'buy',
            'item' => 'Plant support',
        ]);
    }

    public function test_missing_reusable_tool_generates_buy_action(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'wind_protection_kmh' => 20,
        ]);

        $this->createPlant($plot, $zone, [
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 8, 'temp_max' => 15, 'rain' => 0, 'wind_kmh' => 31],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertDatabaseHas('tasks', [
            'fk_task_calendar_id' => $calendar->id,
            'type' => 'buy',
            'item' => 'Plant support',
        ]);
    }

    public function test_first_generation_creates_and_assigns_plant_care(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $plant = $this->createPlant($plot, $zone, [
            'fk_plant_care_id' => null,
        ]);

        $this->fakeApis(
            careOverrides: [
                'watering_interval_days' => 3,
                'plant_type' => PlantType::Vegetable->value,
            ],
            forecastDays: [
                ['date' => '2026-03-20 12:00:00', 'temp_min' => 8, 'temp_max' => 15, 'rain' => 0, 'wind_kmh' => 6],
            ],
        );

        $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $plant->refresh();
        $care = $plant->fresh('catalogPlant.plantCare')->effectivePlantCare();

        $this->assertNotNull($care);
        $this->assertDatabaseHas('plant_care', [
            'id' => $care->id,
            'plant_name' => $plant->name,
            'watering_interval_days' => 3,
        ]);
    }

    public function test_subsequent_generation_reuses_saved_plant_care_without_fresh_api_success(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $this->createPlant($plot, $zone, [
            'fk_plant_care_id' => null,
        ]);

        $this->fakeApis(
            careOverrides: ['watering_interval_days' => 2],
            forecastDays: [
                ['date' => '2026-03-20 12:00:00', 'temp_min' => 8, 'temp_max' => 15, 'rain' => 0, 'wind_kmh' => 6],
            ],
        );

        $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        Http::preventStrayRequests();
        $this->fakeWeather([
            ['date' => '2026-03-21 12:00:00', 'temp_min' => 9, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 6],
        ]);

        $this->generateCalendar($plot, '2026-03-21', '2026-03-21');

        $this->assertSame(1, PlantCare::query()->count());
        $this->assertSame(2, TaskCalendar::query()->count());
    }

    public function test_generation_reuses_latest_saved_weather_when_requested_city_has_no_cache(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $plot->update(['city' => 'Siauliai']);

        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'rain_skip_threshold_mm' => 5,
        ]);

        $plant = $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        Http::fake(fn () => Http::response([], 503));

        $sourceCalendar = TaskCalendar::query()->create([
            'creation_date' => now(),
            'start_date' => '2026-03-19',
            'end_date' => '2026-03-20',
            'plot_id' => $plot->id,
            'fk_plot_id' => $plot->id,
        ]);

        \App\Models\WeatherForecast::query()->create([
            'date' => '2026-03-20',
            'temperature' => 13,
            'temp_min' => 10,
            'temp_max' => 16,
            'precipitation' => 7,
            'humidity' => 72,
            'wind_kmh' => 8,
            'condition_code' => 'light-rain',
            'is_seasonal_fallback' => false,
            'city' => 'Vilnius',
            'task_calendar_id' => $sourceCalendar->id,
            'fk_task_calendar_id' => $sourceCalendar->id,
        ]);

        $calendar = $this->generateCalendar($plot->fresh(), '2026-03-20', '2026-03-20');

        $this->assertDatabaseHas('weather_forecasts', [
            'task_calendar_id' => $calendar->id,
            'city' => 'Siauliai',
            'temp_min' => 10,
            'temp_max' => 16,
            'precipitation' => 7,
            'is_seasonal_fallback' => false,
            'source' => 'stored_other_city_date',
            'source_city' => 'Vilnius',
        ]);

        $this->assertFalse(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('fk_plant_id', $plant->id)
                ->where('type', 'watering')
                ->exists()
        );
    }

    public function test_generation_uses_seasonal_weather_estimate_when_api_and_cache_are_unavailable(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $plot->update(['city' => 'Siauliai']);

        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'rain_skip_threshold_mm' => 50,
        ]);

        $plant = $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        Http::fake(fn () => Http::response([], 503));

        $calendar = $this->generateCalendar($plot->fresh(), '2026-03-20', '2026-03-20');

        $this->assertDatabaseHas('weather_forecasts', [
            'task_calendar_id' => $calendar->id,
            'city' => 'Siauliai',
            'is_seasonal_fallback' => true,
            'source' => 'seasonal',
        ]);

        $this->assertTrue(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('fk_plant_id', $plant->id)
                ->where('type', 'watering')
                ->exists()
        );
    }

    public function test_daily_aggregation_uses_only_that_days_meteo_timestamps(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
        ]);
        $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 00:00:00', 'temp_min' => 2, 'temp_max' => 10, 'rain' => 4, 'wind_kmh' => 18],
            ['date' => '2026-03-21 00:00:00', 'temp_min' => 7, 'temp_max' => 15, 'rain' => 0.5, 'wind_kmh' => 36],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-21');
        $forecasts = \App\Models\WeatherForecast::query()
            ->where('task_calendar_id', $calendar->id)
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($forecast) => $forecast->date->toDateString());

        $this->assertSame(2.0, (float) $forecasts['2026-03-20']->temp_min);
        $this->assertSame(10.0, (float) $forecasts['2026-03-20']->temp_max);
        $this->assertSame(4.0, (float) $forecasts['2026-03-20']->precipitation);
        $this->assertSame(18.0, (float) $forecasts['2026-03-20']->wind_kmh);
        $this->assertSame('api', $forecasts['2026-03-20']->source);
        $this->assertSame(7.0, (float) $forecasts['2026-03-21']->temp_min);
        $this->assertSame(15.0, (float) $forecasts['2026-03-21']->temp_max);
        $this->assertSame(0.5, (float) $forecasts['2026-03-21']->precipitation);
        $this->assertSame(36.0, (float) $forecasts['2026-03-21']->wind_kmh);
        $this->assertSame('api', $forecasts['2026-03-21']->source);
    }

    public function test_missing_days_are_not_filled_by_copying_one_stored_forecast_to_all_dates(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $plot->update(['city' => 'Kaunas']);

        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
        ]);
        $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        Http::fake(fn () => Http::response([], 503));

        $sourceCalendar = TaskCalendar::query()->create([
            'creation_date' => now(),
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'plot_id' => $plot->id,
            'fk_plot_id' => $plot->id,
        ]);

        \App\Models\WeatherForecast::query()->create([
            'date' => '2026-03-20',
            'temperature' => 13,
            'temp_min' => 10,
            'temp_max' => 16,
            'precipitation' => 7,
            'humidity' => 72,
            'wind_kmh' => 8,
            'condition_code' => 'light-rain',
            'is_seasonal_fallback' => false,
            'source' => 'api',
            'city' => 'Kaunas',
            'task_calendar_id' => $sourceCalendar->id,
            'fk_task_calendar_id' => $sourceCalendar->id,
        ]);

        $calendar = $this->generateCalendar($plot->fresh(), '2026-03-20', '2026-03-22');
        $forecasts = \App\Models\WeatherForecast::query()
            ->where('task_calendar_id', $calendar->id)
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($forecast) => $forecast->date->toDateString());

        $this->assertSame('stored_city_date', $forecasts['2026-03-20']->source);
        $this->assertSame('seasonal', $forecasts['2026-03-21']->source);
        $this->assertSame('seasonal', $forecasts['2026-03-22']->source);
        $this->assertNotSame((string) $forecasts['2026-03-20']->temp_min, (string) $forecasts['2026-03-21']->temp_min);
        $this->assertNotSame((string) $forecasts['2026-03-20']->temp_max, (string) $forecasts['2026-03-21']->temp_max);
    }

    public function test_weather_repair_command_refreshes_suspicious_identical_forecasts(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $plot->update(['city' => 'Kaunas']);

        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
        ]);
        $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $calendar = TaskCalendar::query()->create([
            'creation_date' => now(),
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-22',
            'plot_id' => $plot->id,
            'fk_plot_id' => $plot->id,
        ]);

        foreach (['2026-03-20', '2026-03-21', '2026-03-22'] as $date) {
            \App\Models\WeatherForecast::query()->create([
                'date' => $date,
                'temperature' => 5.25,
                'temp_min' => 2.7,
                'temp_max' => 7.8,
                'precipitation' => 1.7,
                'humidity' => 70,
                'wind_kmh' => 25.2,
                'condition_code' => 'cloudy',
                'is_seasonal_fallback' => false,
                'source' => 'legacy_unknown',
                'city' => 'Kaunas',
                'task_calendar_id' => $calendar->id,
                'fk_task_calendar_id' => $calendar->id,
            ]);
        }

        $this->fakeWeather([
            ['date' => '2026-03-20 00:00:00', 'temp_min' => 1, 'temp_max' => 5, 'rain' => 0, 'wind_kmh' => 10],
            ['date' => '2026-03-21 00:00:00', 'temp_min' => 3, 'temp_max' => 9, 'rain' => 2, 'wind_kmh' => 20],
            ['date' => '2026-03-22 00:00:00', 'temp_min' => 6, 'temp_max' => 12, 'rain' => 4, 'wind_kmh' => 30],
        ]);

        Artisan::call('weather:repair-forecasts', [
            '--calendar-id' => $calendar->id,
        ]);

        $forecasts = \App\Models\WeatherForecast::query()
            ->where('task_calendar_id', $calendar->id)
            ->orderBy('date')
            ->get();

        $this->assertSame(['api'], $forecasts->pluck('source')->unique()->values()->all());
        $this->assertSame(3, $forecasts->map(fn ($forecast) => implode('|', [
            $forecast->temp_min,
            $forecast->temp_max,
            $forecast->precipitation,
            $forecast->wind_kmh,
        ]))->unique()->count());
    }

    public function test_generation_creates_default_local_care_when_perenual_details_are_rate_limited(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $plant = $this->createPlant($plot, $zone, [
            'name' => 'Basil',
            'type' => PlantType::Herb,
            'fk_plant_care_id' => null,
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'perenual.com/api/species-list')) {
                return Http::response([
                    'data' => [
                        ['id' => 830, 'common_name' => 'Basil'],
                    ],
                ], 200);
            }

            if (str_contains($url, 'perenual.com/api/species/details/')) {
                return Http::response([], 429);
            }

            if (str_ends_with($url, '/places')) {
                return Http::response($this->buildMeteoPlaces(), 200);
            }

            if (str_contains($url, '/forecasts/long-term')) {
                return Http::response($this->buildMeteoForecast([
                    ['date' => '2026-03-20 12:00:00', 'temp_min' => 10, 'temp_max' => 18, 'rain' => 0, 'wind_kmh' => 6],
                ]), 200);
            }

            throw new \RuntimeException("Unexpected HTTP request [{$url}]");
        });

        $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $plant->refresh();
        $care = $plant->fresh('catalogPlant.plantCare')->effectivePlantCare();

        $this->assertSame('default', $care->source_quality);
        $this->assertSame('local', $care->source_provider);
        $this->assertSame('basil', $care->canonical_name);
    }

    public function test_completing_task_decrements_inventory_correctly(): void
    {
        [$user, $owner, $plot, $zone] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        $calendar = TaskCalendar::query()->create([
            'creation_date' => now(),
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'fk_plot_id' => $plot->id,
        ]);

        $plant = $this->createPlant($plot, $zone);
        $task = Task::query()->create([
            'date' => '2026-03-20',
            'name' => 'Tręšti pomidorą',
            'type' => 'fertilize',
            'item' => 'Trasos',
            'item_quantity' => 2,
            'status' => 'pending',
            'fk_task_calendar_id' => $calendar->id,
            'fk_plant_id' => $plant->id,
        ]);

        TaskResourceRequirement::query()->create([
            'task_id' => $task->id,
            'resource_name' => 'Trasos',
            'normalized_name' => 'trasos',
            'inventory_item_type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
            'required_quantity' => 2,
            'shortage_quantity' => 0,
            'is_consumed' => true,
        ]);

        $inventoryItem = $this->createInventoryItemForOwner($owner, [
            'name' => 'Trasos',
            'quantity' => 5,
            'type' => InventoryItemType::Material,
            'unit' => InventoryUnit::Kilogram,
        ]);

        $response = $this->patchJson("/api/tasks/{$task->id}/complete", [
            'materials_used' => [
                ['name' => 'Trasos', 'quantity' => 2],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Veiksmas sėkmingai įvykdytas')
            ->assertJsonPath('task.status', 'completed');

        $this->assertDatabaseHas('inventory_items', [
            'id' => $inventoryItem->id,
            'quantity' => 3,
        ]);
    }

    public function test_completing_already_completed_task_returns_422(): void
    {
        [$user, , $plot, $zone] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        $task = $this->createManualTask($plot, $zone, 'completed');

        $this->patchJson("/api/tasks/{$task->id}/complete")
            ->assertStatus(422);
    }

    public function test_rejecting_already_cancelled_task_returns_422(): void
    {
        [$user, , $plot, $zone] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        $task = $this->createManualTask($plot, $zone, 'cancelled');

        $this->patchJson("/api/tasks/{$task->id}/reject", [
            'reason' => 'Nebereikia',
        ])->assertStatus(422);
    }

    public function test_task_calendars_table_still_has_no_fk_plant_care_id(): void
    {
        $this->assertFalse(Schema::hasColumn('task_calendars', 'fk_plant_care_id'));
    }

    public function test_generation_uses_thresholds_from_saved_plant_care_data(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'rain_skip_threshold_mm' => 50,
        ]);
        $plant = $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 10, 'temp_max' => 16, 'rain' => 8, 'wind_kmh' => 5],
        ]);

        $firstCalendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertTrue(
            Task::query()
                ->where('fk_task_calendar_id', $firstCalendar->id)
                ->where('fk_plant_id', $plant->id)
                ->where('type', 'watering')
                ->exists()
        );

        $care->update(['rain_skip_threshold_mm' => 5]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 10, 'temp_max' => 16, 'rain' => 8, 'wind_kmh' => 5],
        ]);

        $secondCalendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertFalse(
            Task::query()
                ->where('fk_task_calendar_id', $secondCalendar->id)
                ->where('fk_plant_id', $plant->id)
                ->where('type', 'watering')
                ->exists()
        );
    }

    public function test_future_state_progression_changes_actions_across_generated_days(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'germinating_duration_days' => 0,
            'growing_duration_days' => 1,
            'flowering_duration_days' => 0,
            'mature_duration_days' => 1,
            'mature_duration_end_days' => 1,
        ]);
        $plant = $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 10, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 4],
            ['date' => '2026-03-21 12:00:00', 'temp_min' => 11, 'temp_max' => 17, 'rain' => 0, 'wind_kmh' => 4],
            ['date' => '2026-03-22 12:00:00', 'temp_min' => 12, 'temp_max' => 18, 'rain' => 0, 'wind_kmh' => 4],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-22');

        $this->assertFalse(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('fk_plant_id', $plant->id)
                ->whereDate('date', '2026-03-20')
                ->where('type', 'harvest')
                ->exists()
        );

        $this->assertTrue(
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('fk_plant_id', $plant->id)
                ->whereDate('date', '2026-03-22')
                ->where('type', 'harvest')
                ->exists()
        );
    }

    public function test_duplicate_actions_are_not_generated_for_same_plant_day_and_type(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
            'heat_extra_water_temp_c' => 25,
            'rain_skip_threshold_mm' => 100,
        ]);
        $plant = $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-18',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-18 12:00:00', 'temp_min' => 16, 'temp_max' => 28, 'rain' => 0, 'wind_kmh' => 4],
            ['date' => '2026-03-19 12:00:00', 'temp_min' => 17, 'temp_max' => 29, 'rain' => 0, 'wind_kmh' => 4],
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 18, 'temp_max' => 31, 'rain' => 0, 'wind_kmh' => 4],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-20');

        $this->assertSame(
            1,
            Task::query()
                ->where('fk_task_calendar_id', $calendar->id)
                ->where('fk_plant_id', $plant->id)
                ->whereDate('date', '2026-03-20')
                ->where('type', 'watering')
                ->count()
        );
    }

    public function test_daily_retrieval_returns_only_exact_date_actions(): void
    {
        [$user, , $plot, $zone] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
        ]);
        $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $this->fakeWeather([
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 10, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 4],
            ['date' => '2026-03-21 12:00:00', 'temp_min' => 10, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 4],
        ]);

        $calendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-21');

        $response = $this->getJson("/api/calendars/{$calendar->id}/tasks?date=2026-03-21");

        $response->assertOk();

        $payload = $response->json();
        $tasks = $payload['data'] ?? $payload;

        $this->assertNotEmpty($tasks);
        $this->assertTrue(collect($tasks)->every(fn (array $task) => data_get($task, 'date') === '2026-03-21'));
    }

    public function test_same_input_produces_same_generated_task_contract(): void
    {
        [, , $plot, $zone] = $this->createOwnedPlotContext();
        $care = $this->createPlantCare([
            'watering_interval_days' => 1,
            'fertilizing_interval_days' => 2,
            'pest_check_interval_days' => 3,
        ]);
        $this->createPlant($plot, $zone, [
            'plant_date' => '2026-03-19',
            'fk_plant_care_id' => $care->id,
        ]);

        $forecastDays = [
            ['date' => '2026-03-20 12:00:00', 'temp_min' => 10, 'temp_max' => 16, 'rain' => 0, 'wind_kmh' => 4],
            ['date' => '2026-03-21 12:00:00', 'temp_min' => 9, 'temp_max' => 15, 'rain' => 0, 'wind_kmh' => 4],
            ['date' => '2026-03-22 12:00:00', 'temp_min' => 8, 'temp_max' => 14, 'rain' => 0, 'wind_kmh' => 4],
        ];

        $this->fakeWeather($forecastDays);
        $firstCalendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-22');
        $firstSignature = $this->taskSignatureForCalendar($firstCalendar);

        $this->fakeWeather($forecastDays);
        $secondCalendar = $this->generateCalendar($plot, '2026-03-20', '2026-03-22');
        $secondSignature = $this->taskSignatureForCalendar($secondCalendar);

        $this->assertSame($firstSignature, $secondSignature);
    }

    private function createOwnedPlotContext(): array
    {
        $user = User::factory()->create();
        $profile = Profile::query()->create([
            'name' => 'Ieva',
            'surname' => 'Kalendore',
        ]);

        $owner = GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        $plot = Plot::query()->create([
            'garden_owner_id' => $owner->id,
            'name' => 'Kalendoriaus sklypas',
            'city' => 'Vilnius',
            'plot_size' => 100,
            'creation_date' => '2026-03-20',
            'description' => 'Bandymu sklypas',
            'share' => false,
        ]);

        HasPlot::query()->create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        $zone = PlantZone::query()->create([
            'name' => 'Zona A',
            'zone_size' => 25,
            'soil_type' => SoilType::Clay,
            'rotation_stage' => 0,
            'last_planting_date' => '2026-03-19',
            'fk_plot_id' => $plot->id,
        ]);

        return [$user, $owner, $plot, $zone];
    }

    private function createInventoryItemForOwner(GardenOwner $owner, array $overrides = []): InventoryItem
    {
        $name = (string) ($overrides['name'] ?? 'Trasos');
        $type = $overrides['inventory_item_type'] ?? $overrides['type'] ?? InventoryItemType::Material;
        $normalizedType = $type instanceof InventoryItemType ? $type : InventoryItemType::from((string) $type);
        $unit = $overrides['unit'] ?? ($normalizedType === InventoryItemType::Tool ? InventoryUnit::Unit : InventoryUnit::Kilogram);

        $item = InventoryItem::query()->create(array_merge([
            'garden_owner_id' => $owner->id,
            'name' => $name,
            'normalized_name' => mb_strtolower(trim($name)),
            'quantity' => 10,
            'type' => $normalizedType,
            'inventory_item_type' => $normalizedType,
            'unit' => $unit,
        ], $overrides));

        HasInventory::query()->create([
            'fk_inventory_item_id' => $item->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return $item;
    }

    private function createPlantCare(array $overrides = []): PlantCare
    {
        return PlantCare::query()->create(array_merge([
            'description' => 'Testinis prieziuros profilis',
            'conditions' => 'Saule ir vidutine dregme',
            'germinating_duration_days' => 1,
            'growing_duration_days' => 3,
            'flowering_duration_days' => 1,
            'mature_duration_days' => 2,
            'mature_duration_end_days' => 1,
            'mature_end_duration_days' => 1,
            'regenerating_duration_days' => 0,
            'reusable' => false,
            'plant_name' => 'Pomidoras',
            'task_type' => 'watering',
            'plant_type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Planted->value,
            'watering_interval_days' => 2,
            'fertilizing_interval_days' => 14,
            'pest_check_interval_days' => 7,
            'rain_skip_threshold_mm' => 8,
            'frost_temp_threshold_c' => 2,
            'heat_extra_water_temp_c' => 30,
            'wind_protection_kmh' => 45,
        ], $overrides));
    }

    private function createPlant(Plot $plot, PlantZone $zone, array $overrides = []): Plant
    {
        return Plant::query()->create(array_merge([
            'name' => 'Pomidoras',
            'plant_date' => '2026-03-20',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Planted,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
        ], $overrides));
    }

    private function createManualTask(Plot $plot, PlantZone $zone, string $status): Task
    {
        $calendar = TaskCalendar::query()->create([
            'creation_date' => now(),
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'fk_plot_id' => $plot->id,
        ]);

        $plant = $this->createPlant($plot, $zone);

        return Task::query()->create([
            'date' => '2026-03-20',
            'name' => 'Testinis veiksmas',
            'type' => 'watering',
            'status' => $status,
            'fk_task_calendar_id' => $calendar->id,
            'fk_plant_id' => $plant->id,
        ]);
    }

    private function generateCalendar(Plot $plot, string $startDate, string $endDate): TaskCalendar
    {
        return app(TaskCalendarService::class)->generate(
            $plot->fresh(),
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->startOfDay(),
        );
    }

    private function fakeApis(array $careOverrides = [], array $forecastDays = []): void
    {
        $details = array_merge($this->defaultCareDetails(), $careOverrides);

        Http::fake(function ($request) use ($details, $forecastDays) {
            $url = $request->url();

            if (str_contains($url, 'perenual.com/api/species-list')) {
                return Http::response([
                    'data' => [
                        ['id' => 123, 'common_name' => 'Pomidoras'],
                    ],
                ], 200);
            }

            if (str_contains($url, 'perenual.com/api/species/details/')) {
                return Http::response($details, 200);
            }

            if (str_ends_with($url, '/places')) {
                return Http::response($this->buildMeteoPlaces(), 200);
            }

            if (str_contains($url, '/forecasts/long-term')) {
                return Http::response($this->buildMeteoForecast($forecastDays), 200);
            }

            throw new \RuntimeException("Unexpected HTTP request [{$url}]");
        });
    }

    private function fakeWeather(array $forecastDays): void
    {
        Http::fake(function ($request) use ($forecastDays) {
            $url = $request->url();

            if (str_ends_with($url, '/places')) {
                return Http::response($this->buildMeteoPlaces(), 200);
            }

            if (str_contains($url, '/forecasts/long-term')) {
                return Http::response($this->buildMeteoForecast($forecastDays), 200);
            }

            throw new \RuntimeException("Unexpected HTTP request [{$url}]");
        });
    }

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
                    'latitude' => 54.8982,
                    'longitude' => 23.9045,
                ],
            ],
            [
                'code' => 'kaunas-centras',
                'name' => 'Kaunas (Centras)',
                'administrativeDivision' => 'Kauno miesto savivaldybe',
                'countryCode' => 'LT',
                'coordinates' => [
                    'latitude' => 54.8967,
                    'longitude' => 23.8856,
                ],
            ],
        ];
    }

    private function buildMeteoForecast(array $forecastDays): array
    {
        return [
            'place' => [
                'code' => 'vilnius',
                'name' => 'Vilnius',
                'administrativeDivision' => 'Vilniaus miesto savivaldybe',
                'country' => 'Lietuva',
                'countryCode' => 'LT',
                'coordinates' => [
                    'latitude' => 54.6872,
                    'longitude' => 25.2797,
                ],
            ],
            'forecastType' => 'long-term',
            'forecastCreationTimeUtc' => '2026-03-20 09:00:00',
            'forecastTimestamps' => collect($forecastDays)->flatMap(function (array $day) {
                $timestamp = Carbon::parse($day['date']);
                $tempMin = $day['temp_min'];
                $tempMax = $day['temp_max'];
                $baseTemperature = $day['temp'] ?? round(($tempMin + $tempMax) / 2, 2);
                $humidity = $day['humidity'] ?? 70;
                $windSpeed = round(($day['wind_kmh'] ?? 0) / 3.6, 3);
                $condition = $day['condition'] ?? 'clear';

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
                        'airTemperature' => $baseTemperature,
                        'relativeHumidity' => $humidity,
                        'totalPrecipitation' => $day['rain'] ?? 0,
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
            })->values()->all(),
        ];
    }

    private function defaultCareDetails(): array
    {
        return [
            'description' => 'Pomidoru prieziura',
            'sunlight' => ['full sun'],
            'watering' => 'average',
            'cycle' => 'annual',
            'care_level' => 'medium',
            'plant_type' => PlantType::Vegetable->value,
            'germinating_duration_days' => 2,
            'growing_duration_days' => 5,
            'flowering_duration_days' => 3,
            'mature_duration_days' => 4,
            'mature_duration_end_days' => 2,
            'regenerating_duration_days' => 0,
            'watering_interval_days' => 2,
            'fertilizing_interval_days' => 10,
            'pest_check_interval_days' => 6,
            'rain_skip_threshold_mm' => 8,
            'frost_temp_threshold_c' => 2,
            'heat_extra_water_temp_c' => 30,
            'wind_protection_kmh' => 40,
        ];
    }

    private function taskSignatureForCalendar(TaskCalendar $calendar): array
    {
        return Task::query()
            ->where('fk_task_calendar_id', $calendar->id)
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function (Task $task): array {
                return [
                    'date' => $task->date?->toDateString(),
                    'name' => $task->name,
                    'type' => $task->type,
                    'priority' => $task->priority?->value ?? $task->priority,
                    'reason' => $task->reason,
                    'item' => $task->item,
                    'item_quantity' => $task->item_quantity === null ? null : (float) $task->item_quantity,
                    'plant_id' => $task->fk_plant_id,
                    'plant_zone_id' => $task->plant_zone_id,
                ];
            })
            ->all();
    }
}
