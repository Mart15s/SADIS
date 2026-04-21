<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'type')) {
                $table->string('type')->nullable()->after('name');
            }

            if (! Schema::hasColumn('tasks', 'fk_plant_id')) {
                $table->foreignId('fk_plant_id')
                    ->nullable()
                    ->after('fk_task_calendar_id')
                    ->constrained('plants')
                    ->nullOnDelete();
            }
        });

        Schema::table('weather_forecasts', function (Blueprint $table) {
            if (! Schema::hasColumn('weather_forecasts', 'temp_min')) {
                $table->decimal('temp_min', 6, 2)->nullable()->after('temperature');
            }

            if (! Schema::hasColumn('weather_forecasts', 'temp_max')) {
                $table->decimal('temp_max', 6, 2)->nullable()->after('temp_min');
            }

            if (! Schema::hasColumn('weather_forecasts', 'wind_kmh')) {
                $table->decimal('wind_kmh', 6, 2)->nullable()->after('humidity');
            }

            if (! Schema::hasColumn('weather_forecasts', 'is_seasonal_fallback')) {
                $table->boolean('is_seasonal_fallback')->default(false)->after('wind_kmh');
            }
        });
    }

    public function down(): void
    {
        Schema::table('weather_forecasts', function (Blueprint $table) {
            foreach (['temp_min', 'temp_max', 'wind_kmh', 'is_seasonal_fallback'] as $column) {
                if (Schema::hasColumn('weather_forecasts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'fk_plant_id')) {
                $table->dropForeign(['fk_plant_id']);
                $table->dropColumn('fk_plant_id');
            }

            if (Schema::hasColumn('tasks', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
