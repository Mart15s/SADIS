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
        Schema::create('catalog_plants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('canonical_name')->unique();
            $table->string('plant_type')->nullable();
            $table->foreignId('fk_plant_care_id')->nullable()->constrained('plant_care')->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('source_provider')->nullable();
            $table->string('source_quality')->nullable();
            $table->string('source_scientific_name')->nullable();
            $table->string('source_family')->nullable();
            $table->text('source_image_url')->nullable();
            $table->json('metadata')->nullable();
            $table->index('name');
            $table->index('plant_type');
        });

        Schema::table('plants', function (Blueprint $table) {
            if (! Schema::hasColumn('plants', 'fk_catalog_plant_id')) {
                $table->foreignId('fk_catalog_plant_id')->nullable()->after('fk_plant_care_id')->constrained('catalog_plants')->nullOnDelete();
            }
        });

        $catalogIdsByCanonicalName = [];

        foreach (DB::table('plant_care')->orderBy('id')->get() as $care) {
            $canonicalName = $this->normalizeName($care->canonical_name ?? $care->plant_name ?? $care->source_common_name ?? null);

            if (! $canonicalName || isset($catalogIdsByCanonicalName[$canonicalName])) {
                continue;
            }

            $catalogId = DB::table('catalog_plants')->insertGetId([
                'name' => $care->plant_name ?? $care->source_common_name ?? Str::headline($canonicalName),
                'canonical_name' => $canonicalName,
                'plant_type' => $care->plant_type,
                'fk_plant_care_id' => $care->id,
                'description' => $care->description,
                'source_provider' => $care->source_provider,
                'source_quality' => $care->source_quality,
                'source_scientific_name' => $care->source_scientific_name,
                'source_family' => $care->source_family,
                'source_image_url' => $care->source_image_url,
                'metadata' => null,
            ]);

            $catalogIdsByCanonicalName[$canonicalName] = $catalogId;
        }

        foreach (DB::table('plants')->orderBy('id')->get() as $plant) {
            $canonicalName = $this->normalizeName($plant->name ?? null);
            $catalogId = null;

            if ($canonicalName && isset($catalogIdsByCanonicalName[$canonicalName])) {
                $catalogId = $catalogIdsByCanonicalName[$canonicalName];
            }

            if (! $catalogId && $canonicalName) {
                $catalogId = DB::table('catalog_plants')->insertGetId([
                    'name' => $plant->name,
                    'canonical_name' => $canonicalName,
                    'plant_type' => $plant->type,
                    'fk_plant_care_id' => $plant->fk_plant_care_id,
                    'description' => null,
                    'source_provider' => 'legacy',
                    'source_quality' => 'default',
                    'source_scientific_name' => null,
                    'source_family' => null,
                    'source_image_url' => null,
                    'metadata' => null,
                ]);

                $catalogIdsByCanonicalName[$canonicalName] = $catalogId;
            }

            if ($catalogId) {
                DB::table('plants')
                    ->where('id', $plant->id)
                    ->update(['fk_catalog_plant_id' => $catalogId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            if (Schema::hasColumn('plants', 'fk_catalog_plant_id')) {
                $table->dropForeign(['fk_catalog_plant_id']);
                $table->dropColumn('fk_catalog_plant_id');
            }
        });

        Schema::dropIfExists('catalog_plants');
    }

    private function normalizeName(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();

        return $value !== '' ? $value : null;
    }
};
