<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plant_care', function (Blueprint $table) {
            if (! Schema::hasColumn('plant_care', 'watering_interval_days')) {
                $table->integer('watering_interval_days')->default(2);
            }

            if (! Schema::hasColumn('plant_care', 'fertilizing_interval_days')) {
                $table->integer('fertilizing_interval_days')->default(14);
            }

            if (! Schema::hasColumn('plant_care', 'pest_check_interval_days')) {
                $table->integer('pest_check_interval_days')->default(7);
            }

            if (! Schema::hasColumn('plant_care', 'rain_skip_threshold_mm')) {
                $table->decimal('rain_skip_threshold_mm', 5, 1)->default(10.0);
            }

            if (! Schema::hasColumn('plant_care', 'frost_temp_threshold_c')) {
                $table->decimal('frost_temp_threshold_c', 4, 1)->default(2.0);
            }

            if (! Schema::hasColumn('plant_care', 'heat_extra_water_temp_c')) {
                $table->decimal('heat_extra_water_temp_c', 4, 1)->default(32.0);
            }

            if (! Schema::hasColumn('plant_care', 'wind_protection_kmh')) {
                $table->decimal('wind_protection_kmh', 5, 1)->default(50.0);
            }

            if (! Schema::hasColumn('plant_care', 'reusable')) {
                $table->boolean('reusable')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('plant_care', function (Blueprint $table) {
            foreach ([
                'watering_interval_days',
                'fertilizing_interval_days',
                'pest_check_interval_days',
                'rain_skip_threshold_mm',
                'frost_temp_threshold_c',
                'heat_extra_water_temp_c',
                'wind_protection_kmh',
            ] as $column) {
                if (Schema::hasColumn('plant_care', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
