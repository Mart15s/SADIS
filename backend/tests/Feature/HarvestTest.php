<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class HarvestTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_owner_can_register_and_view_harvest_history(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Pomidoras',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/plots/{$plot->id}/harvests", [
            'plant_id' => $plant->id,
            'quantity' => 12.5,
            'harvested_on' => '2026-04-03',
            'notes' => 'Pirmas rinkimas',
        ])->assertCreated()
            ->assertJsonPath('data.plant_name', 'Pomidoras')
            ->assertJsonPath('data.quantity', 12.5);

        $this->getJson("/api/plots/{$plot->id}/harvests")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plant_name', 'Pomidoras')
            ->assertJsonPath('data.0.notes', 'Pirmas rinkimas');
    }

    public function test_harvest_analytics_use_explicit_harvest_records(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone, [
            'name' => 'Agurkas',
        ]);
        $calendar = $this->createCalendarForPlot($plot);
        $task = $this->createTaskForCalendar($calendar, [
            'name' => 'Derliaus langas',
            'type' => 'harvest',
            'task_type' => 'harvest',
            'status' => 'pending',
            'fk_plant_id' => $plant->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/plots/{$plot->id}/harvests", [
            'plant_id' => $plant->id,
            'task_id' => $task->id,
            'quantity' => 8,
            'harvested_on' => '2026-04-03',
        ])->assertCreated();

        $this->postJson("/api/plots/{$plot->id}/analytics", [
            'analysisTypes' => ['harvest'],
        ])
            ->assertOk()
            ->assertJsonPath('sections.harvest.total_records', 1)
            ->assertJsonPath('sections.harvest.total_quantity', 8)
            ->assertJsonPath('sections.harvest.best_yielding_plants.0.plant_name', 'Agurkas');
    }
}
