<?php

namespace Tests\Feature\Plot;

use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Models\GardenOwner;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlotManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_plot_zone_and_plant(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'name' => 'Ieva',
            'surname' => 'Kazlauskiene',
        ]);

        GardenOwner::create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        Sanctum::actingAs($user);

        $plotResponse = $this->postJson('/api/plots', [
            'name' => 'Pagrindinis sklypas',
            'city' => 'Vilnius',
            'plot_size' => 120.5,
            'creation_date' => '2026-03-20',
            'description' => 'Bandymu sklypas',
            'share' => true,
        ]);

        $plotResponse->assertCreated();
        $plotId = $plotResponse->json('id');

        $zoneResponse = $this->postJson("/api/plots/{$plotId}/plant-zones", [
            'name' => 'Zona A',
            'zone_size' => 45.2,
            'soil_type' => SoilType::Clay->value,
            'rotation_stage' => 1,
            'last_planting_date' => '2026-03-19',
        ]);

        $zoneResponse->assertCreated();
        $zoneId = $zoneResponse->json('id');

        $plantResponse = $this->postJson("/api/plots/{$plotId}/plants", [
            'name' => 'Pomidoras',
            'plant_date' => '2026-03-20',
            'type' => PlantType::Vegetable->value,
            'condition' => ConditionType::Growing->value,
            'fk_plant_zone_id' => $zoneId,
        ]);

        $plantResponse->assertCreated()
            ->assertJsonPath('fk_plot_id', $plotId)
            ->assertJsonPath('fk_plant_zone_id', $zoneId);

        $this->assertDatabaseHas('plots', [
            'id' => $plotId,
            'garden_owner_id' => $user->id,
        ]);
    }

    public function test_owner_only_sees_owned_plots(): void
    {
        [$user, $profile] = $this->createGardenOwner('owner@example.com');
        [$otherUser, $otherProfile] = $this->createGardenOwner('other@example.com');

        Sanctum::actingAs($user);

        $ownedPlot = $this->postJson('/api/plots', [
            'name' => 'Mano sklypas',
            'city' => 'Kaunas',
            'plot_size' => 50,
            'creation_date' => '2026-03-20',
        ])->json();

        Sanctum::actingAs($otherUser);

        $this->postJson('/api/plots', [
            'name' => 'Svetimas sklypas',
            'city' => 'Klaipeda',
            'plot_size' => 70,
            'creation_date' => '2026-03-20',
        ])->assertCreated();

        Sanctum::actingAs($user);

        $indexResponse = $this->getJson('/api/plots');

        $indexResponse->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ownedPlot['id']);
    }

    public function test_owner_can_retrieve_plot_history_snapshots(): void
    {
        [$user] = $this->createGardenOwner('history@example.com');

        Sanctum::actingAs($user);

        $plotId = $this->postJson('/api/plots', [
            'name' => 'Istorijos sklypas',
            'city' => 'Vilnius',
            'plot_size' => 60,
            'creation_date' => '2026-03-20',
        ])->assertCreated()->json('id');

        $this->patchJson("/api/plots/{$plotId}", [
            'description' => 'Atnaujintas aprasymas',
        ])->assertOk();

        $this->getJson("/api/plots/{$plotId}/history")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'plot_updated')
            ->assertJsonPath('data.1.action', 'plot_created')
            ->assertJsonPath('data.0.snapshot.plot.id', $plotId);
    }

    private function createGardenOwner(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $profile = Profile::create([
            'name' => 'Vardenis',
            'surname' => 'Pavardenis',
        ]);

        GardenOwner::create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        return [$user, $profile];
    }
}
