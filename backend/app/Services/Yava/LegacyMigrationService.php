<?php

namespace App\Services\Yava;

use App\Models\Crop;
use App\Models\CropSeason;
use App\Models\Farm;
use App\Models\FarmMembership;
use App\Models\Field;
use App\Models\LegacyMigrationRun;
use App\Models\LegacyRecordMapping;
use App\Models\Plant;
use App\Models\Plot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class LegacyMigrationService
{
    public function run(bool $execute = false, int $chunkSize = 250, ?string $runId = null, ?int $limit = null): LegacyMigrationRun
    {
        $run = $runId ? LegacyMigrationRun::query()->findOrFail($runId) : LegacyMigrationRun::query()->create([
            'type' => 'yava_stage_one', 'status' => 'pending', 'dry_run' => ! $execute,
            'chunk_size' => max(10, min($chunkSize, 2000)), 'counts' => $this->emptyCounts(),
        ]);
        abort_if((bool) $run->dry_run !== ! $execute, 422, 'A migration run cannot switch between dry-run and execute mode.');
        $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'error' => null]);
        $counts = array_merge($this->emptyCounts(), $run->counts ?? []);
        $processed = 0;

        try {
            if ($execute) {
                $this->migratePlots($run, $counts);
            }

            $query = Plant::query()->with(['plot', 'plantZone', 'catalogPlant'])->where('id', '>', $run->last_legacy_id)->orderBy('id');
            $query->chunkById($run->chunk_size, function ($plants) use ($run, $execute, $limit, &$counts, &$processed): bool {
                foreach ($plants as $plant) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }
                    $processed++;
                    $classification = $this->classify($plant);
                    $counts[$classification['classification']]++;
                    $target = $execute && $classification['classification'] === 'high_confidence_crop_season'
                        ? $this->migrateHighConfidencePlant($plant, $classification, $run)
                        : null;
                    if ($execute || ! LegacyRecordMapping::query()->where('legacy_type', 'plant')->where('legacy_id', $plant->id)->exists()) {
                        LegacyRecordMapping::query()->updateOrCreate(
                            ['legacy_type' => 'plant', 'legacy_id' => $plant->id],
                            [
                                'target_type' => $target ? CropSeason::class : null, 'target_id' => $target?->id,
                                'classification' => $classification['classification'],
                                'status' => $target ? 'migrated' : ($execute ? 'preserved_legacy' : 'classified'),
                                'confidence' => $classification['confidence'], 'evidence' => $classification['evidence'],
                                'migration_run_id' => $run->id,
                            ]
                        );
                    }
                    $run->update(['last_legacy_id' => $plant->id, 'counts' => $counts]);
                }

                return $limit === null || $processed < $limit;
            });

            $remaining = Plant::query()->where('id', '>', $run->last_legacy_id)->exists();
            $run->update(['status' => $remaining ? 'paused' : 'completed', 'counts' => $counts, 'completed_at' => $remaining ? null : now()]);
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
        if (LegacyRecordMapping::query()->where('legacy_type', 'plant')->where('legacy_id', $plant->id)->where('status', 'migrated')->exists()) {
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

    public function counts(): array
    {
        return [
            'legacy' => ['plots' => Plot::count(), 'plants' => Plant::count(), 'community_posts' => DB::table('community_posts')->count()],
            'stage_one' => ['farms' => Farm::count(), 'fields' => Field::count(), 'crop_seasons' => CropSeason::count()],
            'unmapped' => [
                'plots' => Plot::query()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('legacy_record_mappings')->whereColumn('legacy_record_mappings.legacy_id', 'plots.id')->where('legacy_type', 'plot'))->count(),
                'plants' => Plant::query()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('legacy_record_mappings')->whereColumn('legacy_record_mappings.legacy_id', 'plants.id')->where('legacy_type', 'plant'))->count(),
            ],
            'orphans' => [
                'plants_without_plot' => Plant::query()->whereDoesntHave('plot')->count(),
                'crop_seasons_without_field' => CropSeason::query()->whereDoesntHave('field')->count(),
            ],
        ];
    }

    private function migratePlots(LegacyMigrationRun $run, array &$counts): void
    {
        Plot::query()->with('gardenOwner')->orderBy('id')->chunkById($run->chunk_size, function ($plots) use ($run, &$counts): void {
            foreach ($plots as $plot) {
                if (LegacyRecordMapping::query()->where('legacy_type', 'plot')->where('legacy_id', $plot->id)->where('status', 'migrated')->exists()) {
                    continue;
                }
                DB::transaction(function () use ($plot, $run, &$counts): void {
                    $farm = Farm::query()->create([
                        'name' => $plot->name, 'slug' => $this->uniqueFarmSlug($plot),
                        'description' => $plot->description, 'area_square_metres' => max(0, (float) $plot->plot_size),
                        'locality' => $plot->city, 'created_by_user_id' => $plot->gardenOwner?->user_id,
                    ]);
                    if ($plot->gardenOwner?->user_id) {
                        FarmMembership::query()->updateOrCreate(
                            ['farm_id' => $farm->id, 'user_id' => $plot->gardenOwner->user_id],
                            ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]
                        );
                    }
                    $field = Field::query()->create([
                        'farm_id' => $farm->id, 'name' => $plot->name,
                        'area_square_metres' => max(0, (float) $plot->plot_size), 'boundary' => $plot->geometry,
                    ]);
                    LegacyRecordMapping::query()->updateOrCreate(
                        ['legacy_type' => 'plot', 'legacy_id' => $plot->id],
                        ['target_type' => Field::class, 'target_id' => $field->id, 'classification' => 'high_confidence_field', 'status' => 'migrated', 'confidence' => 1, 'evidence' => ['farm_id' => $farm->id], 'migration_run_id' => $run->id]
                    );
                    DB::table('community_posts')->where('plot_id', $plot->id)->update(['field_id' => $field->id, 'is_legacy' => true]);
                    $counts['plots_migrated']++;
                });
            }
        });
    }

    private function migrateHighConfidencePlant(Plant $plant, array $classification, LegacyMigrationRun $run): ?CropSeason
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
        $groupKey = hash('sha256', implode('|', [
            $plant->fk_plot_id, $plant->plant_zone_id ?? $plant->fk_plant_zone_id,
            Str::lower(trim($plant->name)), Str::lower(trim((string) $plant->variety)), $plant->plant_date->toDateString(),
        ]));
        $season = CropSeason::query()->firstOrCreate(['legacy_group_key' => $groupKey], [
            'farm_id' => $field->farm_id, 'field_id' => $field->id, 'crop_id' => $crop->id,
            'name' => $plant->name, 'starts_on' => $plant->plant_date,
            'expected_ends_on' => $plant->harvest_date, 'planted_area_square_metres' => $plant->occupied_area,
            'status' => $plant->harvest_date?->isPast() ? 'completed' : 'active',
            'notes' => 'Created by the Yava Stage 1 legacy classifier.',
        ]);
        DB::table('legacy_migration_audits')->insert([
            'migration_run_id' => $run->id, 'event' => 'crop_season_migrated',
            'legacy_type' => 'plant', 'legacy_id' => $plant->id,
            'context' => json_encode(['crop_season_id' => $season->id, 'classification' => $classification['classification']]),
            'created_at' => now(),
        ]);

        return $season;
    }

    private function uniqueFarmSlug(Plot $plot): string
    {
        $base = Str::slug($plot->name) ?: 'legacy-farm';
        return Farm::query()->where('slug', $base)->exists() ? "{$base}-legacy-{$plot->id}" : $base;
    }

    private function emptyCounts(): array
    {
        return [
            'plots_migrated' => 0, 'high_confidence_crop_season' => 0, 'historical_crop_record' => 0,
            'ambiguous_legacy_plant' => 0, 'invalid_or_orphaned' => 0, 'already_migrated' => 0,
        ];
    }
}
