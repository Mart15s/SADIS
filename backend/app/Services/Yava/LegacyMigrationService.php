<?php

namespace App\Services\Yava;

use App\Models\AccessRight;
use App\Models\CatalogPlant;
use App\Models\CommunityPost;
use App\Models\Crop;
use App\Models\CropSeason;
use App\Models\Farm;
use App\Models\FarmMemberPermission;
use App\Models\FarmMembership;
use App\Models\Field;
use App\Models\FieldZone;
use App\Models\GardenOwner;
use App\Models\InventoryItem;
use App\Models\LegacyMigrationRun;
use App\Models\LegacyRecordMapping;
use App\Models\Plant;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\StockItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class LegacyMigrationService
{
    private const TERMINAL_MAPPING_STATUSES = ['migrated', 'preserved_legacy'];

    public function run(bool $execute = false, int $chunkSize = 250, ?string $runId = null, ?int $limit = null): LegacyMigrationRun
    {
        $chunkSize = max(10, min($chunkSize, 2000));
        if (! $execute) {
            abort_if($runId !== null, 422, 'Dry runs are stateless and cannot be resumed.');

            return $this->dryRun($chunkSize, $limit);
        }

        $run = $runId
            ? LegacyMigrationRun::query()->findOrFail($runId)
            : LegacyMigrationRun::query()->create([
                'type' => 'yava_stage_one', 'status' => 'pending', 'dry_run' => false,
                'chunk_size' => $chunkSize, 'last_legacy_id' => 0, 'counts' => $this->emptyCounts(),
            ]);
        abort_if($run->dry_run, 422, 'A migration run cannot switch between dry-run and execute mode.');
        $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'completed_at' => null, 'error' => null]);
        $counts = array_merge($this->emptyCounts(), $run->counts ?? []);
        $processed = 0;

        try {
            $this->migratePlots($run, $counts);
            $this->migrateCompatibilitySources($run, $counts);

            Plant::query()->where('id', '>', (int) $run->last_legacy_id)->orderBy('id')
                ->chunkById($run->chunk_size, function (Collection $plants) use ($run, $limit, &$counts, &$processed): bool {
                    foreach ($plants as $plant) {
                        if ($limit !== null && $processed >= $limit) {
                            return false;
                        }
                        $processed++;
                        $this->migratePlant((int) $plant->id, $run, $counts);
                        $run->update(['last_legacy_id' => $plant->id, 'counts' => $counts]);
                    }

                    return $limit === null || $processed < $limit;
                });

            $remaining = Plant::query()->where('id', '>', (int) $run->last_legacy_id)->exists();
            $run->update([
                'status' => $remaining ? 'paused' : 'completed', 'counts' => $counts,
                'completed_at' => $remaining ? null : now(),
            ]);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'counts' => $counts, 'error' => $exception->getMessage()]);
            throw $exception;
        }

        return $run->fresh();
    }

    public function classify(Plant $plant): array
    {
        $evidence = [
            'plot_id' => $plant->fk_plot_id, 'zone_id' => $plant->plant_zone_id ?? $plant->fk_plant_zone_id,
            'name' => $plant->name, 'plant_date' => optional($plant->plant_date)->toDateString(),
            'quantity' => $plant->quantity, 'occupied_area' => $plant->occupied_area,
            'has_marker' => $plant->marker_position_x !== null || $plant->marker_position_y !== null,
        ];
        if (! $plant->fk_plot_id || ! $plant->plot || blank($plant->name) || ! $plant->plant_date) {
            return ['classification' => 'invalid_or_orphaned', 'confidence' => 0.05, 'evidence' => $evidence];
        }
        if ($this->terminalMapping('plant', (int) $plant->id)) {
            return ['classification' => 'already_migrated', 'confidence' => 1, 'evidence' => $evidence];
        }
        if ($plant->harvest_date && $plant->harvest_date->isPast()) {
            return ['classification' => 'historical_crop_record', 'confidence' => 0.8, 'evidence' => $evidence];
        }

        $groupCount = Plant::query()
            ->where('fk_plot_id', $plant->fk_plot_id)
            ->where('name', $plant->name)
            ->whereDate('plant_date', $plant->plant_date)
            ->count();
        if ((float) ($plant->occupied_area ?? 0) > 0 || (float) ($plant->quantity ?? 0) > 1 || $groupCount > 1) {
            $evidence['group_count'] = $groupCount;

            return ['classification' => 'high_confidence_crop_season', 'confidence' => 0.92, 'evidence' => $evidence];
        }

        return ['classification' => 'ambiguous_legacy_plant', 'confidence' => 0.35, 'evidence' => $evidence];
    }

    /** @return array<string, mixed> */
    public function dryRunReport(): array
    {
        $plotMappings = $this->terminalMappings('plot');
        $zoneMappings = $this->terminalMappings('plant_zone');
        $plantMappings = $this->terminalMappings('plant');
        $plots = Plot::query()->with('gardenOwner')->orderBy('id')->get();
        $zones = PlantZone::query()->with('plot')->orderBy('id')->get();
        $plants = Plant::query()->with('plot')->orderBy('id')->get();

        $eligibleGroups = [];
        $plantEffects = ['would_create' => 0, 'would_preserve' => 0, 'mappings_reused' => 0, 'would_skip' => 0, 'ambiguous' => 0, 'historical' => 0, 'invalid' => 0, 'orphaned' => 0, 'duplicate_candidates' => 0];
        foreach ($plants as $plant) {
            if ($plantMappings->has((int) $plant->id)) {
                $plantEffects['mappings_reused']++;

                continue;
            }
            $classification = $this->classify($plant)['classification'];
            if ($classification === 'high_confidence_crop_season') {
                $eligibleGroups[$this->plantGroupKey($plant)] = true;
            } elseif ($classification === 'ambiguous_legacy_plant') {
                $plantEffects['ambiguous']++;
                $plantEffects['would_preserve']++;
                $plantEffects['would_skip']++;
            } elseif ($classification === 'historical_crop_record') {
                $plantEffects['historical']++;
                $plantEffects['would_preserve']++;
                $plantEffects['would_skip']++;
            } else {
                $plantEffects['invalid']++;
                $plantEffects['orphaned'] += $plant->plot ? 0 : 1;
                $plantEffects['would_skip']++;
            }
        }
        $eligibleRecords = $plants->filter(function (Plant $plant) use ($plantMappings): bool {
            return ! $plantMappings->has((int) $plant->id)
                && $this->classify($plant)['classification'] === 'high_confidence_crop_season';
        })->count();
        $plantEffects['would_create'] = count($eligibleGroups);
        $plantEffects['duplicate_candidates'] = max(0, $eligibleRecords - count($eligibleGroups));

        $unmappedPlots = $plots->reject(fn (Plot $plot) => $plotMappings->has((int) $plot->id));
        $orphanZones = $zones->filter(fn (PlantZone $zone) => ! $zone->plot);
        $unmappedZones = $zones->reject(fn (PlantZone $zone) => $zoneMappings->has((int) $zone->id));
        $catalogNames = CatalogPlant::query()->orderBy('id')->pluck('name')->map(fn ($name) => Str::lower(trim((string) $name)));
        $accessRights = AccessRight::query()->with(['plot', 'recipientUser'])->orderBy('id')->get();
        $invalidAccess = $accessRights->filter(fn (AccessRight $right) => ! $right->plot || ! $right->recipientUser)->count();
        $inventoryItems = InventoryItem::query()->with('inventoryLinks')->orderBy('id')->get();
        $orphanInventory = $inventoryItems->filter(fn (InventoryItem $item) => ! $item->garden_owner_id && $item->inventoryLinks->isEmpty())->count();
        $communityPosts = DB::table('community_posts')->orderBy('id')->get();
        $orphanPosts = $communityPosts->filter(fn ($post) => empty($post->plot_id) || ! $plots->contains('id', $post->plot_id))->count();
        $catalogMappings = $this->terminalMappings('catalog_plant');
        $accessMappings = $this->terminalMappings('access_right');
        $inventoryMappings = $this->terminalMappings('inventory_item');
        $postMappings = $this->terminalMappings('community_post');

        $entities = [
            'garden_owners_to_farms' => [
                'source' => GardenOwner::count(),
                'would_create' => $unmappedPlots->pluck('garden_owner_id')->filter()->unique()->count(),
                'mappings_reused' => $plots->filter(fn (Plot $plot) => $plotMappings->has((int) $plot->id))->pluck('garden_owner_id')->filter()->unique()->count(),
                'would_preserve' => GardenOwner::query()->whereDoesntHave('ownedPlots')->count(), 'would_skip' => GardenOwner::query()->whereDoesntHave('ownedPlots')->count(), 'ambiguous' => 0, 'historical' => 0, 'invalid' => 0, 'orphaned' => 0, 'duplicate_candidates' => 0,
            ],
            'owners_to_farm_memberships' => [
                'source' => $plots->pluck('garden_owner_id')->filter()->unique()->count(), 'would_create' => $unmappedPlots->filter(fn (Plot $plot) => (bool) $plot->gardenOwner?->user_id)->count(),
                'mappings_reused' => $plotMappings->count(), 'would_skip' => $unmappedPlots->filter(fn (Plot $plot) => ! $plot->gardenOwner?->user_id)->count(),
                'would_preserve' => 0, 'ambiguous' => 0, 'historical' => 0, 'invalid' => 0, 'orphaned' => $unmappedPlots->filter(fn (Plot $plot) => ! $plot->gardenOwner)->count(), 'duplicate_candidates' => 0,
            ],
            'plots_to_fields' => [
                'source' => $plots->count(), 'would_create' => $unmappedPlots->count(), 'mappings_reused' => $plotMappings->count(),
                'would_preserve' => 0, 'would_skip' => 0, 'ambiguous' => 0, 'historical' => 0, 'invalid' => 0, 'orphaned' => 0, 'duplicate_candidates' => 0,
            ],
            'plant_zones_to_field_zones' => [
                'source' => $zones->count(), 'would_create' => $unmappedZones->count() - $orphanZones->count(), 'mappings_reused' => $zoneMappings->count(),
                'would_preserve' => $orphanZones->count(), 'would_skip' => $orphanZones->count(), 'ambiguous' => 0, 'historical' => 0, 'invalid' => 0, 'orphaned' => $orphanZones->count(),
                'duplicate_candidates' => $zones->groupBy(fn (PlantZone $zone) => ($zone->plot_id ?? $zone->fk_plot_id).'|'.Str::lower(trim($zone->name)))->filter(fn (Collection $group) => $group->count() > 1)->count(),
            ],
            'catalogue_to_crops' => [
                'source' => $catalogNames->count(), 'would_create' => $catalogNames->count() - $catalogMappings->count(), 'mappings_reused' => $catalogMappings->count(),
                'would_preserve' => 0, 'would_skip' => 0, 'ambiguous' => 0, 'historical' => 0, 'invalid' => $catalogNames->filter(fn ($name) => blank($name))->count(), 'orphaned' => 0,
                'duplicate_candidates' => $catalogNames->count() - $catalogNames->unique()->count(),
            ],
            'plants_to_crop_seasons' => ['source' => $plants->count()] + $plantEffects,
            'access_rights_to_permissions' => [
                'source' => $accessRights->count(), 'would_create' => max(0, $accessRights->count() - $invalidAccess - $accessMappings->count()),
                'mappings_reused' => $accessMappings->count(), 'would_preserve' => $invalidAccess, 'would_skip' => $invalidAccess,
                'ambiguous' => 0, 'historical' => 0, 'invalid' => $invalidAccess, 'orphaned' => $invalidAccess, 'duplicate_candidates' => 0,
            ],
            'legacy_inventory_ownership' => [
                'source' => $inventoryItems->count(), 'would_create' => max(0, $inventoryItems->count() - $orphanInventory - $inventoryMappings->count()),
                'mappings_reused' => $inventoryMappings->count(), 'would_preserve' => $orphanInventory, 'would_skip' => $orphanInventory,
                'ambiguous' => 0, 'historical' => 0, 'invalid' => 0, 'orphaned' => $orphanInventory,
                'duplicate_candidates' => $inventoryItems->groupBy(fn (InventoryItem $item) => ($item->garden_owner_id ?? 'orphan').'|'.Str::lower(trim($item->name)))->filter(fn (Collection $group) => $group->count() > 1)->count(),
            ],
            'legacy_community_posts' => [
                'source' => $communityPosts->count(), 'would_create' => 0, 'would_preserve' => max(0, $communityPosts->count() - $postMappings->count()),
                'mappings_reused' => $postMappings->count(), 'would_skip' => $orphanPosts,
                'ambiguous' => 0, 'historical' => $communityPosts->count(), 'invalid' => 0, 'orphaned' => $orphanPosts, 'duplicate_candidates' => 0,
            ],
        ];

        $totals = ['source' => 0, 'would_create' => 0, 'would_preserve' => 0, 'mappings_reused' => 0, 'would_skip' => 0, 'ambiguous' => 0, 'historical' => 0, 'invalid' => 0, 'orphaned' => 0, 'duplicate_candidates' => 0];
        foreach ($entities as $effect) {
            foreach ($totals as $key => $_) {
                $totals[$key] += (int) ($effect[$key] ?? 0);
            }
        }

        $warnings = [];
        if ($totals['orphaned'] > 0) {
            $warnings[] = 'Orphaned source records require manual review and will be preserved.';
        }
        if ($totals['duplicate_candidates'] > 0) {
            $warnings[] = 'Duplicate candidates will reuse a deterministic target where supported.';
        }
        if ($plantEffects['ambiguous'] > 0) {
            $warnings[] = 'Ambiguous and historical Plant records will remain available as legacy history.';
        }

        return [
            'source_counts' => $this->sourceCounts(),
            'target_counts' => ['farms' => Farm::count(), 'fields' => Field::count(), 'field_zones' => FieldZone::count(), 'crops' => Crop::count(), 'crop_seasons' => CropSeason::count(), 'stock_items' => StockItem::count()],
            'mapping_counts' => LegacyRecordMapping::query()->selectRaw('legacy_type, status, COUNT(*) as records')->groupBy('legacy_type', 'status')->orderBy('legacy_type')->orderBy('status')->get()->toArray(),
            'estimated_effects' => $entities, 'totals' => $totals, 'warnings' => $warnings,
        ];
    }

    public function counts(): array
    {
        return [
            'legacy' => $this->sourceCounts(),
            'stage_one' => ['farms' => Farm::count(), 'fields' => Field::count(), 'field_zones' => FieldZone::count(), 'crops' => Crop::count(), 'crop_seasons' => CropSeason::count()],
            'unmapped' => [
                'plots' => Plot::query()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('legacy_record_mappings')->whereColumn('legacy_record_mappings.legacy_id', 'plots.id')->where('legacy_type', 'plot')->whereIn('status', self::TERMINAL_MAPPING_STATUSES))->count(),
                'plant_zones' => PlantZone::query()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('legacy_record_mappings')->whereColumn('legacy_record_mappings.legacy_id', 'plant_zones.id')->where('legacy_type', 'plant_zone')->whereIn('status', self::TERMINAL_MAPPING_STATUSES))->count(),
                'plants' => Plant::query()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('legacy_record_mappings')->whereColumn('legacy_record_mappings.legacy_id', 'plants.id')->where('legacy_type', 'plant')->whereIn('status', self::TERMINAL_MAPPING_STATUSES))->count(),
            ],
            'orphans' => [
                'plants_without_plot' => Plant::query()->whereDoesntHave('plot')->count(),
                'crop_seasons_without_field' => CropSeason::query()->whereDoesntHave('field')->count(),
            ],
        ];
    }

    private function dryRun(int $chunkSize, ?int $limit): LegacyMigrationRun
    {
        $run = new LegacyMigrationRun([
            'id' => (string) Str::uuid(), 'type' => 'yava_stage_one', 'status' => 'running',
            'dry_run' => true, 'chunk_size' => $chunkSize, 'last_legacy_id' => 0,
            'counts' => $this->emptyCounts(), 'started_at' => now(),
        ]);
        $counts = $this->emptyCounts();
        $processed = 0;

        Plant::query()->orderBy('id')->chunkById($chunkSize, function (Collection $plants) use ($run, $limit, &$counts, &$processed): bool {
            foreach ($plants as $plant) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }
                $processed++;
                $classification = $this->classify($plant);
                $counts[$classification['classification']]++;
                if ($classification['classification'] === 'already_migrated') {
                    $counts['mappings_reused']++;
                }
                $run->last_legacy_id = $plant->id;
            }

            return $limit === null || $processed < $limit;
        });

        $remaining = Plant::query()->where('id', '>', (int) $run->last_legacy_id)->exists();
        $run->status = $remaining ? 'paused' : 'completed';
        $run->counts = $counts;
        $run->options = ['report' => $this->dryRunReport()];
        $run->completed_at = $remaining ? null : now();

        return $run;
    }

    private function migratePlots(LegacyMigrationRun $run, array &$counts): void
    {
        Plot::query()->orderBy('id')->chunkById($run->chunk_size, function (Collection $plots) use ($run, &$counts): void {
            foreach ($plots as $plot) {
                DB::transaction(function () use ($plot, $run, &$counts): void {
                    $lockedPlot = Plot::query()->with('gardenOwner')->lockForUpdate()->findOrFail($plot->id);
                    $mapping = LegacyRecordMapping::query()->where('legacy_type', 'plot')->where('legacy_id', $lockedPlot->id)->lockForUpdate()->first();
                    $terminalMapping = $mapping && in_array($mapping->status, self::TERMINAL_MAPPING_STATUSES, true);
                    $field = $terminalMapping ? Field::find($mapping->target_id) : null;
                    if ($terminalMapping && ! $field) {
                        throw new \RuntimeException("Completed plot mapping {$mapping->id} points to a missing Field and requires manual repair.");
                    }

                    if (! $field) {
                        $farm = Farm::query()->create([
                            'name' => $lockedPlot->name, 'slug' => $this->uniqueFarmSlug($lockedPlot),
                            'description' => $lockedPlot->description, 'area_square_metres' => max(0, (float) $lockedPlot->plot_size),
                            'locality' => $lockedPlot->city, 'created_by_user_id' => $lockedPlot->gardenOwner?->user_id,
                        ]);
                        if ($lockedPlot->gardenOwner?->user_id) {
                            FarmMembership::query()->updateOrCreate(
                                ['farm_id' => $farm->id, 'user_id' => $lockedPlot->gardenOwner->user_id],
                                ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]
                            );
                        }
                        $field = Field::query()->create([
                            'farm_id' => $farm->id, 'name' => $lockedPlot->name,
                            'area_square_metres' => max(0, (float) $lockedPlot->plot_size), 'boundary' => $lockedPlot->geometry,
                        ]);
                        LegacyRecordMapping::query()->updateOrCreate(
                            ['legacy_type' => 'plot', 'legacy_id' => $lockedPlot->id],
                            ['target_type' => Field::class, 'target_id' => $field->id, 'classification' => 'high_confidence_field', 'status' => 'migrated', 'confidence' => 1, 'evidence' => ['farm_id' => $farm->id], 'migration_run_id' => $run->id]
                        );
                        DB::table('legacy_migration_audits')->insertOrIgnore([
                            'migration_run_id' => $run->id, 'event' => 'field_migrated', 'legacy_type' => 'plot', 'legacy_id' => $lockedPlot->id,
                            'context' => json_encode(['field_id' => $field->id, 'farm_id' => $farm->id]), 'created_at' => now(),
                        ]);
                        $counts['plots_migrated']++;
                    } else {
                        $counts['mappings_reused']++;
                    }

                    $this->migrateZones($lockedPlot, $field, $run, $counts);
                    DB::table('community_posts')->where('plot_id', $lockedPlot->id)->update(['field_id' => $field->id, 'is_legacy' => true]);
                }, 3);
            }
        });
    }

    private function migrateZones(Plot $plot, Field $field, LegacyMigrationRun $run, array &$counts): void
    {
        PlantZone::query()->where(fn ($query) => $query->where('plot_id', $plot->id)->orWhere('fk_plot_id', $plot->id))->orderBy('id')->get()
            ->each(function (PlantZone $zone) use ($field, $run, &$counts): void {
                $mapping = LegacyRecordMapping::query()->where('legacy_type', 'plant_zone')->where('legacy_id', $zone->id)->lockForUpdate()->first();
                if ($mapping && in_array($mapping->status, self::TERMINAL_MAPPING_STATUSES, true)) {
                    $counts['mappings_reused']++;

                    return;
                }
                $target = FieldZone::query()->firstOrCreate(
                    ['field_id' => $field->id, 'name' => $zone->name],
                    ['area_square_metres' => max(0, (float) $zone->zone_size), 'boundary' => $zone->geometry, 'colour' => $zone->color_hex]
                );
                LegacyRecordMapping::query()->updateOrCreate(
                    ['legacy_type' => 'plant_zone', 'legacy_id' => $zone->id],
                    ['target_type' => FieldZone::class, 'target_id' => $target->id, 'classification' => 'high_confidence_field_zone', 'status' => 'migrated', 'confidence' => 1, 'evidence' => ['field_id' => $field->id], 'migration_run_id' => $run->id]
                );
                DB::table('legacy_migration_audits')->insertOrIgnore([
                    'migration_run_id' => $run->id, 'event' => 'field_zone_migrated', 'legacy_type' => 'plant_zone', 'legacy_id' => $zone->id,
                    'context' => json_encode(['field_zone_id' => $target->id]), 'created_at' => now(),
                ]);
                $counts['field_zones_migrated']++;
            });
    }

    private function migrateCompatibilitySources(LegacyMigrationRun $run, array &$counts): void
    {
        $this->migrateGardenOwners($run, $counts);
        $this->migrateCatalogue($run, $counts);
        $this->migrateAccessRights($run, $counts);
        $this->migrateInventory($run, $counts);
        $this->preserveCommunityPosts($run, $counts);
    }

    private function migrateGardenOwners(LegacyMigrationRun $run, array &$counts): void
    {
        GardenOwner::query()->orderBy('id')->get()->each(function (GardenOwner $owner) use ($run, &$counts): void {
            DB::transaction(function () use ($owner, $run, &$counts): void {
                $lockedOwner = GardenOwner::query()->lockForUpdate()->findOrFail($owner->id);
                $mapping = LegacyRecordMapping::query()->where('legacy_type', 'garden_owner')->where('legacy_id', $lockedOwner->id)->lockForUpdate()->first();
                if ($mapping && in_array($mapping->status, self::TERMINAL_MAPPING_STATUSES, true)) {
                    $counts['mappings_reused']++;

                    return;
                }
                $fieldId = LegacyRecordMapping::query()->where('legacy_type', 'plot')->where('status', 'migrated')
                    ->whereIn('legacy_id', Plot::query()->where('garden_owner_id', $lockedOwner->id)->select('id'))
                    ->orderBy('legacy_id')->value('target_id');
                $farm = $fieldId ? Field::query()->find($fieldId)?->farm : null;
                LegacyRecordMapping::query()->updateOrCreate(
                    ['legacy_type' => 'garden_owner', 'legacy_id' => $lockedOwner->id],
                    [
                        'target_type' => $farm ? Farm::class : null, 'target_id' => $farm?->id,
                        'classification' => $farm ? 'farm_owner_compatibility' : 'owner_without_mapped_plot',
                        'status' => $farm ? 'migrated' : 'preserved_legacy', 'confidence' => $farm ? 1 : 0.2,
                        'evidence' => ['user_id' => $lockedOwner->user_id], 'migration_run_id' => $run->id,
                    ]
                );
                $counts[$farm ? 'garden_owners_mapped' : 'garden_owners_preserved']++;
            }, 3);
        });
    }

    private function migrateCatalogue(LegacyMigrationRun $run, array &$counts): void
    {
        CatalogPlant::query()->orderBy('id')->get()->each(function (CatalogPlant $catalogPlant) use ($run, &$counts): void {
            DB::transaction(function () use ($catalogPlant, $run, &$counts): void {
                $lockedCatalogPlant = CatalogPlant::query()->lockForUpdate()->findOrFail($catalogPlant->id);
                $mapping = LegacyRecordMapping::query()->where('legacy_type', 'catalog_plant')->where('legacy_id', $lockedCatalogPlant->id)->lockForUpdate()->first();
                if ($mapping && in_array($mapping->status, self::TERMINAL_MAPPING_STATUSES, true)) {
                    $counts['mappings_reused']++;

                    return;
                }
                $crop = Crop::query()->firstOrCreate(
                    ['legacy_source' => 'catalog_plants', 'legacy_id' => $lockedCatalogPlant->id],
                    [
                        'name' => $lockedCatalogPlant->name, 'scientific_name' => $lockedCatalogPlant->source_scientific_name,
                        'category' => $lockedCatalogPlant->plant_type?->value ?? $lockedCatalogPlant->plant_type,
                        'is_global' => true, 'farm_id' => null,
                    ]
                );
                LegacyRecordMapping::query()->updateOrCreate(
                    ['legacy_type' => 'catalog_plant', 'legacy_id' => $lockedCatalogPlant->id],
                    ['target_type' => Crop::class, 'target_id' => $crop->id, 'classification' => 'global_crop_catalogue', 'status' => 'migrated', 'confidence' => 1, 'evidence' => ['name' => $lockedCatalogPlant->name], 'migration_run_id' => $run->id]
                );
                $counts['catalogue_crops_migrated']++;
            }, 3);
        });
    }

    private function migrateAccessRights(LegacyMigrationRun $run, array &$counts): void
    {
        AccessRight::query()->orderBy('id')->get()->each(function (AccessRight $right) use ($run, &$counts): void {
            DB::transaction(function () use ($right, $run, &$counts): void {
                $lockedRight = AccessRight::query()->lockForUpdate()->findOrFail($right->id);
                $mapping = LegacyRecordMapping::query()->where('legacy_type', 'access_right')->where('legacy_id', $lockedRight->id)->lockForUpdate()->first();
                if ($mapping && in_array($mapping->status, self::TERMINAL_MAPPING_STATUSES, true)) {
                    $counts['mappings_reused']++;

                    return;
                }
                $fieldId = LegacyRecordMapping::query()->where('legacy_type', 'plot')->where('legacy_id', $lockedRight->plot_id ?? $lockedRight->fk_plot_id)->where('status', 'migrated')->value('target_id');
                $farmId = $fieldId ? Field::query()->whereKey($fieldId)->value('farm_id') : null;
                $userId = $lockedRight->fk_recipient_owner_id;
                $membership = null;
                if ($farmId && $userId) {
                    $membership = FarmMembership::query()->firstOrCreate(
                        ['farm_id' => $farmId, 'user_id' => $userId],
                        ['role' => $lockedRight->role?->value === 'editor' ? 'manager' : 'viewer', 'status' => 'active', 'joined_at' => $lockedRight->granted_at]
                    );
                    $permissions = $lockedRight->role?->value === 'editor'
                        ? ['view_farm', 'manage_fields', 'manage_crops', 'manage_tasks', 'manage_inventory', 'view_analytics']
                        : ['view_farm'];
                    foreach ($permissions as $permission) {
                        FarmMemberPermission::query()->updateOrCreate(
                            ['farm_membership_id' => $membership->id, 'permission' => $permission],
                            ['allowed' => true]
                        );
                    }
                }
                LegacyRecordMapping::query()->updateOrCreate(
                    ['legacy_type' => 'access_right', 'legacy_id' => $lockedRight->id],
                    [
                        'target_type' => $membership ? FarmMembership::class : null, 'target_id' => $membership?->id,
                        'classification' => $membership ? 'farm_membership_permission' : 'invalid_or_orphaned_access',
                        'status' => $membership ? 'migrated' : 'preserved_legacy', 'confidence' => $membership ? 0.95 : 0.05,
                        'evidence' => ['plot_id' => $lockedRight->plot_id ?? $lockedRight->fk_plot_id, 'recipient_user_id' => $userId, 'legacy_role' => $lockedRight->role?->value ?? $lockedRight->role],
                        'migration_run_id' => $run->id,
                    ]
                );
                $counts[$membership ? 'access_rights_migrated' : 'access_rights_preserved']++;
            }, 3);
        });
    }

    private function migrateInventory(LegacyMigrationRun $run, array &$counts): void
    {
        InventoryItem::query()->with('inventoryLinks')->orderBy('id')->get()->each(function (InventoryItem $item) use ($run, &$counts): void {
            DB::transaction(function () use ($item, $run, &$counts): void {
                $lockedItem = InventoryItem::query()->with('inventoryLinks')->lockForUpdate()->findOrFail($item->id);
                $mapping = LegacyRecordMapping::query()->where('legacy_type', 'inventory_item')->where('legacy_id', $lockedItem->id)->lockForUpdate()->first();
                if ($mapping && in_array($mapping->status, self::TERMINAL_MAPPING_STATUSES, true)) {
                    $counts['mappings_reused']++;

                    return;
                }
                $ownerId = $lockedItem->garden_owner_id ?? $lockedItem->inventoryLinks->sortBy('fk_owner_id')->first()?->fk_owner_id;
                $farmId = $ownerId ? FarmMembership::query()->where('user_id', $ownerId)->where('role', 'owner')->where('status', 'active')->orderBy('farm_id')->value('farm_id') : null;
                $target = $farmId ? StockItem::query()->create([
                    'farm_id' => $farmId, 'name' => $lockedItem->name,
                    'category' => $lockedItem->inventory_item_type?->value ?? $lockedItem->type?->value ?? $lockedItem->type,
                    'quantity' => $lockedItem->quantity, 'unit' => $lockedItem->unit?->value ?? $lockedItem->unit ?? 'unit',
                ]) : null;
                LegacyRecordMapping::query()->updateOrCreate(
                    ['legacy_type' => 'inventory_item', 'legacy_id' => $lockedItem->id],
                    [
                        'target_type' => $target ? StockItem::class : null, 'target_id' => $target?->id,
                        'classification' => $target ? 'farm_inventory_item' : 'orphaned_inventory_ownership',
                        'status' => $target ? 'migrated' : 'preserved_legacy', 'confidence' => $target ? 0.9 : 0.05,
                        'evidence' => ['owner_user_id' => $ownerId, 'farm_id' => $farmId], 'migration_run_id' => $run->id,
                    ]
                );
                $counts[$target ? 'inventory_items_migrated' : 'inventory_items_preserved']++;
            }, 3);
        });
    }

    private function preserveCommunityPosts(LegacyMigrationRun $run, array &$counts): void
    {
        CommunityPost::query()->orderBy('id')->get()->each(function (CommunityPost $post) use ($run, &$counts): void {
            DB::transaction(function () use ($post, $run, &$counts): void {
                $lockedPost = CommunityPost::query()->lockForUpdate()->findOrFail($post->id);
                $mapping = LegacyRecordMapping::query()->where('legacy_type', 'community_post')->where('legacy_id', $lockedPost->id)->lockForUpdate()->first();
                if ($mapping && in_array($mapping->status, self::TERMINAL_MAPPING_STATUSES, true)) {
                    $counts['mappings_reused']++;

                    return;
                }
                LegacyRecordMapping::query()->updateOrCreate(
                    ['legacy_type' => 'community_post', 'legacy_id' => $lockedPost->id],
                    [
                        'target_type' => CommunityPost::class, 'target_id' => $lockedPost->id,
                        'classification' => 'legacy_community_history', 'status' => 'preserved_legacy', 'confidence' => 1,
                        'evidence' => ['field_id' => $lockedPost->field_id, 'plot_id' => $lockedPost->plot_id], 'migration_run_id' => $run->id,
                    ]
                );
                $counts['community_posts_preserved']++;
            }, 3);
        });
    }

    private function migratePlant(int $plantId, LegacyMigrationRun $run, array &$counts): void
    {
        DB::transaction(function () use ($plantId, $run, &$counts): void {
            $plant = Plant::query()->with(['plot', 'plantZone', 'catalogPlant'])->lockForUpdate()->findOrFail($plantId);
            $mapping = LegacyRecordMapping::query()->where('legacy_type', 'plant')->where('legacy_id', $plant->id)->lockForUpdate()->first();
            if ($mapping && in_array($mapping->status, self::TERMINAL_MAPPING_STATUSES, true)) {
                $counts['already_migrated']++;
                $counts['mappings_reused']++;

                return;
            }

            $classification = $this->classify($plant);
            $counts[$classification['classification']]++;
            $season = $classification['classification'] === 'high_confidence_crop_season'
                ? $this->migrateHighConfidencePlant($plant)
                : null;
            $status = $season ? 'migrated' : 'preserved_legacy';
            LegacyRecordMapping::query()->updateOrCreate(
                ['legacy_type' => 'plant', 'legacy_id' => $plant->id],
                [
                    'target_type' => $season ? CropSeason::class : null, 'target_id' => $season?->id,
                    'classification' => $classification['classification'], 'status' => $status,
                    'confidence' => $classification['confidence'], 'evidence' => $classification['evidence'],
                    'migration_run_id' => $run->id,
                ]
            );
            DB::table('legacy_migration_audits')->insertOrIgnore([
                'migration_run_id' => $run->id,
                'event' => $season ? 'crop_season_migrated' : 'legacy_plant_preserved',
                'legacy_type' => 'plant', 'legacy_id' => $plant->id,
                'context' => json_encode(['crop_season_id' => $season?->id, 'classification' => $classification['classification']]),
                'created_at' => now(),
            ]);
        }, 3);
    }

    private function migrateHighConfidencePlant(Plant $plant): ?CropSeason
    {
        $fieldId = LegacyRecordMapping::query()->where('legacy_type', 'plot')->where('legacy_id', $plant->fk_plot_id)->where('status', 'migrated')->value('target_id');
        $field = $fieldId ? Field::find($fieldId) : null;
        if (! $field) {
            return null;
        }
        $crop = Crop::query()->firstOrCreate(
            ['farm_id' => $field->farm_id, 'name' => trim($plant->name)],
            ['is_global' => false, 'legacy_source' => 'plants', 'legacy_id' => $plant->id]
        );
        $zoneId = $plant->plant_zone_id ?? $plant->fk_plant_zone_id;
        $fieldZoneId = $zoneId ? LegacyRecordMapping::query()
            ->where('legacy_type', 'plant_zone')->where('legacy_id', $zoneId)->where('status', 'migrated')->value('target_id') : null;

        return CropSeason::query()->firstOrCreate(['legacy_group_key' => $this->plantGroupKey($plant)], [
            'farm_id' => $field->farm_id, 'field_id' => $field->id, 'field_zone_id' => $fieldZoneId, 'crop_id' => $crop->id,
            'name' => $plant->name, 'starts_on' => $plant->plant_date,
            'expected_ends_on' => $plant->harvest_date, 'planted_area_square_metres' => $plant->occupied_area,
            'status' => $plant->harvest_date?->isPast() ? 'completed' : 'active',
            'notes' => 'Created by the Yava Stage 1 legacy classifier.',
        ]);
    }

    private function plantGroupKey(Plant $plant): string
    {
        return hash('sha256', implode('|', [
            $plant->fk_plot_id, $plant->plant_zone_id ?? $plant->fk_plant_zone_id,
            Str::lower(trim($plant->name)), Str::lower(trim((string) $plant->variety)), optional($plant->plant_date)->toDateString(),
        ]));
    }

    private function terminalMapping(string $type, int $id): ?LegacyRecordMapping
    {
        return LegacyRecordMapping::query()->where('legacy_type', $type)->where('legacy_id', $id)
            ->whereIn('status', self::TERMINAL_MAPPING_STATUSES)->first();
    }

    private function terminalMappings(string $type): Collection
    {
        return LegacyRecordMapping::query()->where('legacy_type', $type)->whereIn('status', self::TERMINAL_MAPPING_STATUSES)
            ->orderBy('legacy_id')->get()->keyBy(fn (LegacyRecordMapping $mapping) => (int) $mapping->legacy_id);
    }

    private function uniqueFarmSlug(Plot $plot): string
    {
        $base = Str::slug($plot->name) ?: 'legacy-farm';

        return Farm::query()->where('slug', $base)->exists() ? "{$base}-legacy-{$plot->id}" : $base;
    }

    /** @return array<string, int> */
    private function sourceCounts(): array
    {
        return [
            'garden_owners' => GardenOwner::count(), 'plots' => Plot::count(), 'plant_zones' => PlantZone::count(),
            'catalog_plants' => CatalogPlant::count(), 'plants' => Plant::count(), 'access_rights' => AccessRight::count(),
            'inventory_items' => InventoryItem::count(), 'inventory_ownership_links' => DB::table('has_inventory')->count(),
            'community_posts' => DB::table('community_posts')->count(),
        ];
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return [
            'plots_migrated' => 0, 'field_zones_migrated' => 0, 'garden_owners_mapped' => 0, 'garden_owners_preserved' => 0,
            'catalogue_crops_migrated' => 0, 'access_rights_migrated' => 0, 'access_rights_preserved' => 0,
            'inventory_items_migrated' => 0, 'inventory_items_preserved' => 0, 'community_posts_preserved' => 0,
            'mappings_reused' => 0,
            'high_confidence_crop_season' => 0, 'historical_crop_record' => 0,
            'ambiguous_legacy_plant' => 0, 'invalid_or_orphaned' => 0, 'already_migrated' => 0,
        ];
    }
}
