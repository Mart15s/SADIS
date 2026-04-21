<?php

namespace Tests\Feature\Plant;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Models\GardenOwner;
use App\Models\Plant;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlantMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_log_condition_history_and_rotation_history(): void
    {
        [$user, $profile] = $this->createGardenOwner();
        Sanctum::actingAs($user);

        $plot = Plot::create([
            'name' => 'Stebejimu sklypas',
            'city' => 'Alytus',
            'plot_size' => 100,
            'creation_date' => '2026-03-20',
            'share' => false,
        ]);

        \App\Models\HasPlot::create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        $zone = PlantZone::create([
            'name' => 'Zona B',
            'zone_size' => 20,
            'soil_type' => SoilType::Sandy,
            'rotation_stage' => 0,
            'fk_plot_id' => $plot->id,
        ]);

        $plant = Plant::create([
            'name' => 'Agurkas',
            'plant_date' => '2026-03-20',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Planted,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
        ]);

        $conditionResponse = $this->postJson("/api/plots/{$plot->id}/plants/{$plant->id}/conditions", [
            'measured_at' => '2026-03-21 08:00:00',
            'condition' => ConditionType::Growing->value,
            'notes' => 'Augalas auga stabiliai',
        ]);

        $conditionResponse->assertCreated()
            ->assertJsonPath('condition', ConditionType::Growing->value);

        $rotationResponse = $this->postJson("/api/plots/{$plot->id}/rotations", [
            'from_date' => '2026-03-22',
            'to_date' => '2026-03-25',
            'fk_plant_zone_id' => $zone->id,
            'fk_plant_id' => $plant->id,
        ]);

        $rotationResponse->assertCreated()
            ->assertJsonPath('rotation.fk_plot_id', $plot->id)
            ->assertJsonPath('evaluation.target_zone.id', $zone->id);

        $historyResponse = $this->getJson("/api/plots/{$plot->id}/plants/{$plant->id}/conditions");
        $historyResponse->assertOk()->assertJsonCount(1);

        $rotationIndexResponse = $this->getJson("/api/plots/{$plot->id}/rotations");
        $rotationIndexResponse->assertOk()->assertJsonCount(1);

        $plant->refresh();
        $zone->refresh();

        $this->assertSame(ConditionType::Growing, $plant->condition);
        $this->assertSame(1, $zone->rotation_stage);
    }

    public function test_condition_logging_persists_boolean_disease_flag(): void
    {
        [$user, $profile] = $this->createGardenOwner();
        Sanctum::actingAs($user);

        $plot = Plot::create([
            'name' => 'Ligu stebejimo sklypas',
            'city' => 'Alytus',
            'plot_size' => 80,
            'creation_date' => '2026-03-20',
            'share' => false,
        ]);

        \App\Models\HasPlot::create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        $zone = PlantZone::create([
            'name' => 'Zona C',
            'zone_size' => 18,
            'soil_type' => SoilType::Clay,
            'rotation_stage' => 0,
            'fk_plot_id' => $plot->id,
        ]);

        $plant = Plant::create([
            'name' => 'Salota',
            'plant_date' => '2026-03-20',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Planted,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
        ]);

        $this->postJson("/api/plots/{$plot->id}/plants/{$plant->id}/conditions", [
            'measured_at' => '2026-03-21 08:00:00',
            'condition' => ConditionType::Diseased->value,
        ])->assertCreated()
            ->assertJsonPath('condition_type', ConditionType::Diseased->value);

        $plant->refresh();

        $this->assertTrue($plant->disease);
        $this->assertSame(ConditionType::Diseased, $plant->condition);
        $this->assertDatabaseHas('plant_condition_history', [
            'fk_plant_id' => $plant->id,
            'plant_id' => $plant->id,
            'condition' => ConditionType::Diseased->value,
            'condition_type' => ConditionType::Diseased->value,
        ]);
    }

    private function createGardenOwner(): array
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'name' => 'Rasa',
            'surname' => 'Rasaite',
        ]);

        GardenOwner::create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        return [$user, $profile];
    }
}
