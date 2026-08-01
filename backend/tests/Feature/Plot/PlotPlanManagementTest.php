<?php

namespace Tests\Feature\Plot;

use App\Enums\AccessRole;
use App\Models\PlantZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class PlotPlanManagementTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_zone_colour_is_created_normalized_returned_and_updated(): void
    {
        [$user, $owner] = $this->createGardenOwner('colour-owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        Sanctum::actingAs($user);

        $zoneId = $this->postJson("/api/plots/{$plot->id}/plant-zones", [
            'name' => 'Šiltnamis',
            'zone_size' => 20,
            'soil_type' => 'clay',
            'color_hex' => '#1a2b3c',
        ])->assertCreated()->assertJsonPath('color_hex', '#1A2B3C')->json('id');

        $this->patchJson("/api/plots/{$plot->id}/plant-zones/{$zoneId}", [
            'color_hex' => '#4caf50',
        ])->assertOk()->assertJsonPath('color_hex', '#4CAF50');

        $this->assertDatabaseHas('plant_zones', ['id' => $zoneId, 'color_hex' => '#4CAF50']);
    }

    public function test_invalid_hex_is_rejected_and_legacy_request_gets_deterministic_fallback(): void
    {
        [$user, $owner] = $this->createGardenOwner('fallback-owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        Sanctum::actingAs($user);

        $this->postJson("/api/plots/{$plot->id}/plant-zones", [
            'name' => 'Bloga spalva', 'zone_size' => 10, 'soil_type' => 'clay', 'color_hex' => '#FFF',
        ])->assertUnprocessable()->assertJsonValidationErrors('color_hex');

        $first = $this->postJson("/api/plots/{$plot->id}/plant-zones", [
            'name' => 'Pirma', 'zone_size' => 10, 'soil_type' => 'clay',
        ])->assertCreated()->json();

        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $first['color_hex']);
        $this->assertSame('#4F7A5A', $first['color_hex']);
    }

    public function test_zone_supports_multiple_planting_records_and_aggregate_response(): void
    {
        [$user, $owner] = $this->createGardenOwner('aggregate-owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot, ['name' => 'Daržo lysvė']);
        $this->createPlantForPlot($plot, $zone, ['name' => 'Pomidoras', 'quantity' => 12]);
        $this->createPlantForPlot($plot, $zone, ['name' => 'Bazilikas', 'quantity' => 6]);
        Sanctum::actingAs($user);

        $this->getJson("/api/plots/{$plot->id}/plant-zones")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.active_planting_count', 2)
            ->assertJsonPath('0.principal_plants.0', 'Pomidoras');

        $this->getJson("/api/plots/{$plot->id}/plants")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.quantity', 12);
    }

    public function test_protected_zone_returns_structured_conflict_and_archive_preserves_plants(): void
    {
        [$user, $owner] = $this->createGardenOwner('archive-owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/plots/{$plot->id}/plant-zones/{$zone->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'zone_has_protected_history')
            ->assertJsonPath('associations.active_planting_count', 1)
            ->assertJsonPath('available_actions.0', 'archive');

        $this->postJson("/api/plots/{$plot->id}/plant-zones/{$zone->id}/archive")
            ->assertOk()
            ->assertJsonPath('is_archived', true);

        $this->assertDatabaseHas('plants', ['id' => $plant->id, 'fk_plant_zone_id' => $zone->id]);
        $this->assertNotNull(PlantZone::findOrFail($zone->id)->archived_at);
        $this->getJson("/api/plots/{$plot->id}/plant-zones")->assertOk()->assertJsonCount(0);
        $this->getJson("/api/plots/{$plot->id}/plant-zones?include_archived=1")->assertOk()->assertJsonCount(1);
    }

    public function test_archived_zone_is_not_a_valid_active_planting_destination(): void
    {
        [$user, $owner] = $this->createGardenOwner('destination-owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot, ['archived_at' => now()]);
        Sanctum::actingAs($user);

        $this->postJson("/api/plots/{$plot->id}/plants", [
            'name' => 'Morka',
            'plant_date' => '2026-04-01',
            'type' => 'vegetable',
            'condition' => 'growing',
            'fk_plant_zone_id' => $zone->id,
        ])->assertUnprocessable();
    }

    public function test_workspace_omission_archives_protected_zone_without_deleting_plant_history(): void
    {
        [$user, $owner] = $this->createGardenOwner('workspace-archive@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        Sanctum::actingAs($user);

        $this->putJson("/api/plots/{$plot->id}/workspace", [
            'plot' => ['plot_size' => $plot->plot_size, 'geometry' => null],
            'zones' => [],
            'plants' => [],
        ])->assertOk()
            ->assertJsonCount(0, 'zones')
            ->assertJsonPath('changes.zones.archived', 1);

        $this->assertDatabaseHas('plants', ['id' => $plant->id, 'fk_plant_zone_id' => $zone->id]);
        $this->assertNotNull(PlantZone::findOrFail($zone->id)->archived_at);
    }

    public function test_read_only_user_cannot_change_colour_or_assign_planting(): void
    {
        [, $owner] = $this->createGardenOwner('permission-owner@example.com');
        [$viewer, $viewerOwner] = $this->createGardenOwner('permission-viewer@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $this->createAccessRight($owner, $plot, $viewerOwner, AccessRole::Viewer);
        Sanctum::actingAs($viewer);

        $this->patchJson("/api/plots/{$plot->id}/plant-zones/{$zone->id}", ['color_hex' => '#4CAF50'])->assertForbidden();
        $this->postJson("/api/plots/{$plot->id}/plants", [
            'name' => 'Salota', 'plant_date' => '2026-04-01', 'type' => 'vegetable', 'condition' => 'growing', 'fk_plant_zone_id' => $zone->id,
        ])->assertForbidden();
    }
}
