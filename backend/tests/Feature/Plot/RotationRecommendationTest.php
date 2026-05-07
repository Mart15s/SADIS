<?php

namespace Tests\Feature\Plot;

use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Models\Plant;
use App\Models\PlantCare;
use App\Models\RotationPlanDraft;
use App\Services\Plot\CropRotationClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class RotationRecommendationTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    private function createCareProfile(string $name, ?string $family, PlantType $type = PlantType::Vegetable): PlantCare
    {
        return PlantCare::query()->create([
            'description' => "{$name} care profile",
            'conditions' => 'well drained fertile soil',
            'plant_name' => $name,
            'canonical_name' => strtolower(str_replace(' ', '-', $name)),
            'task_type' => 'watering',
            'plant_type' => $type,
            'condition' => 'growing',
            'source_family' => $family,
        ]);
    }

    private function createProfiledPlant(string $name, ?string $family, $plot, $zone, array $overrides = []): Plant
    {
        $care = $this->createCareProfile($name, $family, $overrides['type'] ?? PlantType::Vegetable);

        return $this->createPlantForPlot($plot, $zone, array_merge([
            'name' => $name,
            'fk_plant_care_id' => $care->id,
            'type' => PlantType::Vegetable,
            'plant_size' => 2,
            'rest_time_days' => 0,
        ], $overrides));
    }

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
            ->assertJsonPath('data.target_zone.previous_year', 2026);

        $this->assertNotEquals('valid', data_get($response->json(), 'data.target_zone.verdict'));
        $this->assertNotEmpty(data_get($response->json(), 'data.target_zone.blocking_reasons', []));
    }

    public function test_same_family_in_same_zone_too_soon_is_not_recommended_with_previous_plant_details(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zoneA = $this->createZoneForPlot($plot, ['name' => 'Zone A', 'zone_size' => 20]);
        $zoneB = $this->createZoneForPlot($plot, ['name' => 'Zone B', 'zone_size' => 20]);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Seedlings', 'zone_size' => 20]);

        $tomato = $this->createProfiledPlant('Tomato 2025', 'Solanaceae', $plot, $zoneB);
        $pepper = $this->createProfiledPlant('Pepper 2026', 'solanaceae family', $plot, $currentZone);
        $this->createRotationHistoryForPlot($plot, $zoneA, $tomato, [
            'from_date' => '2025-04-15',
            'to_date' => '2025-09-01',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/plots/{$plot->id}/rotations/recommendations?fk_plant_id={$pepper->id}&fk_plant_zone_id={$zoneA->id}&from_date=2026-04-15");

        $response->assertOk()
            ->assertJsonPath('data.target_zone.zone_id', $zoneA->id)
            ->assertJsonPath('data.target_zone.zone_name', 'Zone A')
            ->assertJsonPath('data.target_zone.verdict', 'invalid')
            ->assertJsonPath('data.target_zone.suitability', 'not_recommended')
            ->assertJsonPath('data.target_zone.conflicting_previous_plant.id', $tomato->id)
            ->assertJsonPath('data.target_zone.previous_year', 2025);
    }

    public function test_different_family_in_same_zone_is_allowed_when_rotation_group_does_not_conflict(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zoneA = $this->createZoneForPlot($plot, ['name' => 'Zone A', 'zone_size' => 20]);
        $archiveZone = $this->createZoneForPlot($plot, ['name' => 'Archive', 'zone_size' => 20]);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Seedlings', 'zone_size' => 20]);

        $tomato = $this->createProfiledPlant('Tomato 2025', 'Solanaceae', $plot, $archiveZone);
        $lettuce = $this->createProfiledPlant('Lettuce 2026', 'Asteraceae', $plot, $currentZone);
        $this->createRotationHistoryForPlot($plot, $zoneA, $tomato, [
            'from_date' => '2025-04-15',
            'to_date' => '2025-09-01',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/plots/{$plot->id}/rotations/recommendations?fk_plant_id={$lettuce->id}&fk_plant_zone_id={$zoneA->id}&from_date=2026-04-15");

        $response->assertOk()
            ->assertJsonPath('data.target_zone.verdict', 'valid')
            ->assertJsonPath('data.target_zone.suitability', 'recommended')
            ->assertJsonPath('data.target_zone.conflicting_previous_plant', null);
    }

    public function test_same_family_is_allowed_in_another_zone_without_matching_history(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zoneA = $this->createZoneForPlot($plot, ['name' => 'Zone A', 'zone_size' => 20]);
        $zoneB = $this->createZoneForPlot($plot, ['name' => 'Zone B', 'zone_size' => 20]);
        $archiveZone = $this->createZoneForPlot($plot, ['name' => 'Archive', 'zone_size' => 20]);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Seedlings', 'zone_size' => 20]);

        $tomato = $this->createProfiledPlant('Tomato 2025', 'Solanaceae', $plot, $archiveZone);
        $pepper = $this->createProfiledPlant('Pepper 2026', 'Solanaceae', $plot, $currentZone);
        $this->createRotationHistoryForPlot($plot, $zoneA, $tomato, [
            'from_date' => '2025-04-15',
            'to_date' => '2025-09-01',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/plots/{$plot->id}/rotations/recommendations?fk_plant_id={$pepper->id}&fk_plant_zone_id={$zoneB->id}&from_date=2026-04-15");

        $response->assertOk()
            ->assertJsonPath('data.target_zone.verdict', 'valid')
            ->assertJsonPath('data.target_zone.zone_name', 'Zone B');
    }

    public function test_multiple_zones_with_different_history_are_ranked_by_rotation_suitability(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zoneA = $this->createZoneForPlot($plot, ['name' => 'Solanaceae bed', 'zone_size' => 20]);
        $zoneB = $this->createZoneForPlot($plot, ['name' => 'Brassica bed', 'zone_size' => 20]);
        $zoneC = $this->createZoneForPlot($plot, ['name' => 'Legume bed', 'zone_size' => 20]);
        $archiveZone = $this->createZoneForPlot($plot, ['name' => 'Archive', 'zone_size' => 20]);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Seedlings', 'zone_size' => 20]);

        $tomato2025 = $this->createProfiledPlant('Tomato 2025', 'Solanaceae', $plot, $archiveZone);
        $cabbage2025 = $this->createProfiledPlant('Cabbage 2025', 'Brassicaceae', $plot, $archiveZone);
        $bean2025 = $this->createProfiledPlant('Bean 2025', 'Fabaceae', $plot, $archiveZone);
        $tomato2026 = $this->createProfiledPlant('Tomato 2026', 'Solanaceae', $plot, $currentZone);

        $this->createRotationHistoryForPlot($plot, $zoneA, $tomato2025, ['from_date' => '2025-04-01', 'to_date' => '2025-09-01']);
        $this->createRotationHistoryForPlot($plot, $zoneB, $cabbage2025, ['from_date' => '2025-04-01', 'to_date' => '2025-09-01']);
        $this->createRotationHistoryForPlot($plot, $zoneC, $bean2025, ['from_date' => '2025-04-01', 'to_date' => '2025-09-01']);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/plots/{$plot->id}/rotations/recommendations?fk_plant_id={$tomato2026->id}&from_date=2026-04-15");

        $response->assertOk();
        $planEntry = collect(data_get($response->json(), 'data.plants', []))
            ->first(fn (array $entry) => (int) data_get($entry, 'plant.id') === (int) $tomato2026->id);
        $this->assertNotNull($planEntry);
        $candidates = collect($planEntry['candidate_zones'] ?? []);

        $this->assertSame('invalid', $candidates->firstWhere('zone_name', 'Solanaceae bed')['verdict']);
        $this->assertSame('valid', $candidates->firstWhere('zone_name', 'Brassica bed')['verdict']);
        $this->assertSame('valid', $candidates->firstWhere('zone_name', 'Legume bed')['verdict']);
        $this->assertGreaterThanOrEqual(
            $candidates->firstWhere('zone_name', 'Brassica bed')['score'],
            $candidates->firstWhere('zone_name', 'Legume bed')['score']
        );
    }

    public function test_multiyear_history_checks_rotation_window_not_only_latest_entry(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zoneA = $this->createZoneForPlot($plot, ['name' => 'Zone A', 'zone_size' => 20]);
        $archiveZone = $this->createZoneForPlot($plot, ['name' => 'Archive', 'zone_size' => 20]);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Seedlings', 'zone_size' => 20]);

        $tomato2024 = $this->createProfiledPlant('Tomato 2024', 'Solanaceae', $plot, $archiveZone);
        $lettuce2025 = $this->createProfiledPlant('Lettuce 2025', 'Asteraceae', $plot, $archiveZone);
        $pepper2026 = $this->createProfiledPlant('Pepper 2026', 'Solanaceae', $plot, $currentZone);
        $this->createRotationHistoryForPlot($plot, $zoneA, $tomato2024, ['from_date' => '2024-04-01', 'to_date' => '2024-09-01']);
        $this->createRotationHistoryForPlot($plot, $zoneA, $lettuce2025, ['from_date' => '2025-04-01', 'to_date' => '2025-09-01']);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/plots/{$plot->id}/rotations/recommendations?fk_plant_id={$pepper2026->id}&fk_plant_zone_id={$zoneA->id}&from_date=2026-04-15");

        $response->assertOk()
            ->assertJsonPath('data.target_zone.verdict', 'invalid')
            ->assertJsonPath('data.target_zone.conflicting_previous_plant.id', $tomato2024->id)
            ->assertJsonPath('data.target_zone.previous_year', 2024);
    }

    public function test_zone_without_history_is_valid_for_rotation_check(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $emptyZone = $this->createZoneForPlot($plot, ['name' => 'Empty rotation zone', 'zone_size' => 20]);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Seedlings', 'zone_size' => 20]);
        $carrot = $this->createProfiledPlant('Carrot 2026', 'Apiaceae', $plot, $currentZone);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/plots/{$plot->id}/rotations/recommendations?fk_plant_id={$carrot->id}&fk_plant_zone_id={$emptyZone->id}&from_date=2026-04-15");

        $response->assertOk()
            ->assertJsonPath('data.target_zone.verdict', 'valid')
            ->assertJsonPath('data.target_zone.conflicting_previous_plant', null);
    }

    public function test_plant_without_family_or_rotation_group_returns_neutral_warning_without_crashing(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $targetZone = $this->createZoneForPlot($plot, ['name' => 'Open zone', 'zone_size' => 20]);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Seedlings', 'zone_size' => 20]);
        $mystery = $this->createProfiledPlant('Mystery crop', null, $plot, $currentZone);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/plots/{$plot->id}/rotations/recommendations?fk_plant_id={$mystery->id}&fk_plant_zone_id={$targetZone->id}&from_date=2026-04-15");

        $response->assertOk()
            ->assertJsonPath('data.target_zone.verdict', 'valid')
            ->assertJsonPath('data.target_zone.rotation_profile.family', null)
            ->assertJsonPath('data.target_zone.rotation_profile.group', null);

        $this->assertContains(
            'Rotation family or group data is missing, so family-based rotation checks are neutral for this plant.',
            data_get($response->json(), 'data.target_zone.soft_warnings', [])
        );
    }

    public function test_multiple_previous_plants_in_one_zone_detect_conflict_with_any_matching_family(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zoneA = $this->createZoneForPlot($plot, ['name' => 'Mixed 2025 bed', 'zone_size' => 20]);
        $archiveZone = $this->createZoneForPlot($plot, ['name' => 'Archive', 'zone_size' => 20]);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Seedlings', 'zone_size' => 20]);

        $carrot = $this->createProfiledPlant('Carrot 2025', 'Apiaceae', $plot, $archiveZone);
        $potato = $this->createProfiledPlant('Potato 2025', 'Solanaceae', $plot, $archiveZone);
        $pepper = $this->createProfiledPlant('Pepper 2026', 'Solanaceae', $plot, $currentZone);
        $this->createRotationHistoryForPlot($plot, $zoneA, $carrot, ['from_date' => '2025-04-01', 'to_date' => '2025-09-01']);
        $this->createRotationHistoryForPlot($plot, $zoneA, $potato, ['from_date' => '2025-04-01', 'to_date' => '2025-09-01']);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/plots/{$plot->id}/rotations/recommendations?fk_plant_id={$pepper->id}&fk_plant_zone_id={$zoneA->id}&from_date=2026-04-15");

        $response->assertOk()
            ->assertJsonPath('data.target_zone.verdict', 'invalid')
            ->assertJsonPath('data.target_zone.conflicting_previous_plant.id', $potato->id);
    }

    public function test_other_users_rotation_history_does_not_affect_or_leak_into_recommendations(): void
    {
        [$firstUser, $firstOwner] = $this->createGardenOwner('first@example.com');
        [, $secondOwner] = $this->createGardenOwner('second@example.com');
        $firstPlot = $this->createPlotForOwner($firstOwner);
        $secondPlot = $this->createPlotForOwner($secondOwner);
        $firstTarget = $this->createZoneForPlot($firstPlot, ['name' => 'First target', 'zone_size' => 20]);
        $firstCurrent = $this->createZoneForPlot($firstPlot, ['name' => 'First current', 'zone_size' => 20]);
        $secondTarget = $this->createZoneForPlot($secondPlot, ['name' => 'Second target', 'zone_size' => 20]);
        $secondArchive = $this->createZoneForPlot($secondPlot, ['name' => 'Second archive', 'zone_size' => 20]);

        $pepper = $this->createProfiledPlant('Pepper 2026', 'Solanaceae', $firstPlot, $firstCurrent);
        $secondTomato = $this->createProfiledPlant('Second tomato 2025', 'Solanaceae', $secondPlot, $secondArchive);
        $this->createRotationHistoryForPlot($secondPlot, $secondTarget, $secondTomato, ['from_date' => '2025-04-01', 'to_date' => '2025-09-01']);

        Sanctum::actingAs($firstUser);

        $this->getJson("/api/plots/{$secondPlot->id}/rotations/recommendations?fk_plant_id={$pepper->id}&fk_plant_zone_id={$secondTarget->id}&from_date=2026-04-15")
            ->assertForbidden();

        $this->getJson("/api/plots/{$firstPlot->id}/rotations/recommendations?fk_plant_id={$pepper->id}&fk_plant_zone_id={$firstTarget->id}&from_date=2026-04-15")
            ->assertOk()
            ->assertJsonPath('data.target_zone.verdict', 'valid')
            ->assertJsonPath('data.target_zone.conflicting_previous_plant', null);
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

        $this->assertContains('Target zone is the same as the current plant zone.', $blockingReasons);
        $this->assertNotContains('Target zone already contains the same broad plant type.', $blockingReasons);
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

        $this->assertContains('Target zone has enough space for this plant.', $selectedReasons);
        $this->assertSame('invalid', $candidateZones['Uzkrauta zona']['verdict']);
        $this->assertContains('Target zone does not have enough space for this plant.', $candidateZones['Uzkrauta zona']['blocking_reasons']);
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

    public function test_permanent_plantings_are_excluded_from_annual_rotation_without_blocking_confirmation(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $orchardZone = $this->createZoneForPlot($plot, ['name' => 'Young orchard', 'zone_size' => 30]);
        $this->createZoneForPlot($plot, ['name' => 'Open annual bed', 'zone_size' => 30]);
        $apple = $this->createProfiledPlant('Apple Tree', 'Rosaceae', $plot, $orchardZone, [
            'name' => "Apple Tree 'Auksis'",
            'type' => PlantType::Tree,
            'plant_size' => 8,
            'reusable' => true,
        ]);

        Sanctum::actingAs($user);

        $draftResponse = $this->postJson("/api/plots/{$plot->id}/rotations/plans", [
            'planning_date' => '2026-04-20',
        ])->assertCreated();

        $draftResponse
            ->assertJsonPath('draft.plan.status', 'ready')
            ->assertJsonPath('draft.plan.summary.annual_plant_count', 0)
            ->assertJsonPath('draft.plan.summary.permanent_plant_count', 1)
            ->assertJsonPath('draft.plan.summary.unresolved_plant_count', 0)
            ->assertJsonPath('draft.plan.plants.0.is_rotatable', false)
            ->assertJsonPath('draft.plan.plants.0.rotation_mode', 'permanent_planting')
            ->assertJsonPath('draft.plan.plants.0.exclusion_reason', 'Permanent planting — excluded from annual crop rotation.');

        $draftId = data_get($draftResponse->json(), 'draft.id');

        $this->postJson("/api/plots/{$plot->id}/rotations/plans/{$draftId}/confirm")
            ->assertOk()
            ->assertJsonPath('changed_plant_ids', []);

        $apple->refresh();

        $this->assertSame($orchardZone->id, $apple->plant_zone_id);
        $this->assertDatabaseMissing('rotation_plan_drafts', ['id' => $draftId]);
    }

    public function test_rotation_reason_messages_are_english_for_blocked_annual_plants(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, [
            'name' => 'Current annual bed',
            'zone_size' => 8,
            'last_planting_date' => '2026-04-18',
        ]);
        $blockedZone = $this->createZoneForPlot($plot, [
            'name' => 'Blocked annual bed',
            'zone_size' => 4,
            'last_planting_date' => '2026-04-19',
        ]);
        $this->createPlantForPlot($plot, $currentZone, [
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
        ])->assertCreated();

        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Target zone has not completed the required rotation rest interval.', $json);
        $this->assertStringContainsString('Target zone does not have enough space for this plant.', $json);
        $this->assertStringContainsString('Postpone the rotation to a later date so zones can complete the required rest interval.', $json);
        $this->assertStringNotContainsString('Tikslin', $json);
        $this->assertStringNotContainsString('Atid', $json);
        $this->assertStringNotContainsString('Ä', $json);
        $this->assertStringNotContainsString('Å', $json);
        $this->assertStringNotContainsString('Ć', $json);
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
        $this->assertContains('Target zone does not have enough space for this plant.', $candidates['Per maza']['blocking_reasons']);
    }
    public function test_blocked_candidate_is_never_selected_as_automatic_target(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Current', 'zone_size' => 8]);
        $blockedZone = $this->createZoneForPlot($plot, ['name' => 'Blocked', 'zone_size' => 4]);
        $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Cucumber',
            'plant_size' => 6,
            'rest_time_days' => 30,
            'type' => PlantType::Vegetable,
        ]);
        $this->createPlantForPlot($plot, $blockedZone, [
            'name' => 'Pumpkin',
            'plant_size' => 4,
            'type' => PlantType::Vegetable,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/plots/{$plot->id}/rotations/plans", [
            'planning_date' => '2026-04-20',
        ])->assertCreated();

        $entry = data_get($response->json(), 'draft.plan.plants.0');

        $this->assertNull($entry['selected_target_zone']);
        $this->assertSame('needs_adjustment', data_get($response->json(), 'draft.plan.status'));
        $this->assertNotEmpty($entry['fallback_solutions']);
    }

    public function test_selected_assigned_target_has_no_hard_blocking_reasons(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Current', 'zone_size' => 20]);
        $targetZone = $this->createZoneForPlot($plot, ['name' => 'Target', 'zone_size' => 20]);
        $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Salota',
            'plant_size' => 3,
            'rest_time_days' => 10,
            'type' => PlantType::Vegetable,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/plots/{$plot->id}/rotations/plans", [
            'planning_date' => '2026-04-20',
        ])->assertCreated();

        $target = data_get($response->json(), 'draft.plan.plants.0.selected_target_zone');

        $this->assertSame($targetZone->id, $target['zone_id']);
        $this->assertTrue($target['is_eligible']);
        $this->assertSame([], $target['hard_blocking_reasons']);
    }

    public function test_draft_assigned_occupancy_is_explained_separately_from_current_zone_state(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $firstZone = $this->createZoneForPlot($plot, ['name' => 'First', 'zone_size' => 4]);
        $secondZone = $this->createZoneForPlot($plot, ['name' => 'Second', 'zone_size' => 4]);
        $this->createZoneForPlot($plot, ['name' => 'Free rotation zone', 'zone_size' => 10]);

        $this->createPlantForPlot($plot, $firstZone, [
            'name' => 'Bean',
            'plant_size' => 4,
            'rest_time_days' => 10,
            'type' => PlantType::Vegetable,
        ]);
        $this->createPlantForPlot($plot, $secondZone, [
            'name' => 'Carrot',
            'plant_size' => 4,
            'rest_time_days' => 10,
            'type' => PlantType::Vegetable,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/plots/{$plot->id}/rotations/plans", [
            'planning_date' => '2026-04-20',
        ])->assertCreated();

        $freeCandidate = collect(data_get($response->json(), 'draft.plan.plants', []))
            ->flatMap(fn (array $entry) => $entry['candidate_zones'])
            ->first(fn (array $candidate) => $candidate['zone_name'] === 'Free rotation zone'
                && count($candidate['draft_assigned_occupants']) > 0);

        $this->assertNotNull($freeCandidate);
        $this->assertSame([], $freeCandidate['current_occupants']);
        $this->assertContains($freeCandidate['draft_assigned_occupants'][0]['name'], ['Bean', 'Carrot']);
        $this->assertTrue(collect($freeCandidate['soft_warnings'])->contains(
            fn (string $reason) => str_contains($reason, 'Broad plant type matches are informational only')
        ));
    }

    public function test_zero_assignment_draft_cannot_be_confirmed(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $currentZone = $this->createZoneForPlot($plot, ['name' => 'Only zone', 'zone_size' => 20]);
        $this->createPlantForPlot($plot, $currentZone, [
            'name' => 'Morka',
            'plant_size' => 3,
            'rest_time_days' => 10,
            'type' => PlantType::Vegetable,
        ]);

        Sanctum::actingAs($user);

        $draftResponse = $this->postJson("/api/plots/{$plot->id}/rotations/plans", [
            'planning_date' => '2026-04-20',
        ])->assertCreated();

        $draftId = data_get($draftResponse->json(), 'draft.id');

        $this->postJson("/api/plots/{$plot->id}/rotations/plans/{$draftId}/confirm")
            ->assertUnprocessable();
    }

    public function test_crop_rotation_mapping_uses_family_and_localized_name_fallbacks(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $classifier = app(CropRotationClassifier::class);

        $cases = [
            ['Pupa', 'Fabaceae', 'legume'],
            ['Morka', 'Apiaceae', 'root'],
            ['Salota', 'Asteraceae', 'leafy'],
            ['SvogÅ«nas', 'Amaryllidaceae', 'allium'],
            ['BurokÄ—lis', 'Amaranthaceae', 'root'],
            ['Agurkas', 'Cucurbitaceae', 'cucurbit'],
        ];

        foreach ($cases as [$name, $family, $expectedGroup]) {
            $care = PlantCare::query()->create([
                'description' => "{$name} care",
                'conditions' => 'well drained fertile soil',
                'plant_name' => $name,
                'canonical_name' => strtolower($name),
                'task_type' => 'watering',
                'plant_type' => PlantType::Vegetable,
                'condition' => 'growing',
                'source_family' => $family,
            ]);
            $plant = $this->createPlantForPlot($plot, $zone, [
                'name' => $name,
                'fk_plant_care_id' => $care->id,
                'type' => PlantType::Vegetable,
            ]);

            $profile = $classifier->profileForPlant($plant->fresh(['catalogPlant.plantCare']));

            $this->assertSame($expectedGroup, $profile['group']);
            $this->assertSame($family, $profile['family']);
        }
    }
}
