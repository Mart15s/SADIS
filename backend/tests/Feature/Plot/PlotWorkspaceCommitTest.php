<?php

namespace Tests\Feature\Plot;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class PlotWorkspaceCommitTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_workspace_commit_saves_one_meaningful_history_version(): void
    {
        [$user, $owner] = $this->createGardenOwner('workspace-owner@example.com');
        Sanctum::actingAs($user);

        $plot = $this->createPlotForOwner($owner, [
            'plot_size' => 120,
            'geometry' => [
                'kind' => 'polygon',
                'points' => [
                    ['x' => 0, 'y' => 0],
                    ['x' => 1, 'y' => 0],
                    ['x' => 1, 'y' => 1],
                    ['x' => 0, 'y' => 1],
                ],
            ],
        ]);
        $zone = $this->createZoneForPlot($plot, [
            'name' => 'Existing zone',
            'zone_size' => 24,
            'geometry' => [
                'kind' => 'polygon',
                'points' => [
                    ['x' => 0, 'y' => 0],
                    ['x' => 0.5, 'y' => 0],
                    ['x' => 0.5, 'y' => 0.4],
                    ['x' => 0, 'y' => 0.4],
                ],
            ],
        ]);
        $plant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Tomato',
        ]);

        $response = $this->putJson("/api/plots/{$plot->id}/workspace", [
            'plot' => [
                'plot_size' => 120,
                'geometry' => [
                    'kind' => 'polygon',
                    'points' => [
                        ['x' => 0, 'y' => 0],
                        ['x' => 1, 'y' => 0],
                        ['x' => 1, 'y' => 1],
                        ['x' => 0, 'y' => 1],
                    ],
                ],
            ],
            'zones' => [[
                'id' => $zone->id,
                'name' => 'North bed',
                'zone_size' => 24,
                'soil_type' => $zone->soil_type?->value ?? $zone->soil_type,
                'rotation_stage' => 1,
                'last_planting_date' => '2026-04-20',
                'geometry' => [
                    'kind' => 'polygon',
                    'points' => [
                        ['x' => 0.5, 'y' => 0.6],
                        ['x' => 1, 'y' => 0.6],
                        ['x' => 1, 'y' => 1],
                        ['x' => 0.5, 'y' => 1],
                    ],
                ],
            ]],
            'plants' => [[
                'id' => $plant->id,
                'name' => 'Tomato',
                'type' => $plant->type?->value ?? $plant->type,
                'condition' => $plant->condition?->value ?? $plant->condition,
                'plant_date' => '2026-03-20',
                'disease' => false,
                'disease_notes' => null,
                'fk_catalog_plant_id' => null,
                'fk_plant_zone_id' => $zone->id,
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('history_entry.label', 'Saved plot version');

        $this->assertDatabaseHas('plant_zones', [
            'id' => $zone->id,
            'name' => 'North bed',
            'rotation_stage' => 1,
        ]);

        $this->assertDatabaseCount('plot_snapshots', 1);
        $this->assertDatabaseHas('plot_snapshots', [
            'plot_id' => $plot->id,
            'action' => 'plot_saved',
        ]);

        $historyResponse = $this->getJson("/api/plots/{$plot->id}/history");

        $historyResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Saved plot version')
            ->assertJsonPath('data.0.plant_count', 1)
            ->assertJsonPath('data.0.zone_count', 1);

        $snapshotPayload = json_decode((string) DB::table('plot_snapshots')->value('snapshot'), true);

        $this->assertSame('North bed', data_get($snapshotPayload, 'zones.0.name'));
        $this->assertSame('Saved plot version', data_get($snapshotPayload, 'metadata.label'));
    }

    public function test_workspace_commit_appends_temp_id_zone_without_overwriting_existing_zones(): void
    {
        [$user, $owner] = $this->createGardenOwner('workspace-append@example.com');
        Sanctum::actingAs($user);

        $plot = $this->createPlotForOwner($owner, [
            'plot_size' => 120,
            'geometry' => [
                'points' => [
                    ['x' => 0, 'y' => 0],
                    ['x' => 1, 'y' => 0],
                    ['x' => 1, 'y' => 1],
                    ['x' => 0, 'y' => 1],
                ],
            ],
        ]);
        $zones = collect([
            $this->createZoneForPlot($plot, ['name' => 'North bed', 'zone_size' => 20]),
            $this->createZoneForPlot($plot, ['name' => 'South bed', 'zone_size' => 20]),
            $this->createZoneForPlot($plot, ['name' => 'East bed', 'zone_size' => 20]),
        ]);

        $response = $this->putJson("/api/plots/{$plot->id}/workspace", [
            'plot' => [
                'plot_size' => 120,
                'geometry' => $plot->geometry,
            ],
            'zones' => $zones->map(fn ($zone): array => [
                'id' => $zone->id,
                'name' => $zone->name,
                'zone_size' => $zone->zone_size,
                'soil_type' => $zone->soil_type?->value ?? $zone->soil_type,
                'rotation_stage' => $zone->rotation_stage,
                'last_planting_date' => $zone->last_planting_date,
                'geometry' => $zone->geometry,
            ])->push([
                'id' => 'draft-zone-unique',
                'client_id' => 'draft-zone-unique',
                'name' => 'West bed',
                'zone_size' => 20,
                'soil_type' => $zones->first()->soil_type?->value ?? $zones->first()->soil_type,
                'rotation_stage' => 0,
                'last_planting_date' => null,
                'geometry' => [
                    'points' => [
                        ['x' => 0.5, 'y' => 0.5],
                        ['x' => 0.8, 'y' => 0.5],
                        ['x' => 0.8, 'y' => 0.8],
                        ['x' => 0.5, 'y' => 0.8],
                    ],
                ],
            ])->values()->all(),
            'plants' => [],
        ]);

        $response->assertOk()
            ->assertJsonCount(4, 'zones');

        $this->assertDatabaseCount('plant_zones', 4);
        $this->assertDatabaseHas('plant_zones', ['id' => $zones[0]->id, 'name' => 'North bed']);
        $this->assertDatabaseHas('plant_zones', ['id' => $zones[1]->id, 'name' => 'South bed']);
        $this->assertDatabaseHas('plant_zones', ['id' => $zones[2]->id, 'name' => 'East bed']);
        $this->assertDatabaseHas('plant_zones', ['name' => 'West bed']);
    }
}
