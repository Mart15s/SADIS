<?php

namespace Tests\Feature;

use App\Enums\AccessRole;
use App\Enums\ConditionType;
use App\Enums\PlantType;
use App\Enums\SoilType;
use App\Models\AccessRight;
use App\Models\GardenOwner;
use App\Models\HasPlot;
use App\Models\Plant;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessRightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_share_plot(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$recipientUser, $recipient] = $this->createGardenOwner('recipient@example.com');
        $plot = $this->createPlotForOwner($owner);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/share", [
            'recipient_email' => $recipientUser->email,
            'role' => AccessRole::Viewer->value,
        ])->assertCreated()
            ->assertJsonPath('message', 'Prieiga sekmingai suteikta')
            ->assertJsonPath('access_right.role', AccessRole::Viewer->value);

        $this->assertDatabaseHas('access_rights', [
            'fk_plot_id' => $plot->id,
            'fk_grantor_owner_id' => $owner->id_user,
            'fk_grantor_profile_id' => $owner->fk_profile_id,
            'fk_recipient_owner_id' => $recipient->id_user,
            'fk_recipient_profile_id' => $recipient->fk_profile_id,
            'role' => AccessRole::Viewer->value,
        ]);

        $this->assertDatabaseHas('plot_snapshots', [
            'plot_id' => $plot->id,
            'garden_owner_id' => $owner->id,
            'action' => 'plot_access_granted',
        ]);
    }

    public function test_owner_cannot_share_with_self(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/share", [
            'recipient_email' => $ownerUser->email,
            'role' => AccessRole::Viewer->value,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_email']);
    }

    public function test_owner_cannot_share_twice_with_same_user(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$recipientUser, $recipient] = $this->createGardenOwner('recipient@example.com');
        $plot = $this->createPlotForOwner($owner);

        $this->createAccessRight($owner, $plot, $recipient, AccessRole::Viewer);

        Sanctum::actingAs($ownerUser);

        $this->postJson("/api/plots/{$plot->id}/share", [
            'recipient_email' => $recipientUser->email,
            'role' => AccessRole::Editor->value,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_email']);
    }

    public function test_non_owner_cannot_share(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$editorUser, $editor] = $this->createGardenOwner('editor@example.com');
        [$recipientUser] = $this->createGardenOwner('recipient@example.com');
        $plot = $this->createPlotForOwner($owner);

        $this->createAccessRight($owner, $plot, $editor, AccessRole::Editor);

        Sanctum::actingAs($editorUser);

        $this->postJson("/api/plots/{$plot->id}/share", [
            'recipient_email' => $recipientUser->email,
            'role' => AccessRole::Viewer->value,
        ])->assertForbidden();
    }

    public function test_recipient_gets_access_after_share(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$recipientUser, $recipient] = $this->createGardenOwner('recipient@example.com');
        $plot = $this->createPlotForOwner($owner);

        Sanctum::actingAs($ownerUser);
        $this->postJson("/api/plots/{$plot->id}/share", [
            'recipient_email' => $recipientUser->email,
            'role' => AccessRole::Viewer->value,
        ])->assertCreated();

        Sanctum::actingAs($recipientUser);

        $this->getJson('/api/plots')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $plot->id)
            ->assertJsonPath('0.access_role', AccessRole::Viewer->value);
    }

    public function test_viewer_cannot_edit_plot(): void
    {
        [$owner, $recipientUser, $plot] = $this->createSharedPlotContext(AccessRole::Viewer);

        Sanctum::actingAs($recipientUser);

        $this->patchJson("/api/plots/{$plot->id}", [
            'name' => 'Naujas pavadinimas',
        ])->assertForbidden();
    }

    public function test_editor_can_edit_plot(): void
    {
        [$owner, $recipientUser, $plot] = $this->createSharedPlotContext(AccessRole::Editor);

        Sanctum::actingAs($recipientUser);

        $this->patchJson("/api/plots/{$plot->id}", [
            'name' => 'Redaguotas sklypas',
        ])->assertOk()
            ->assertJsonPath('name', 'Redaguotas sklypas');

        $this->assertDatabaseHas('plots', [
            'id' => $plot->id,
            'name' => 'Redaguotas sklypas',
        ]);
    }

    public function test_viewer_cannot_delete_plot(): void
    {
        [$owner, $recipientUser, $plot] = $this->createSharedPlotContext(AccessRole::Viewer);

        Sanctum::actingAs($recipientUser);

        $this->deleteJson("/api/plots/{$plot->id}")
            ->assertForbidden();
    }

    public function test_editor_cannot_delete_plot(): void
    {
        [$owner, $recipientUser, $plot] = $this->createSharedPlotContext(AccessRole::Editor);

        Sanctum::actingAs($recipientUser);

        $this->deleteJson("/api/plots/{$plot->id}")
            ->assertForbidden();
    }

    public function test_owner_can_revoke_access(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$recipientUser, $recipient] = $this->createGardenOwner('recipient@example.com');
        $plot = $this->createPlotForOwner($owner);

        $this->createAccessRight($owner, $plot, $recipient, AccessRole::Viewer);

        Sanctum::actingAs($ownerUser);

        $this->deleteJson("/api/plots/{$plot->id}/share/{$recipientUser->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Prieiga panaikinta');

        $this->assertDatabaseMissing('access_rights', [
            'fk_plot_id' => $plot->id,
            'fk_recipient_owner_id' => $recipient->id_user,
            'fk_recipient_profile_id' => $recipient->fk_profile_id,
        ]);

        $this->assertDatabaseHas('plot_snapshots', [
            'plot_id' => $plot->id,
            'garden_owner_id' => $owner->id,
            'action' => 'plot_access_revoked',
        ]);
    }

    public function test_non_owner_cannot_revoke_access(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$editorUser, $editor] = $this->createGardenOwner('editor@example.com');
        [$viewerUser, $viewer] = $this->createGardenOwner('viewer@example.com');
        $plot = $this->createPlotForOwner($owner);

        $this->createAccessRight($owner, $plot, $editor, AccessRole::Editor);
        $this->createAccessRight($owner, $plot, $viewer, AccessRole::Viewer);

        Sanctum::actingAs($editorUser);

        $this->deleteJson("/api/plots/{$plot->id}/share/{$viewerUser->id}")
            ->assertForbidden();
    }

    public function test_after_revoke_user_loses_access(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$recipientUser, $recipient] = $this->createGardenOwner('recipient@example.com');
        $plot = $this->createPlotForOwner($owner);

        $this->createAccessRight($owner, $plot, $recipient, AccessRole::Viewer);

        Sanctum::actingAs($ownerUser);
        $this->deleteJson("/api/plots/{$plot->id}/share/{$recipientUser->id}")
            ->assertOk();

        Sanctum::actingAs($recipientUser);

        $this->getJson("/api/plots/{$plot->id}")
            ->assertForbidden();
    }

    public function test_unauthorized_user_gets_403_on_plot_access(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$outsiderUser] = $this->createGardenOwner('outsider@example.com');
        $plot = $this->createPlotForOwner($owner);

        Sanctum::actingAs($outsiderUser);

        $this->getJson("/api/plots/{$plot->id}")
            ->assertForbidden();
    }

    public function test_shared_user_can_access_plot_data(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$recipientUser, $recipient] = $this->createGardenOwner('recipient@example.com');
        $plot = $this->createPlotForOwner($owner);
        $zone = $this->createZoneForPlot($plot);
        $plant = $this->createPlantForPlot($plot, $zone);

        $this->createAccessRight($owner, $plot, $recipient, AccessRole::Viewer);

        Sanctum::actingAs($recipientUser);

        $this->getJson("/api/plots/{$plot->id}/plants")
            ->assertOk()
            ->assertJsonPath('0.id', $plant->id)
            ->assertJsonPath('0.name', $plant->name);
    }

    public function test_owner_can_list_plot_access_with_recipient_identifiers(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$recipientUser, $recipient, $recipientProfile] = $this->createGardenOwner('recipient@example.com');
        $plot = $this->createPlotForOwner($owner);
        $accessRight = $this->createAccessRight($owner, $plot, $recipient, AccessRole::Editor);

        Sanctum::actingAs($ownerUser);

        $this->getJson("/api/plots/{$plot->id}/access")
            ->assertOk()
            ->assertJsonPath('0.access_right_id', $accessRight->id)
            ->assertJsonPath('0.user_id', $recipientUser->id)
            ->assertJsonPath('0.email', $recipientUser->email)
            ->assertJsonPath('0.role', AccessRole::Editor->value)
            ->assertJsonPath('0.name', trim("{$recipientProfile->name} {$recipientProfile->surname}"));
    }

    public function test_owner_can_revoke_access_by_access_right_id(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$recipientUser, $recipient] = $this->createGardenOwner('recipient@example.com');
        $plot = $this->createPlotForOwner($owner);
        $accessRight = $this->createAccessRight($owner, $plot, $recipient, AccessRole::Viewer);

        Sanctum::actingAs($ownerUser);

        $this->deleteJson("/api/access/{$accessRight->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Prieiga panaikinta');

        $this->assertDatabaseMissing('access_rights', [
            'id' => $accessRight->id,
        ]);
    }

    private function createGardenOwner(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $profile = Profile::query()->create([
            'name' => 'Vardenis',
            'surname' => 'Pavardenis',
        ]);

        $owner = GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        return [$user, $owner, $profile];
    }

    private function createPlotForOwner(GardenOwner $owner, array $overrides = []): Plot
    {
        $plot = Plot::query()->create(array_merge([
            'name' => 'Bendrinamas sklypas',
            'city' => 'Vilnius',
            'plot_size' => 100,
            'creation_date' => '2026-03-20',
            'description' => 'Bandymu sklypas',
            'share' => true,
        ], $overrides));

        HasPlot::query()->create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return $plot;
    }

    private function createAccessRight(
        GardenOwner $grantor,
        Plot $plot,
        GardenOwner $recipient,
        AccessRole $role
    ): AccessRight {
        return AccessRight::query()->create([
            'granted_at' => now(),
            'role' => $role->value,
            'fk_plot_id' => $plot->id,
            'fk_grantor_owner_id' => $grantor->id_user,
            'fk_grantor_profile_id' => $grantor->fk_profile_id,
            'fk_recipient_owner_id' => $recipient->id_user,
            'fk_recipient_profile_id' => $recipient->fk_profile_id,
        ]);
    }

    private function createSharedPlotContext(AccessRole $role): array
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$recipientUser, $recipient] = $this->createGardenOwner('recipient@example.com');
        $plot = $this->createPlotForOwner($owner);

        $this->createAccessRight($owner, $plot, $recipient, $role);

        return [$owner, $recipientUser, $plot];
    }

    private function createZoneForPlot(Plot $plot): PlantZone
    {
        return PlantZone::query()->create([
            'name' => 'Zona A',
            'zone_size' => 25,
            'soil_type' => SoilType::Clay,
            'rotation_stage' => 0,
            'last_planting_date' => '2026-03-19',
            'fk_plot_id' => $plot->id,
        ]);
    }

    private function createPlantForPlot(Plot $plot, PlantZone $zone): Plant
    {
        return Plant::query()->create([
            'name' => 'Pomidoras',
            'plant_date' => '2026-03-20',
            'type' => PlantType::Vegetable,
            'condition' => ConditionType::Growing,
            'fk_plant_zone_id' => $zone->id,
            'fk_plot_id' => $plot->id,
        ]);
    }
}
