<?php

namespace Tests\Feature;

use App\Enums\AccessRole;
use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Services\Plot\PlotSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_owner_can_generate_planning_analysis_for_own_plot(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot, ['name' => 'Zone A']);
        $plant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Tomato',
            'type' => PlantType::Vegetable,
        ]);

        app(PlotSnapshotService::class)->capture($plot->fresh(['plantZones', 'plants']), 'plot_created', $owner);

        $this->createRotationHistoryForPlot($plot, $zone, $plant, [
            'from_date' => '2026-03-01',
            'to_date' => '2026-03-20',
        ]);
        $this->createRotationHistoryForPlot($plot, $zone, $plant, [
            'from_date' => '2026-03-25',
            'to_date' => '2026-04-10',
        ]);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['planning'],
        ])
            ->assertOk()
            ->assertJsonPath('selectedAnalysisTypes', ['planning'])
            ->assertJsonPath('sections.planning.status', 'ready')
            ->assertJsonPath('sections.planning.total_versions', 1)
            ->assertJsonPath('sections.planning.rotation_violation_count', 1);
    }

    public function test_owner_can_generate_plant_condition_analysis(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Cucumber',
            'condition' => ConditionType::Growing,
        ]);
        $calendar = $this->createCalendarForPlot($plot);

        $this->createConditionHistoryForPlant($plant, [
            'measured_at' => '2026-04-01 10:00:00',
            'condition' => ConditionType::Diseased,
        ]);
        $this->createTaskForCalendar($calendar, [
            'name' => 'Spray plant',
            'task_type' => 'spray',
            'status' => 'completed',
            'date' => '2026-04-02',
            'fk_plant_id' => $plant->id,
        ]);
        $this->createConditionHistoryForPlant($plant, [
            'measured_at' => '2026-04-03 10:00:00',
            'condition' => ConditionType::Growing,
        ]);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['plant_condition'],
        ])
            ->assertOk()
            ->assertJsonPath('selectedAnalysisTypes', ['plant_condition'])
            ->assertJsonPath('sections.plant_condition.status', 'ready')
            ->assertJsonPath('sections.plant_condition.plants_with_history_count', 1)
            ->assertJsonPath('sections.plant_condition.condition_changes.0.direction', 'improved')
            ->assertJsonPath('sections.plant_condition.care_response_trends.improvement_after_care_count', 1);
    }

    public function test_owner_can_generate_harvest_analysis(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Pepper',
        ]);
        $calendar = $this->createCalendarForPlot($plot);
        $task = $this->createTaskForCalendar($calendar, [
            'name' => 'Harvest pepper',
            'task_type' => 'harvest',
            'status' => 'pending',
            'fk_plant_id' => $plant->id,
        ]);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/harvests", [
            'plant_id' => $plant->id,
            'task_id' => $task->id,
            'quantity' => 9.5,
            'harvested_on' => '2026-04-10',
        ])->assertCreated();

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['harvest'],
        ])
            ->assertOk()
            ->assertJsonPath('selectedAnalysisTypes', ['harvest'])
            ->assertJsonPath('sections.harvest.status', 'ready')
            ->assertJsonPath('sections.harvest.total_records', 1)
            ->assertJsonPath('sections.harvest.total_quantity', 9.5)
            ->assertJsonPath('sections.harvest.best_yielding_plants.0.plant_name', 'Pepper');
    }

    public function test_owner_can_generate_two_selected_analysis_types_in_one_response(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone, ['name' => 'Tomato']);
        app(PlotSnapshotService::class)->capture($plot->fresh(['plantZones', 'plants']), 'plot_created', $owner);
        $this->createConditionHistoryForPlant($plant, [
            'measured_at' => '2026-04-01 10:00:00',
            'condition' => ConditionType::Growing,
        ]);

        Sanctum::actingAs($ownerUser);

        $response = $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['planning', 'plant_condition'],
        ]);

        $response->assertOk()
            ->assertJsonPath('selectedAnalysisTypes', ['planning', 'plant_condition'])
            ->assertJsonPath('sections.planning.status', 'ready')
            ->assertJsonPath('sections.plant_condition.status', 'ready');

        $payload = $response->json();

        $this->assertArrayHasKey('planning', $payload['sections']);
        $this->assertArrayHasKey('plant_condition', $payload['sections']);
        $this->assertArrayNotHasKey('harvest', $payload['sections']);
    }

    public function test_owner_can_generate_all_three_analysis_types(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone, ['name' => 'Bean']);
        $calendar = $this->createCalendarForPlot($plot);

        app(PlotSnapshotService::class)->capture($plot->fresh(['plantZones', 'plants']), 'plot_created', $owner);
        $this->createConditionHistoryForPlant($plant, [
            'measured_at' => '2026-04-01 10:00:00',
            'condition' => ConditionType::Growing,
        ]);
        $task = $this->createTaskForCalendar($calendar, [
            'name' => 'Harvest bean',
            'task_type' => 'harvest',
            'status' => 'completed',
            'fk_plant_id' => $plant->id,
        ]);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/harvests", [
            'plant_id' => $plant->id,
            'task_id' => $task->id,
            'quantity' => 4.25,
            'harvested_on' => '2026-04-12',
        ])->assertCreated();

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['planning', 'plant_condition', 'harvest'],
        ])
            ->assertOk()
            ->assertJsonPath('summary.selected_sections_count', 3)
            ->assertJsonPath('summary.sections_with_data_count', 3)
            ->assertJsonPath('sections.planning.status', 'ready')
            ->assertJsonPath('sections.plant_condition.status', 'ready')
            ->assertJsonPath('sections.harvest.status', 'ready');
    }

    public function test_validation_fails_when_no_analysis_type_is_selected(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['analysisTypes']);
    }

    public function test_validation_fails_for_unsupported_analysis_type(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['unknown'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['analysisTypes.0']);
    }

    public function test_validation_fails_for_duplicate_analysis_types(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['planning', 'planning'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['analysisTypes']);
    }

    public function test_partial_analysis_returns_ready_and_no_data_sections_together(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $this->createPlantForPlot($plot, $zone);
        app(PlotSnapshotService::class)->capture($plot->fresh(['plantZones', 'plants']), 'plot_created', $owner);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['planning', 'harvest'],
        ])
            ->assertOk()
            ->assertJsonPath('sections.planning.status', 'ready')
            ->assertJsonPath('sections.harvest.status', 'no_data')
            ->assertJsonPath('summary.sections_with_data_count', 1)
            ->assertJsonPath('summary.sections_without_data_count', 1);
    }

    public function test_backward_compatible_single_analysis_type_request_still_works(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        $calendar = $this->createCalendarForPlot($plot);
        $task = $this->createTaskForCalendar($calendar, [
            'name' => 'Harvest crop',
            'task_type' => 'harvest',
            'status' => 'completed',
            'fk_plant_id' => $plant->id,
        ]);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/harvests", [
            'plant_id' => $plant->id,
            'task_id' => $task->id,
            'quantity' => 2.5,
            'harvested_on' => '2026-04-15',
        ])->assertCreated();

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisType' => 'harvest',
        ])
            ->assertOk()
            ->assertJsonPath('selectedAnalysisTypes', ['harvest'])
            ->assertJsonPath('sections.harvest.status', 'ready');
    }

    public function test_analytics_response_structure_is_stable_for_frontend_consumption(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        app(PlotSnapshotService::class)->capture($plot->fresh(['plantZones', 'plants']), 'plot_created', $owner);
        $this->createConditionHistoryForPlant($plant, [
            'measured_at' => '2026-04-01 10:00:00',
            'condition' => ConditionType::Growing,
        ]);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['planning', 'plant_condition', 'harvest'],
        ])
            ->assertOk()
            ->assertJsonStructure([
                'plot' => ['id', 'name', 'city', 'plot_size', 'creation_date', 'description', 'share'],
                'selectedAnalysisTypes',
                'sections' => [
                    'planning' => [
                        'status',
                        'total_versions',
                        'change_events_count',
                        'actions_breakdown',
                        'plan_change_frequency',
                        'zone_season_selections',
                        'rotation_history',
                        'rotation_violations',
                        'rotation_violation_count',
                    ],
                    'plant_condition' => [
                        'status',
                        'counts_by_condition',
                        'history_counts_by_condition',
                        'disease_ratio',
                        'latest_entries_count',
                        'latest_measured_at',
                        'plants_with_history_count',
                        'condition_timeline',
                        'condition_changes',
                        'critical_deterioration_points',
                        'critical_deterioration_count',
                        'care_response_trends',
                        'trend_by_plant',
                    ],
                    'harvest' => [
                        'status',
                        'total_harvest_tasks',
                        'completed_harvest_tasks',
                        'canceled_harvest_tasks',
                        'pending_harvest_tasks',
                        'latest_harvest_date',
                        'plants_with_harvest_tasks_count',
                        'total_records',
                        'total_quantity',
                        'plants_with_harvest_records_count',
                        'best_yielding_plants',
                        'quantity_by_plant',
                        'records_by_period',
                        'trend',
                        'actual_vs_planned_ratio',
                    ],
                ],
                'summary' => [
                    'total_zones',
                    'total_plants',
                    'active_plants_count',
                    'diseased_plants_count',
                    'shared_users_count',
                    'selected_sections_count',
                    'sections_with_data_count',
                    'sections_without_data_count',
                    'has_actionable_data',
                ],
                'generatedAt',
                'warnings',
            ]);
    }

    public function test_unauthorized_user_gets_403_for_generated_analytics(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$outsiderUser] = $this->createGardenOwner('outsider@example.com');
        $plot = $this->createPlotForOwner($owner);

        Sanctum::actingAs($outsiderUser);

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['planning'],
        ])->assertForbidden();
    }

    public function test_editor_can_generate_analytics_for_shared_plot(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$editorUser, $editor] = $this->createGardenOwner('editor@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        $this->createAccessRight($owner, $plot, $editor, AccessRole::Editor);
        $this->createConditionHistoryForPlant($plant, [
            'measured_at' => '2026-04-01 10:00:00',
            'condition' => ConditionType::Growing,
        ]);

        Sanctum::actingAs($editorUser);

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['plant_condition'],
        ])
            ->assertOk()
            ->assertJsonPath('sections.plant_condition.status', 'ready');
    }
}
