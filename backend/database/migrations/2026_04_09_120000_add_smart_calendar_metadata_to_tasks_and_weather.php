<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'priority')) {
                $table->string('priority')->nullable()->after('type');
            }

            if (! Schema::hasColumn('tasks', 'reason')) {
                $table->text('reason')->nullable()->after('priority');
            }

            if (! Schema::hasColumn('tasks', 'weather_context')) {
                $table->json('weather_context')->nullable()->after('reason');
            }

            if (! Schema::hasColumn('tasks', 'inventory_context')) {
                $table->json('inventory_context')->nullable()->after('weather_context');
            }

            if (! Schema::hasColumn('tasks', 'simulated_state')) {
                $table->json('simulated_state')->nullable()->after('inventory_context');
            }
        });

        Schema::table('weather_forecasts', function (Blueprint $table) {
            if (! Schema::hasColumn('weather_forecasts', 'condition_code')) {
                $table->string('condition_code')->nullable()->after('wind_kmh');
            }
        });
    }

    public function down(): void
    {
        Schema::table('weather_forecasts', function (Blueprint $table) {
            if (Schema::hasColumn('weather_forecasts', 'condition_code')) {
                $table->dropColumn('condition_code');
            }
        });

        Schema::table('tasks', function (Blueprint $table) {
            foreach (['priority', 'reason', 'weather_context', 'inventory_context', 'simulated_state'] as $column) {
                if (Schema::hasColumn('tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
