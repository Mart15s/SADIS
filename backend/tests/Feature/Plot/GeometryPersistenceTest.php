<?php

namespace Tests\Feature\Plot;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class GeometryPersistenceTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    private const PLOT_GEOMETRY = [
        'points' => [
            ['x' => 0.10, 'y' => 0.10],
            ['x' => 0.88, 'y' => 0.12],
            ['x' => 0.84, 'y' => 0.82],
            ['x' => 0.12, 'y' => 0.78],
        ],
    ];

    private const ZONE_GEOMETRY = [
        'points' => [
            ['x' => 0.18, 'y' => 0.18],
            ['x' => 0.46, 'y' => 0.20],
            ['x' => 0.43, 'y' => 0.46],
            ['x' => 0.20, 'y' => 0.44],
        ],
    ];

    public function test_plot_geometry_can_be_saved_and_returned(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/plots', [
            'name' => 'Geometrijos sklypas',
            'city' => 'Vilnius',
            'plot_size' => 120,
            'creation_date' => '2026-03-27',
            'geometry' => self::PLOT_GEOMETRY,
        ]);

        $response->assertCreated()
            ->assertJsonPath('geometry.points.0.x', 0.10)
            ->assertJsonPath('geometry.points.3.y', 0.78);

        $plotId = $response->json('id');

        $showResponse = $this->getJson("/api/plots/{$plotId}");

        $showResponse->assertOk()
            ->assertJsonPath('geometry.points.1.x', 0.88)
            ->assertJsonPath('geometry.points.2.y', 0.82);
    }

    public function test_plot_geometry_supports_three_point_boundaries(): void
    {
        [$user] = $this->createGardenOwner('triangle-owner@example.com');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/plots', [
            'name' => 'Trikampis sklypas',
            'city' => 'Vilnius',
            'plot_size' => 86.5,
            'creation_date' => '2026-03-27',
            'geometry' => [
                'points' => [
                    ['x' => 0.10, 'y' => 0.12],
                    ['x' => 0.88, 'y' => 0.18],
                    ['x' => 0.42, 'y' => 0.82],
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('geometry.points.0.x', 0.10)
            ->assertJsonPath('geometry.points.2.y', 0.82);
    }

    public function test_plant_zone_geometry_can_be_saved_and_returned(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'geometry' => self::PLOT_GEOMETRY,
        ]);

        Sanctum::actingAs($user);

        $createResponse = $this->postJson("/api/plots/{$plot->id}/plant-zones", [
            'name' => 'Zona su geometrija',
            'zone_size' => 24,
            'soil_type' => 'clay',
            'rotation_stage' => 1,
            'last_planting_date' => '2026-03-26',
            'geometry' => self::ZONE_GEOMETRY,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('geometry.points.0.x', 0.18)
            ->assertJsonPath('geometry.points.2.y', 0.46);

        $zoneId = $createResponse->json('id');

        $updateResponse = $this->patchJson("/api/plots/{$plot->id}/plant-zones/{$zoneId}", [
            'geometry' => [
                'points' => [
                    ['x' => 0.22, 'y' => 0.21],
                    ['x' => 0.51, 'y' => 0.24],
                    ['x' => 0.47, 'y' => 0.49],
                    ['x' => 0.24, 'y' => 0.46],
                ],
            ],
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('geometry.points.0.x', 0.22)
            ->assertJsonPath('geometry.points.1.y', 0.24);

        $listResponse = $this->getJson("/api/plots/{$plot->id}/plant-zones");

        $listResponse->assertOk()
            ->assertJsonPath('0.geometry.points.2.x', 0.47)
            ->assertJsonPath('0.geometry.points.3.y', 0.46);
    }

    public function test_legacy_null_geometry_remains_supported(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'geometry' => null,
        ]);
        $zone = $this->createZoneForPlot($plot, [
            'geometry' => null,
        ]);

        Sanctum::actingAs($user);

        $plotResponse = $this->getJson("/api/plots/{$plot->id}");
        $zonesResponse = $this->getJson("/api/plots/{$plot->id}/plant-zones");

        $plotResponse->assertOk()
            ->assertJsonPath('id', $plot->id)
            ->assertJsonPath('geometry', null);

        $zonesResponse->assertOk()
            ->assertJsonPath('0.id', $zone->id)
            ->assertJsonPath('0.geometry', null);
    }

    public function test_workspace_save_rejects_out_of_range_geometry_payload(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'geometry' => self::PLOT_GEOMETRY,
        ]);
        $zone = $this->createZoneForPlot($plot, [
            'geometry' => self::ZONE_GEOMETRY,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/plots/{$plot->id}/workspace", [
            'plot' => [
                'plot_size' => 120,
                'geometry' => [
                    'points' => [
                        ['x' => 0, 'y' => 0],
                        ['x' => 1.2, 'y' => 0],
                        ['x' => 1, 'y' => 1],
                        ['x' => 0, 'y' => 1],
                    ],
                ],
            ],
            'zones' => [[
                'id' => $zone->id,
                'name' => $zone->name,
                'zone_size' => 24,
                'soil_type' => 'clay',
                'rotation_stage' => 0,
                'last_planting_date' => '2026-03-26',
                'geometry' => self::ZONE_GEOMETRY,
            ]],
            'plants' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['plot.geometry']);
    }
}
