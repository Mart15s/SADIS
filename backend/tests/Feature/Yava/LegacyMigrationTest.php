<?php

namespace Tests\Feature\Yava;

use App\Models\GardenOwner;
use App\Models\Plant;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\User;
use App\Services\Yava\LegacyMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifier_is_deterministic_and_execute_is_idempotent(): void
    {
        $user = User::factory()->create();
        $profile = Profile::query()->create(['user_id' => $user->id, 'name' => 'Legacy', 'surname' => 'Owner']);
        $owner = GardenOwner::query()->create(['id' => $user->id, 'user_id' => $user->id, 'id_user' => $user->id, 'fk_profile_id' => $profile->id]);
        $plot = Plot::query()->create(['garden_owner_id' => $owner->id, 'name' => 'Legacy Plot', 'city' => 'Mysuru', 'plot_size' => 2000, 'creation_date' => '2025-01-01']);
        $zone = PlantZone::query()->create(['name' => 'Legacy Zone', 'zone_size' => 500, 'soil_type' => 'sandy', 'fk_plot_id' => $plot->id, 'plot_id' => $plot->id]);
        $plant = Plant::query()->create([
            'name' => 'Tomato', 'plant_date' => '2026-05-01', 'type' => 'vegetable', 'condition' => 'flowering',
            'fk_plant_zone_id' => $zone->id, 'plant_zone_id' => $zone->id, 'fk_plot_id' => $plot->id,
            'quantity' => 20, 'occupied_area' => 50,
        ]);

        $service = app(LegacyMigrationService::class);
        $this->assertSame('high_confidence_crop_season', $service->classify($plant)['classification']);
        $dryRun = $service->run(false, 10);
        $this->assertSame('completed', $dryRun->status);
        $this->assertDatabaseCount('farms', 0);

        $execute = $service->run(true, 10);
        $this->assertSame('completed', $execute->status);
        $this->assertDatabaseCount('farms', 1);
        $this->assertDatabaseCount('fields', 1);
        $this->assertDatabaseCount('crop_seasons', 1);
        $service->run(true, 10);
        $this->assertDatabaseCount('farms', 1);
        $this->assertDatabaseCount('crop_seasons', 1);
        $this->assertSame(0, $service->counts()['unmapped']['plants']);
    }
}
