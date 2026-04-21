<?php

namespace Tests\Feature;

use App\Enums\AccessRole;
use App\Models\AccessRight;
use App\Models\CommunityPost;
use App\Models\GardenOwner;
use App\Models\HasPlot;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_global_post(): void
    {
        [$user, $owner] = $this->createGardenOwner('author@example.com');

        Sanctum::actingAs($user);

        $this->postJson('/api/community', [
            'name' => '  Globalus irasas  ',
            'text' => '  Bendras turinys  ',
            'share' => true,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Globalus irasas')
            ->assertJsonPath('data.text', 'Bendras turinys')
            ->assertJsonPath('data.plot_name', null);

        $this->assertDatabaseHas('community_posts', [
            'name' => 'Globalus irasas',
            'text' => 'Bendras turinys',
            'share' => true,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
            'fk_plot_id' => null,
        ]);
    }

    public function test_user_can_create_plot_post_with_access(): void
    {
        [$user, $owner] = $this->createGardenOwner('author@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'name' => 'Bendruomenes sklypas',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/community', [
            'name' => 'Sklypo naujiena',
            'text' => 'Turime nauju darbu.',
            'share' => false,
            'fk_plot_id' => $plot->id,
        ])->assertCreated()
            ->assertJsonPath('data.plot_name', 'Bendruomenes sklypas');

        $this->assertDatabaseHas('community_posts', [
            'name' => 'Sklypo naujiena',
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);
    }

    public function test_user_cannot_create_post_for_plot_without_access(): void
    {
        [$user] = $this->createGardenOwner('outsider@example.com');
        [, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner);

        Sanctum::actingAs($user);

        $this->postJson('/api/community', [
            'name' => 'Nepavykes irasas',
            'text' => 'Bandymas be prieigos',
            'share' => false,
            'fk_plot_id' => $plot->id,
        ])->assertForbidden();
    }

    public function test_public_posts_visible_to_all_users(): void
    {
        [$authorUser, $author] = $this->createGardenOwner('author@example.com');
        [$viewerUser] = $this->createGardenOwner('viewer@example.com');

        $post = $this->createCommunityPost($author, [
            'name' => 'Viesas irasas',
            'share' => true,
            'fk_plot_id' => null,
        ]);

        Sanctum::actingAs($viewerUser);

        $this->getJson('/api/community')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $post->id,
                'name' => 'Viesas irasas',
            ]);

        Sanctum::actingAs($authorUser);

        $this->getJson('/api/community')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $post->id,
                'name' => 'Viesas irasas',
            ]);
    }

    public function test_guest_cannot_access_community_feed(): void
    {
        $this->getJson('/api/community')
            ->assertUnauthorized();
    }

    public function test_private_plot_posts_visible_only_to_authorized_users(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$sharedUser, $sharedOwner] = $this->createGardenOwner('shared@example.com');
        $plot = $this->createPlotForOwner($owner);

        $this->createAccessRight($owner, $plot, $sharedOwner, AccessRole::Viewer);
        $post = $this->createCommunityPost($owner, [
            'name' => 'Privatus sklypo irasas',
            'share' => false,
            'fk_plot_id' => $plot->id,
        ]);

        Sanctum::actingAs($ownerUser);
        $this->getJson('/api/community')
            ->assertOk()
            ->assertJsonFragment(['id' => $post->id]);

        Sanctum::actingAs($sharedUser);
        $this->getJson('/api/community')
            ->assertOk()
            ->assertJsonFragment(['id' => $post->id]);
    }

    public function test_unauthorized_users_cannot_see_private_posts(): void
    {
        [, $owner] = $this->createGardenOwner('owner@example.com');
        [$outsiderUser] = $this->createGardenOwner('outsider@example.com');
        $plot = $this->createPlotForOwner($owner);

        $post = $this->createCommunityPost($owner, [
            'name' => 'Privatus irasas',
            'share' => false,
            'fk_plot_id' => $plot->id,
        ]);

        Sanctum::actingAs($outsiderUser);

        $this->getJson('/api/community')
            ->assertOk()
            ->assertJsonMissing(['id' => $post->id]);
    }

    public function test_user_can_edit_own_post(): void
    {
        [$user, $owner] = $this->createGardenOwner('author@example.com');
        $post = $this->createCommunityPost($owner, [
            'name' => 'Senas pavadinimas',
            'text' => 'Senas tekstas',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/community/{$post->id}", [
            'name' => 'Naujas pavadinimas',
            'text' => 'Atnaujintas tekstas',
            'share' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Naujas pavadinimas')
            ->assertJsonPath('data.text', 'Atnaujintas tekstas')
            ->assertJsonPath('data.share', false);

        $this->assertDatabaseHas('community_posts', [
            'id' => $post->id,
            'name' => 'Naujas pavadinimas',
            'text' => 'Atnaujintas tekstas',
            'share' => false,
        ]);
    }

    public function test_user_cannot_edit_another_users_post(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$otherUser] = $this->createGardenOwner('other@example.com');
        $post = $this->createCommunityPost($owner);

        Sanctum::actingAs($otherUser);

        $this->patchJson("/api/community/{$post->id}", [
            'name' => 'Bandymas',
        ])->assertForbidden();

        Sanctum::actingAs($ownerUser);

        $this->getJson('/api/community')
            ->assertOk()
            ->assertJsonFragment(['id' => $post->id]);
    }

    public function test_user_can_delete_own_post(): void
    {
        [$user, $owner] = $this->createGardenOwner('author@example.com');
        $post = $this->createCommunityPost($owner);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/community/{$post->id}")
            ->assertOk()
            ->assertJsonPath('message', "\u{012E}ra\u{0161}as s\u{0117}kmingai pa\u{0161}alintas");

        $this->assertDatabaseMissing('community_posts', [
            'id' => $post->id,
        ]);
    }

    public function test_user_cannot_delete_another_users_post(): void
    {
        [, $owner] = $this->createGardenOwner('owner@example.com');
        [$otherUser] = $this->createGardenOwner('other@example.com');
        $post = $this->createCommunityPost($owner);

        Sanctum::actingAs($otherUser);

        $this->deleteJson("/api/community/{$post->id}")
            ->assertForbidden();
    }

    public function test_feed_includes_correct_combination_of_posts(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [, $sharedPlotOwner] = $this->createGardenOwner('shared-plot-owner@example.com');
        [, $otherOwner] = $this->createGardenOwner('other@example.com');

        $ownedPlot = $this->createPlotForOwner($owner);
        $sharedPlot = $this->createPlotForOwner($sharedPlotOwner, [
            'name' => 'Dalinamas sklypas',
        ]);
        $privateForeignPlot = $this->createPlotForOwner($otherOwner, [
            'name' => 'Svetimas sklypas',
        ]);

        $this->createAccessRight($sharedPlotOwner, $sharedPlot, $owner, AccessRole::Viewer);

        $publicPost = $this->createCommunityPost($otherOwner, [
            'name' => 'Viesas visiems',
            'share' => true,
            'fk_plot_id' => null,
        ]);
        $ownPrivateGlobal = $this->createCommunityPost($owner, [
            'name' => 'Mano privatus',
            'share' => false,
            'fk_plot_id' => null,
        ]);
        $accessiblePrivatePlotPost = $this->createCommunityPost($sharedPlotOwner, [
            'name' => 'Matomas per prieiga',
            'share' => false,
            'fk_plot_id' => $sharedPlot->id,
        ]);
        $ownPlotPrivatePost = $this->createCommunityPost($owner, [
            'name' => 'Mano sklypo irasas',
            'share' => false,
            'fk_plot_id' => $ownedPlot->id,
        ]);
        $hiddenPrivatePost = $this->createCommunityPost($otherOwner, [
            'name' => 'Nematomas privatus',
            'share' => false,
            'fk_plot_id' => $privateForeignPlot->id,
        ]);

        Sanctum::actingAs($ownerUser);

        $response = $this->getJson('/api/community');

        $response->assertOk()
            ->assertJsonFragment(['id' => $publicPost->id, 'name' => 'Viesas visiems'])
            ->assertJsonFragment(['id' => $ownPrivateGlobal->id, 'name' => 'Mano privatus'])
            ->assertJsonFragment(['id' => $accessiblePrivatePlotPost->id, 'name' => 'Matomas per prieiga'])
            ->assertJsonFragment(['id' => $ownPlotPrivatePost->id, 'name' => 'Mano sklypo irasas'])
            ->assertJsonMissing(['id' => $hiddenPrivatePost->id, 'name' => 'Nematomas privatus']);
    }

    public function test_plot_specific_feed_respects_access_rules(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        [$viewerUser, $viewer] = $this->createGardenOwner('viewer@example.com');
        [$outsiderUser] = $this->createGardenOwner('outsider@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'name' => 'Sklypo bendruomene',
        ]);

        $this->createAccessRight($owner, $plot, $viewer, AccessRole::Viewer);

        $publicPlotPost = $this->createCommunityPost($owner, [
            'name' => 'Viesas sklype',
            'share' => true,
            'fk_plot_id' => $plot->id,
        ]);
        $privatePlotPost = $this->createCommunityPost($owner, [
            'name' => 'Privatus sklype',
            'share' => false,
            'fk_plot_id' => $plot->id,
        ]);

        Sanctum::actingAs($ownerUser);
        $this->getJson("/api/plots/{$plot->id}/community")
            ->assertOk()
            ->assertJsonFragment(['id' => $publicPlotPost->id])
            ->assertJsonFragment(['id' => $privatePlotPost->id]);

        Sanctum::actingAs($viewerUser);
        $this->getJson("/api/plots/{$plot->id}/community")
            ->assertOk()
            ->assertJsonFragment(['id' => $publicPlotPost->id])
            ->assertJsonFragment(['id' => $privatePlotPost->id]);

        Sanctum::actingAs($outsiderUser);
        $this->getJson("/api/plots/{$plot->id}/community")
            ->assertForbidden();
    }

    public function test_plot_linked_posts_expose_plan_preview_data(): void
    {
        [$ownerUser, $owner] = $this->createGardenOwner('owner@example.com');
        $plot = $this->createPlotForOwner($owner, [
            'name' => 'Perziuros sklypas',
            'geometry' => [
                'points' => [
                    ['x' => 0.10, 'y' => 0.10],
                    ['x' => 0.88, 'y' => 0.12],
                    ['x' => 0.84, 'y' => 0.82],
                    ['x' => 0.12, 'y' => 0.78],
                ],
            ],
        ]);
        $this->createZoneForPlot($plot, [
            'name' => 'Zona A',
            'geometry' => [
                'points' => [
                    ['x' => 0.18, 'y' => 0.18],
                    ['x' => 0.46, 'y' => 0.20],
                    ['x' => 0.43, 'y' => 0.46],
                    ['x' => 0.20, 'y' => 0.44],
                ],
            ],
        ]);
        $post = $this->createCommunityPost($owner, [
            'name' => 'Sklypo planas',
            'share' => true,
            'fk_plot_id' => $plot->id,
        ]);

        Sanctum::actingAs($ownerUser);

        $response = $this->getJson('/api/community');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $post->id)
            ->assertJsonPath('data.0.plot_preview.plot_id', $plot->id)
            ->assertJsonPath('data.0.plot_preview.geometry.points.0.x', 0.10)
            ->assertJsonPath('data.0.plot_preview.zones.0.name', 'Zona A')
            ->assertJsonPath('data.0.plot_preview.zones.0.geometry.points.2.y', 0.46);
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
            'name' => 'Sklypas',
            'city' => 'Vilnius',
            'plot_size' => 120,
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

    private function createZoneForPlot(Plot $plot, array $overrides = []): PlantZone
    {
        return PlantZone::query()->create(array_merge([
            'name' => 'Zona A',
            'zone_size' => 25,
            'soil_type' => 'clay',
            'rotation_stage' => 0,
            'last_planting_date' => '2026-03-19',
            'fk_plot_id' => $plot->id,
            'geometry' => null,
        ], $overrides));
    }

    private function createCommunityPost(GardenOwner $owner, array $overrides = []): CommunityPost
    {
        return CommunityPost::query()->create(array_merge([
            'name' => 'Bendruomenes irasas',
            'text' => 'Bendruomenes turinys',
            'share' => true,
            'created_at' => now(),
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
            'fk_plot_id' => null,
        ], $overrides));
    }
}
