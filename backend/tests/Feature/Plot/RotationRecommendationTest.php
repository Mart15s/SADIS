<?php

namespace Tests\Feature\Plot;

use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Models\Plant;
use App\Models\RotationPlanDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class RotationRecommendationTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_target_zone_with_recent_rotation_conflict_is_rejected_without_valid_verdict(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Darzoves', 'zone_size' => 30]);
        $blockedZone = $this->createZoneForPlot($plot, [
            'name' => 'Vaisiai',
            'zone_size' => 30,
            'soil_type' => SoilType::Sandy,
            'last_planting_date' => '2026-04-15',
        ]);
        $plant = $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Cucumber',
            'plant_size' => 5,
            'rest_time_days' => 20,
            'type' => PlantType::Vegetable,
        ]);

        $this->createRotationHistoryForPlot($plot, $blockedZone, $plant, [
            'from_date' => '2026-04-10',
            'to_date' => '2026-04-15',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/plots/{$plot->id}/rotations/recommendations?fk_plant_id={$plant->id}&fk_plant_zone_id={$blockedZone->id}&from_date=2026-04-20");

        $response->assertOk()
            ->assertJsonPath('data.target_zone.zone_name', 'Vaisiai')
            ->assertJsonPath('data.target_zone.verdict', 'invalid')
            ->assertJsonPath('data.target_zone.blocking_reasons.0', 'Tikslinėje zonoje tas pats augalas buvo sodintas per neseniai pagal rotacijos istoriją.');

        $this->assertNotEquals('valid', data_get($response->json(), 'data.target_zone.verdict'));
        $this->assertNotEmpty(data_get($response->json(), 'data.target_zone.blocking_reasons', []));
    }

    public function test_current_zone_is_rejected_when_rotation_would_keep_plant_in_place(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Darzoves', 'zone_size' => 30]);
        $otherZone = $this->createZoneForPlot($plot, ['name' => 'Prieskoniai', 'zone_size' => 30]);
        $plant = $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Cucumber',
            'plant_size' => 4,
            'rest_time_days' => 15,
            'type' => PlantType::Vegetable,
        ]);

        $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Tomato',
            'plant_size' => 4,
            'type' => PlantType::Vegetable,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/plots/{$plot->id}/rotations/recommendations?fk_plant_id={$plant->id}&fk_plant_zone_id={$currentZone->id}&from_date=2026-04-20");

        $response->assertOk()
            ->assertJsonPath('data.target_zone.zone_name', 'Darzoves')
            ->assertJsonPath('data.target_zone.verdict', 'invalid');

        $blockingReasons = data_get($response->json(), 'data.target_zone.blocking_reasons', []);

        $this->assertContains('Tikslinė zona sutampa su dabartine augalo zona, todėl tai nėra tinkama rotacija.', $blockingReasons);
        $this->assertContains('Tikslinėje zonoje jau yra to paties tipo augalų konfliktas.', $blockingReasons);
    }

    public function test_plot_wide_plan_returns_valid_target_zones_with_zone_specific_reasons(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Darzoves', 'zone_size' => 18]);
        $validZone = $this->createZoneForPlot($plot, [
            'name' => 'Ankstiniai',
            'zone_size' => 25,
            'soil_type' => SoilType::Clay,
            'last_planting_date' => '2026-03-01',
        ]);
        $blockedZone = $this->createZoneForPlot($plot, [
            'name' => 'Uzkrauta zona',
            'zone_size' => 5,
            'soil_type' => SoilType::Clay,
        ]);
        $plant = $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Cucumber',
            'plant_size' => 6,
            'rest_time_days' => 10,
            'type' => PlantType::Vegetable,
        ]);

        $this->createPlantForPlot($plot, $blockedZone, [
            'name' => 'Pumpkin',
            'plant_size' => 5,
            'type' => PlantType::Fruit,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/plots/{$plot->id}/rotations/plans", [
            'planning_date' => '2026-04-20',
        ]);

        $response->assertCreated()
            ->assertJsonPath('draft.plan.status', 'ready')
            ->assertJsonPath('draft.plan.plants.0.plant.name', 'Cucumber')
            ->assertJsonPath('draft.plan.plants.0.selected_target_zone.zone_name', 'Ankstiniai');

        $selectedReasons = data_get($response->json(), 'draft.plan.plants.0.selected_target_zone.passed_reasons', []);
        $candidateZones = collect(data_get($response->json(), 'draft.plan.plants.0.candidate_zones', []))->keyBy('zone_name');

        $this->assertContains('Tikslinėje zonoje pakanka vietos šiam augalui.', $selectedReasons);
        $this->assertSame('invalid', $candidateZones['Uzkrauta zona']['verdict']);
        $this->assertContains('Tikslinėje zonoje nepakanka vietos šiam augalui.', $candidateZones['Uzkrauta zona']['blocking_reasons']);
    }

    public function test_plan_returns_fallback_solutions_when_no_valid_target_zone_exists(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, [
            'name' => 'Darzoves',
            'zone_size' => 8,
            'last_planting_date' => '2026-04-18',
        ]);
        $blockedZone = $this->createZoneForPlot($plot, [
            'name' => 'Vaisiai',
            'zone_size' => 4,
            'last_planting_date' => '2026-04-19',
        ]);
        $plant = $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Cucumber',
            'plant_size' => 5,
            'rest_time_days' => 30,
            'type' => PlantType::Vegetable,
        ]);

        $this->createPlantForPlot($plot, $blockedZone, [
            'name' => 'Pepper',
            'plant_size' => 4,
            'type' => PlantType::Vegetable,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/plots/{$plot->id}/rotations/plans", [
            'planning_date' => '2026-04-20',
        ]);

        $response->assertCreated()
            ->assertJsonPath('draft.plan.status', 'needs_adjustment')
            ->assertJsonPath('draft.plan.plants.0.selected_target_zone', null);

        $fallbackSolutions = data_get($response->json(), 'draft.plan.plants.0.fallback_solutions', []);

        $this->assertNotEmpty($fallbackSolutions);
    }

    public function test_confirming_rotation_scheme_updates_assignments_and_history(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Darzoves', 'zone_size' => 20]);
        $targetZone = $this->createZoneForPlot($plot, ['name' => 'Ankstiniai', 'zone_size' => 20]);
        $plant = $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Cucumber',
            'plant_size' => 4,
            'rest_time_days' => 10,
            'type' => PlantType::Vegetable,
        ]);

        Sanctum::actingAs($user);

        $draftResponse = $this->postJson("/api/plots/{$plot->id}/rotations/plans", [
            'planning_date' => '2026-04-20',
        ])->assertCreated();

        $draftId = data_get($draftResponse->json(), 'draft.id');

        $this->postJson("/api/plots/{$plot->id}/rotations/plans/{$draftId}/confirm")
            ->assertOk()
            ->assertJsonPath('changed_plant_ids.0', $plant->id);

        $plant->refresh();
        $targetZone->refresh();

        $this->assertSame($targetZone->id, $plant->plant_zone_id);
        $this->assertDatabaseHas('rotation_history', [
            'fk_plot_id' => $plot->id,
            'fk_plant_zone_id' => $targetZone->id,
            'fk_plant_id' => $plant->id,
            'from_date' => '2026-04-20 00:00:00',
        ]);
        $this->assertDatabaseMissing('rotation_plan_drafts', [
            'id' => $draftId,
        ]);
        $this->assertDatabaseHas('plot_snapshots', [
            'plot_id' => $plot->id,
            'action' => 'rotation_plan_confirmed',
        ]);
    }

    public function test_rejecting_rotation_scheme_keeps_previous_state_and_removes_draft(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Darzoves', 'zone_size' => 20]);
        $targetZone = $this->createZoneForPlot($plot, ['name' => 'Ankstiniai', 'zone_size' => 20]);
        $plant = $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Cucumber',
            'plant_size' => 4,
            'rest_time_days' => 10,
            'type' => PlantType::Vegetable,
        ]);

        Sanctum::actingAs($user);

        $draftResponse = $this->postJson("/api/plots/{$plot->id}/rotations/plans", [
            'planning_date' => '2026-04-20',
        ])->assertCreated();

        $draftId = data_get($draftResponse->json(), 'draft.id');

        $this->deleteJson("/api/plots/{$plot->id}/rotations/plans/{$draftId}")
            ->assertNoContent();

        $plant->refresh();

        $this->assertSame($currentZone->id, $plant->plant_zone_id);
        $this->assertDatabaseMissing('rotation_plan_drafts', [
            'id' => $draftId,
        ]);
        $this->assertDatabaseCount('rotation_history', 0);
    }

    public function test_score_and_reasons_match_target_zone_validation_result(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Darzoves', 'zone_size' => 18]);
        $validZone = $this->createZoneForPlot($plot, ['name' => 'Ankstiniai', 'zone_size' => 20]);
        $invalidZone = $this->createZoneForPlot($plot, ['name' => 'Per maza', 'zone_size' => 2]);
        $plant = $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Cucumber',
            'plant_size' => 4,
            'rest_time_days' => 10,
            'type' => PlantType::Vegetable,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/plots/{$plot->id}/rotations/plans", [
            'planning_date' => '2026-04-20',
        ])->assertCreated();

        $candidates = collect(data_get($response->json(), 'draft.plan.plants.0.candidate_zones', []))->keyBy('zone_name');

        $this->assertSame('valid', $candidates['Ankstiniai']['verdict']);
        $this->assertGreaterThan(0, $candidates['Ankstiniai']['score']);
        $this->assertEmpty($candidates['Ankstiniai']['blocking_reasons']);

        $this->assertSame('invalid', $candidates['Per maza']['verdict']);
        $this->assertLessThanOrEqual(0, $candidates['Per maza']['score']);
        $this->assertContains('Tikslinėje zonoje nepakanka vietos šiam augalui.', $candidates['Per maza']['blocking_reasons']);
    }
}
