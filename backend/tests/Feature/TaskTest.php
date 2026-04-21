<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\CreatesGardenData;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use CreatesGardenData;
    use RefreshDatabase;

    public function test_calendar_task_listing_includes_plant_and_zone_names(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot, ['name' => 'Darzo zona']);
        $plant = $this->createPlantForPlot($plot, $zone, ['name' => 'Pomidoras']);
        $calendar = $this->createCalendarForPlot($plot);

        $this->createTaskForCalendar($calendar, [
            'name' => 'Laistyti pomidorus',
            'fk_plant_id' => $plant->id,
        ]);

        Sanctum::actingAs($ownerUser);

        $response = $this->getJson("/api/calendars/{$calendar->id}/tasks");

        $response->assertOk();

        $payload = $response->json();
        $task = $payload['data'][0] ?? $payload[0] ?? null;

        $this->assertNotNull($task);
        $this->assertSame('Pomidoras', $task['plant_name'] ?? null);
        $this->assertSame('Darzo zona', $task['zone_name'] ?? null);
    }
}
