<?php

namespace Tests\Feature\Plot;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class PlantMarkerPositionTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_workspace_persists_a_valid_zone_local_marker_position(): void
    {
        [$user, $owner] = $this->createGardenOwner('marker-owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        Sanctum::actingAs($user);

        $geometry = ['points' => [
            ['x' => 0.1, 'y' => 0.1], ['x' => 0.9, 'y' => 0.1], ['x' => 0.9, 'y' => 0.9], ['x' => 0.1, 'y' => 0.9],
        ]];

        $this->putJson("/api/plots/{$plot->id}/workspace", [
            'plot' => ['plot_size' => $plot->plot_size, 'geometry' => null],
            'zones' => [[
                'id' => $zone->id, 'name' => $zone->name, 'zone_size' => 25, 'soil_type' => 'clay', 'geometry' => $geometry,
            ]],
            'plants' => [[
                'id' => $plant->id, 'name' => $plant->name, 'type' => 'vegetable', 'condition' => 'growing', 'plant_date' => '2026-03-20',
                'fk_plant_zone_id' => $zone->id, 'marker_position_x' => 0.25, 'marker_position_y' => 0.75,
            ]],
        ])->assertOk()->assertJsonPath('plants.0.marker_position_x', 0.25)->assertJsonPath('plants.0.marker_position_y', 0.75);

        $this->assertDatabaseHas('plants', ['id' => $plant->id, 'marker_position_x' => 0.25, 'marker_position_y' => 0.75]);
    }

    public function test_workspace_rejects_a_marker_outside_its_zone(): void
    {
        [$user, $owner] = $this->createGardenOwner('marker-invalid@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);
        Sanctum::actingAs($user);

        $triangle = ['points' => [['x' => 0.1, 'y' => 0.1], ['x' => 0.9, 'y' => 0.1], ['x' => 0.1, 'y' => 0.9]]];
        $this->putJson("/api/plots/{$plot->id}/workspace", [
            'plot' => ['plot_size' => $plot->plot_size, 'geometry' => null],
            'zones' => [['id' => $zone->id, 'name' => $zone->name, 'zone_size' => 25, 'soil_type' => 'clay', 'geometry' => $triangle]],
            'plants' => [['id' => $plant->id, 'name' => $plant->name, 'type' => 'vegetable', 'condition' => 'growing', 'plant_date' => '2026-03-20', 'fk_plant_zone_id' => $zone->id, 'marker_position_x' => 0.9, 'marker_position_y' => 0.9]],
        ])->assertUnprocessable()->assertJsonValidationErrors('plants');
    }
}
