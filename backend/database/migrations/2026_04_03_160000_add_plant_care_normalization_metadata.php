<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plant_care', function (Blueprint $table) {
            if (! Schema::hasColumn('plant_care', 'canonical_name')) {
                $table->string('canonical_name')->nullable()->after('plant_name');
            }

            if (! Schema::hasColumn('plant_care', 'source_provider')) {
                $table->string('source_provider')->nullable()->after('canonical_name');
            }

            if (! Schema::hasColumn('plant_care', 'source_quality')) {
                $table->string('source_quality')->nullable()->after('source_provider');
            }

            if (! Schema::hasColumn('plant_care', 'source_perenual_species_id')) {
                $table->unsignedBigInteger('source_perenual_species_id')->nullable()->after('source_quality');
            }

            if (! Schema::hasColumn('plant_care', 'source_common_name')) {
                $table->string('source_common_name')->nullable()->after('source_perenual_species_id');
            }

            if (! Schema::hasColumn('plant_care', 'source_scientific_name')) {
                $table->string('source_scientific_name')->nullable()->after('source_common_name');
            }

            if (! Schema::hasColumn('plant_care', 'source_family')) {
                $table->string('source_family')->nullable()->after('source_scientific_name');
            }

            if (! Schema::hasColumn('plant_care', 'source_image_url')) {
                $table->text('source_image_url')->nullable()->after('source_family');
            }
        });

        Schema::table('plant_care', function (Blueprint $table) {
            $table->index('canonical_name', 'plant_care_canonical_name_idx');
            $table->index('source_perenual_species_id', 'plant_care_source_species_idx');
        });
    }

    public function down(): void
    {
        Schema::table('plant_care', function (Blueprint $table) {
            try {
                $table->dropIndex('plant_care_canonical_name_idx');
            } catch (Throwable) {
            }

            try {
                $table->dropIndex('plant_care_source_species_idx');
            } catch (Throwable) {
            }

            foreach ([
                'source_image_url',
                'source_family',
                'source_scientific_name',
                'source_common_name',
                'source_perenual_species_id',
                'source_quality',
                'source_provider',
                'canonical_name',
            ] as $column) {
                if (Schema::hasColumn('plant_care', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
