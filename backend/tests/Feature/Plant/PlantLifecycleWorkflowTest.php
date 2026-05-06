<?php

namespace Tests\Feature\Plant;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Models\PlantCare;
use App\Models\Task;
use App\Services\Calendar\TaskCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class PlantLifecycleWorkflowTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-24 09:00:00');
        config([
            'services.meteo_lt.base_url' => 'https://api.meteo.lt/v1',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_calendar_generation_creates_lifecycle_review_task_from_plant_care_durations(): void
    {
        [, , $plot] = $this->createPlotContext();
        $zone = $this->createZoneForPlot($plot);
        $care = $this->createPlantCare([
            'germinating_duration_days' => 4,
            'growing_duration_days' => 6,
            'flowering_duration_days' => 3,
            'mature_duration_days' => 5,
            'watering_interval_days' => 99,
            'fertilizing_interval_days' => 99,
            'pest_check_interval_days' => 99,
        ]);
        $plant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Lifecycle tomato',
            'plant_date' => '2026-03-20',
            'condition' => ConditionType::Germinating,
            'fk_plant_care_id' => $care->id,
        ]);

        $this->createConditionHistoryForPlant($plant, [
            'measured_at' => '2026-03-20 12:00:00',
            'condition' => ConditionType::Germinating,
        ]);

        $this->fakeWeather('2026-03-25');

        $calendar = app(TaskCalendarService::class)->generate(
            $plot->fresh(),
            Carbon::parse('2026-03-25')->startOfDay(),
            Carbon::parse('2026-03-25')->startOfDay(),
        );

        $reviewTask = Task::query()
            ->where('task_calendar_id', $calendar->id)
            ->where('plant_id', $plant->id)
            ->get()
            ->first(fn (Task $task) => data_get($task->workflow_context, 'kind') === 'lifecycle_review');

        $this->assertNotNull($reviewTask);
        $this->assertSame('rest', $reviewTask->task_type);
        $this->assertSame('germinating', data_get($reviewTask->workflow_context, 'review.from_phase'));
        $this->assertSame('growing', data_get($reviewTask->workflow_context, 'review.target_condition'));
    }

    public function test_completing_lifecycle_review_updates_plant_and_creates_history_entry(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $care = $this->createPlantCare();
        $plant = $this->createPlantForPlot($plot, $zone, [
            'condition' => ConditionType::Germinating,
            'fk_plant_care_id' => $care->id,
        ]);
        $calendar = $this->createCalendarForPlot($plot, [
            'start_date' => '2026-03-24',
            'end_date' => '2026-03-24',
        ]);

        $task = $this->createTaskForCalendar($calendar, [
            'name' => 'Review transition to growing for Pomidoras',
            'type' => 'rest',
            'task_type' => 'rest',
            'status' => 'pending',
            'state' => 'pending',
            'fk_plant_id' => $plant->id,
            'workflow_context' => [
                'kind' => 'lifecycle_review',
                'review' => [
                    'current_condition' => 'germinating',
                    'target_condition' => 'growing',
                    'expected_on' => '2026-03-24',
                    'is_overdue' => false,
                ],
            ],
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/tasks/{$task->id}/complete", [
            'condition_review' => [
                'action' => 'confirm',
                'measured_at' => '2026-03-24',
                'notes' => 'Transition confirmed in the garden.',
            ],
        ])->assertOk()
            ->assertJsonPath('task.status', 'completed')
            ->assertJsonPath('condition_history_entry.condition', 'growing');

        $this->assertDatabaseHas('plants', [
            'id' => $plant->id,
            'condition' => 'growing',
        ]);
        $this->assertDatabaseHas('plant_condition_history', [
            'plant_id' => $plant->id,
            'condition' => 'growing',
        ]);
    }

    public function test_completing_harvest_task_creates_harvest_record_and_updates_analytics(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $care = $this->createPlantCare([
            'regenerating_duration_days' => 0,
            'reusable' => false,
        ]);
        $plant = $this->createPlantForPlot($plot, $zone, [
            'condition' => ConditionType::Mature,
            'fk_plant_care_id' => $care->id,
        ]);
        $calendar = $this->createCalendarForPlot($plot, [
            'start_date' => '2026-04-18',
            'end_date' => '2026-04-18',
        ]);

        $task = $this->createTaskForCalendar($calendar, [
            'name' => 'Harvest Pomidoras',
            'type' => 'harvest',
            'task_type' => 'harvest',
            'status' => 'pending',
            'state' => 'pending',
            'date' => '2026-04-18',
            'fk_plant_id' => $plant->id,
            'workflow_context' => [
                'kind' => 'harvest',
                'harvest' => [
                    'expected_on' => '2026-04-18',
                    'is_overdue' => false,
                    'post_harvest_condition' => 'dried',
                ],
            ],
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/tasks/{$task->id}/complete", [
            'harvest' => [
                'quantity' => 6.5,
                'harvested_on' => '2026-04-18',
                'notes' => 'Main harvest batch.',
            ],
        ])->assertOk()
            ->assertJsonPath('task.status', 'completed')
            ->assertJsonPath('harvest_record.quantity', 6.5);

        $this->assertDatabaseHas('harvest_records', [
            'task_id' => $task->id,
            'plant_id' => $plant->id,
            'quantity' => 6.5,
        ]);
        $this->assertDatabaseHas('plants', [
            'id' => $plant->id,
            'condition' => 'dried',
        ]);

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['harvest'],
        ])->assertOk()
            ->assertJsonPath('sections.harvest.total_records', 1)
            ->assertJsonPath('sections.harvest.total_quantity', 6.5);
    }

    private function createPlotContext(): array
    {
        [$user, $owner] = $this->createGardenOwner('workflow-owner@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'name' => 'Lifecycle plot',
            'city' => 'Vilnius',
            'share' => false,
        ]);

        return [$user, $owner, $plot];
    }

    private function createPlantCare(array $overrides = []): PlantCare
    {
        return PlantCare::query()->create(array_merge([
            'description' => 'Lifecycle care',
            'conditions' => 'Sunny',
            'germinating_duration_days' => 2,
            'growing_duration_days' => 5,
            'flowering_duration_days' => 3,
            'mature_duration_days' => 4,
            'mature_duration_end_days' => 2,
            'mature_end_duration_days' => 2,
            'regenerating_duration_days' => 0,
            'reusable' => false,
            'plant_name' => 'Pomidoras',
            'canonical_name' => 'pomidoras',
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

    private function fakeWeather(string $date): void
    {
        Http::fake(function ($request) use ($date) {
            $url = $request->url();

            if (str_ends_with($url, '/places')) {
                return Http::response([
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
                ], 200);
            }

            if (str_contains($url, '/forecasts/long-term')) {
                return Http::response([
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
                    'forecastCreationTimeUtc' => '2026-03-24 09:00:00',
                    'forecastTimestamps' => [
                        [
                            'forecastTimeUtc' => "{$date} 06:00:00",
                            'airTemperature' => 8,
                            'relativeHumidity' => 70,
                            'totalPrecipitation' => 0,
                            'windSpeed' => 2,
                            'conditionCode' => 'clear',
                        ],
                        [
                            'forecastTimeUtc' => "{$date} 12:00:00",
                            'airTemperature' => 14,
                            'relativeHumidity' => 65,
                            'totalPrecipitation' => 0,
                            'windSpeed' => 2,
                            'conditionCode' => 'clear',
                        ],
                        [
                            'forecastTimeUtc' => "{$date} 18:00:00",
                            'airTemperature' => 16,
                            'relativeHumidity' => 60,
                            'totalPrecipitation' => 0,
                            'windSpeed' => 2,
                            'conditionCode' => 'clear',
                        ],
                    ],
                ], 200);
            }

            throw new \RuntimeException("Unexpected HTTP request [{$url}]");
        });
    }
}
