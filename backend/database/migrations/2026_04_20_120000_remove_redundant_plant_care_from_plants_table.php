<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillCatalogLinksFromLegacyPlantCare();

        Schema::table('plants', function (Blueprint $table) {
            if (Schema::hasColumn('plants', 'fk_plant_care_id')) {
                $table->dropForeign(['fk_plant_care_id']);
                $table->dropColumn('fk_plant_care_id');
            }

            if (Schema::hasColumn('plants', 'plant_care_overrides')) {
                $table->dropColumn('plant_care_overrides');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            if (! Schema::hasColumn('plants', 'fk_plant_care_id')) {
                $table->unsignedBigInteger('fk_plant_care_id')->nullable()->after('condition');
                $table->foreign('fk_plant_care_id')
                    ->references('id')
                    ->on('plant_care')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('plants', 'plant_care_overrides')) {
                $table->json('plant_care_overrides')->nullable()->after('fk_plant_care_id');
            }
        });

        DB::table('plants')
            ->whereNotNull('fk_catalog_plant_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $plant): void {
                $catalogCareId = DB::table('catalog_plants')
                    ->where('id', $plant->fk_catalog_plant_id)
                    ->value('fk_plant_care_id');

                DB::table('plants')
                    ->where('id', $plant->id)
                    ->update([
                        'fk_plant_care_id' => $catalogCareId,
                        'plant_care_overrides' => null,
                    ]);
            });
    }

    private function backfillCatalogLinksFromLegacyPlantCare(): void
    {
        if (! Schema::hasColumn('plants', 'fk_plant_care_id')) {
            return;
        }

        DB::table('plants')
            ->orderBy('id')
            ->get()
            ->each(function (object $plant): void {
                $catalogId = $plant->fk_catalog_plant_id;
                $legacyCareId = $plant->fk_plant_care_id;

                if ($catalogId) {
                    $catalogCareId = DB::table('catalog_plants')
                        ->where('id', $catalogId)
                        ->value('fk_plant_care_id');

                    if ($catalogCareId === null && $legacyCareId !== null) {
                        DB::table('catalog_plants')
                            ->where('id', $catalogId)
                            ->update(['fk_plant_care_id' => $legacyCareId]);
                    }

                    return;
                }

                $legacyCare = $legacyCareId
                    ? DB::table('plant_care')->where('id', $legacyCareId)->first()
                    : null;

                $canonicalName = $this->normalizeName(
                    $legacyCare->canonical_name
                    ?? $legacyCare->plant_name
                    ?? $plant->name
                    ?? null
                );

                if ($canonicalName === null) {
                    return;
                }

                $catalogId = DB::table('catalog_plants')
                    ->where('canonical_name', $canonicalName)
                    ->value('id');

                if (! $catalogId) {
                    $catalogId = DB::table('catalog_plants')->insertGetId([
                        'name' => $legacyCare->plant_name ?? $plant->name ?? Str::headline($canonicalName),
                        'canonical_name' => $canonicalName,
                        'plant_type' => $plant->type ?? $legacyCare->plant_type ?? null,
                        'fk_plant_care_id' => $legacyCareId,
                        'description' => $legacyCare->description ?? null,
                        'source_provider' => $legacyCare->source_provider ?? 'legacy',
                        'source_quality' => $legacyCare->source_quality ?? 'default',
                        'source_scientific_name' => $legacyCare->source_scientific_name ?? null,
                        'source_family' => $legacyCare->source_family ?? null,
                        'source_image_url' => $legacyCare->source_image_url ?? null,
                        'metadata' => null,
                    ]);
                } elseif ($legacyCareId !== null) {
                    DB::table('catalog_plants')
                        ->where('id', $catalogId)
                        ->whereNull('fk_plant_care_id')
                        ->update(['fk_plant_care_id' => $legacyCareId]);
                }

                DB::table('plants')
                    ->where('id', $plant->id)
                    ->update(['fk_catalog_plant_id' => $catalogId]);
            });
    }

    private function normalizeName(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();

        return $normalized !== '' ? $normalized : null;
    }
};
