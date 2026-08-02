<?php

namespace Tests\Feature\Yava;

use App\Models\AccessRight;
use App\Models\CatalogPlant;
use App\Models\CommunityPost;
use App\Models\GardenOwner;
use App\Models\HasInventory;
use App\Models\InventoryItem;
use App\Models\LegacyMigrationRun;
use App\Models\LegacyRecordMapping;
use App\Models\Plant;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\User;
use App\Services\Yava\LegacyMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_runs_are_deterministic_comprehensive_and_do_not_mutate_any_table(): void
    {
        [$plot, $zone] = $this->legacyPlot();
        $this->plant($plot, $zone, ['quantity' => 20, 'occupied_area' => 50]);
        $this->plant($plot, $zone, ['quantity' => 20, 'occupied_area' => 50]);
        $this->plant($plot, $zone, ['name' => 'Basil', 'quantity' => 1, 'occupied_area' => 0]);

        $service = app(LegacyMigrationService::class);
        $before = $this->tableCounts();
        $smallChunks = $service->run(false, 10);
        $afterFirst = $this->tableCounts();
        $largeChunks = $service->run(false, 1000);

        $this->assertFalse($smallChunks->exists);
        $this->assertSame('completed', $smallChunks->status);
        $this->assertSame($before, $afterFirst);
        $this->assertSame($before, $this->tableCounts());
        $this->assertSame($smallChunks->counts, $largeChunks->counts);
        $this->assertSame($smallChunks->options['report'], $largeChunks->options['report']);
        $this->assertSame(0, LegacyMigrationRun::count());
        $this->assertSame(0, LegacyRecordMapping::count());
        $this->assertDatabaseCount('farms', 0);
        $this->assertDatabaseCount('crop_seasons', 0);

        $report = $smallChunks->options['report'];
        $this->assertSame(3, $report['source_counts']['plants']);
        $this->assertSame(1, $report['source_counts']['plant_zones']);
        $this->assertSame(1, $report['estimated_effects']['plots_to_fields']['would_create']);
        $this->assertSame(1, $report['estimated_effects']['plant_zones_to_field_zones']['would_create']);
        $this->assertSame(1, $report['estimated_effects']['plants_to_crop_seasons']['would_create']);
        $this->assertSame(1, $report['estimated_effects']['plants_to_crop_seasons']['duplicate_candidates']);
        $this->assertSame(1, $report['estimated_effects']['plants_to_crop_seasons']['ambiguous']);
        $this->assertArrayHasKey('access_rights_to_permissions', $report['estimated_effects']);
        $this->assertArrayHasKey('legacy_inventory_ownership', $report['estimated_effects']);
        $this->assertArrayHasKey('legacy_community_posts', $report['estimated_effects']);
    }

    public function test_execution_is_resumable_and_reruns_preserve_completed_and_ambiguous_mappings(): void
    {
        [$plot, $zone] = $this->legacyPlot();
        $highOne = $this->plant($plot, $zone, ['quantity' => 20, 'occupied_area' => 50]);
        $highTwo = $this->plant($plot, $zone, ['quantity' => 10, 'occupied_area' => 25]);
        $ambiguous = $this->plant($plot, $zone, ['name' => 'Basil', 'quantity' => 1, 'occupied_area' => 0]);

        $service = app(LegacyMigrationService::class);
        $paused = $service->run(true, 10, null, 1);
        $this->assertSame('paused', $paused->status);
        $this->assertSame($highOne->id, (int) $paused->last_legacy_id);
        $this->assertDatabaseCount('farms', 1);
        $this->assertDatabaseCount('fields', 1);
        $this->assertDatabaseCount('field_zones', 1);
        $this->assertDatabaseCount('crop_seasons', 1);

        $completed = $service->run(true, 1000, $paused->id);
        $this->assertSame('completed', $completed->status);
        $this->assertSame($ambiguous->id, (int) $completed->last_legacy_id);
        $this->assertDatabaseCount('crop_seasons', 1);
        $this->assertDatabaseHas('legacy_record_mappings', [
            'legacy_type' => 'plant', 'legacy_id' => $ambiguous->id,
            'classification' => 'ambiguous_legacy_plant', 'status' => 'preserved_legacy',
            'target_id' => null,
        ]);

        $completedMapping = LegacyRecordMapping::query()->where('legacy_type', 'plant')->where('legacy_id', $highOne->id)->firstOrFail();
        $ambiguousMapping = LegacyRecordMapping::query()->where('legacy_type', 'plant')->where('legacy_id', $ambiguous->id)->firstOrFail();
        $auditCount = DB::table('legacy_migration_audits')->count();
        $mappingCount = LegacyRecordMapping::count();

        $rerun = $service->run(true, 17);
        $this->assertSame('completed', $rerun->status);
        $this->assertDatabaseCount('farms', 1);
        $this->assertDatabaseCount('fields', 1);
        $this->assertDatabaseCount('field_zones', 1);
        $this->assertDatabaseCount('crops', 1);
        $this->assertDatabaseCount('crop_seasons', 1);
        $this->assertSame($mappingCount, LegacyRecordMapping::count());
        $this->assertSame($auditCount, DB::table('legacy_migration_audits')->count());
        $this->assertSame($completedMapping->migration_run_id, $completedMapping->fresh()->migration_run_id);
        $this->assertSame($completedMapping->classification, $completedMapping->fresh()->classification);
        $this->assertSame($completedMapping->target_id, $completedMapping->fresh()->target_id);
        $this->assertSame($ambiguousMapping->migration_run_id, $ambiguousMapping->fresh()->migration_run_id);
        $this->assertSame('preserved_legacy', $ambiguousMapping->fresh()->status);
        $this->assertSame(0, $service->counts()['unmapped']['plants']);

        $beforeDryAfterExecution = $this->tableCounts();
        $dryAfterExecution = $service->run(false, 10);
        $this->assertSame($beforeDryAfterExecution, $this->tableCounts());
        $this->assertSame(3, $dryAfterExecution->counts['already_migrated']);
        $this->assertSame(3, $dryAfterExecution->counts['mappings_reused']);
        $this->assertSame(3, $dryAfterExecution->options['report']['estimated_effects']['plants_to_crop_seasons']['mappings_reused']);

        $this->assertSame($highTwo->id, (int) LegacyRecordMapping::query()->where('legacy_type', 'plant')->where('legacy_id', $highTwo->id)->value('legacy_id'));
    }

    public function test_execution_after_a_dry_run_creates_targets_once(): void
    {
        [$plot, $zone] = $this->legacyPlot();
        $plant = $this->plant($plot, $zone, ['quantity' => 20, 'occupied_area' => 50]);
        $service = app(LegacyMigrationService::class);

        $dryRun = $service->run(false, 10);
        $this->assertSame('high_confidence_crop_season', $service->classify($plant)['classification']);
        $this->assertSame('completed', $dryRun->status);
        $this->assertDatabaseCount('legacy_migration_runs', 0);
        $this->assertDatabaseCount('legacy_record_mappings', 0);

        $execute = $service->run(true, 10);
        $this->assertSame('completed', $execute->status);
        $this->assertDatabaseCount('farms', 1);
        $this->assertDatabaseCount('crop_seasons', 1);
        $this->assertDatabaseHas('legacy_record_mappings', [
            'legacy_type' => 'plant', 'legacy_id' => $plant->id,
            'classification' => 'high_confidence_crop_season', 'status' => 'migrated',
        ]);
    }

    public function test_execution_migrates_or_preserves_every_reported_compatibility_entity(): void
    {
        [$plot, $zone] = $this->legacyPlot();
        $owner = $plot->gardenOwner;
        $recipient = User::factory()->create();
        $recipientProfile = Profile::query()->create(['user_id' => $recipient->id, 'name' => 'Shared', 'surname' => 'Farmer']);
        GardenOwner::query()->create(['id' => $recipient->id, 'user_id' => $recipient->id, 'id_user' => $recipient->id, 'fk_profile_id' => $recipientProfile->id]);
        $catalogPlant = CatalogPlant::query()->create(['name' => 'Tomato', 'canonical_name' => 'tomato', 'plant_type' => 'vegetable']);
        AccessRight::query()->create([
            'granted_at' => now(), 'role' => 'editor', 'fk_plot_id' => $plot->id,
            'fk_grantor_owner_id' => $owner->id_user, 'fk_grantor_profile_id' => $owner->fk_profile_id,
            'fk_recipient_owner_id' => $recipient->id, 'fk_recipient_profile_id' => $recipientProfile->id,
        ]);
        $item = InventoryItem::query()->create(['garden_owner_id' => $owner->id, 'name' => 'Seed bags', 'quantity' => 5, 'type' => 'material']);
        HasInventory::query()->create(['fk_inventory_item_id' => $item->id, 'fk_owner_id' => $owner->id_user, 'fk_profile_id' => $owner->fk_profile_id]);
        $post = CommunityPost::query()->create([
            'garden_owner_id' => $owner->id, 'plot_id' => $plot->id, 'name' => 'Legacy note', 'text' => 'History',
            'share' => true, 'created_at' => now(), 'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id, 'fk_plot_id' => $plot->id,
        ]);

        $run = app(LegacyMigrationService::class)->run(true, 10);
        $this->assertSame('completed', $run->status);
        $this->assertDatabaseHas('crops', ['legacy_source' => 'catalog_plants', 'legacy_id' => $catalogPlant->id, 'is_global' => true]);
        $this->assertDatabaseHas('farm_memberships', ['user_id' => $recipient->id, 'role' => 'manager', 'status' => 'active']);
        $this->assertDatabaseHas('farm_member_permissions', ['permission' => 'manage_fields', 'allowed' => true]);
        $this->assertDatabaseHas('stock_items', ['name' => 'Seed bags', 'quantity' => 5]);
        $this->assertDatabaseHas('community_posts', ['id' => $post->id, 'is_legacy' => true]);
        foreach (['garden_owner', 'catalog_plant', 'access_right', 'inventory_item', 'community_post'] as $type) {
            $this->assertDatabaseHas('legacy_record_mappings', ['legacy_type' => $type]);
        }
        $this->assertDatabaseHas('legacy_record_mappings', [
            'legacy_type' => 'community_post', 'legacy_id' => $post->id,
            'classification' => 'legacy_community_history', 'status' => 'preserved_legacy',
        ]);

        $mappingCount = LegacyRecordMapping::count();
        app(LegacyMigrationService::class)->run(true, 10);
        $this->assertSame($mappingCount, LegacyRecordMapping::count());
        $this->assertDatabaseCount('stock_items', 1);
        $this->assertDatabaseCount('community_posts', 1);
    }

    /** @return array{0: Plot, 1: PlantZone} */
    private function legacyPlot(): array
    {
        $user = User::factory()->create();
        $profile = Profile::query()->create(['user_id' => $user->id, 'name' => 'Legacy', 'surname' => 'Owner']);
        $owner = GardenOwner::query()->create(['id' => $user->id, 'user_id' => $user->id, 'id_user' => $user->id, 'fk_profile_id' => $profile->id]);
        $plot = Plot::query()->create(['garden_owner_id' => $owner->id, 'name' => 'Legacy Plot', 'city' => 'Mysuru', 'plot_size' => 2000, 'creation_date' => '2025-01-01']);
        $zone = PlantZone::query()->create(['name' => 'Legacy Zone', 'zone_size' => 500, 'soil_type' => 'sandy', 'fk_plot_id' => $plot->id, 'plot_id' => $plot->id]);

        return [$plot, $zone];
    }

    private function plant(Plot $plot, PlantZone $zone, array $overrides = []): Plant
    {
        return Plant::query()->create($overrides + [
            'name' => 'Tomato', 'plant_date' => '2026-05-01', 'type' => 'vegetable', 'condition' => 'flowering',
            'fk_plant_zone_id' => $zone->id, 'plant_zone_id' => $zone->id, 'fk_plot_id' => $plot->id,
            'quantity' => 1, 'occupied_area' => 0,
        ]);
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        $tables = Schema::getTableListing();
        sort($tables);
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
