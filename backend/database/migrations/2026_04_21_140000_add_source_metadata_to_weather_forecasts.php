<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weather_forecasts', function (Blueprint $table) {
            if (! Schema::hasColumn('weather_forecasts', 'source')) {
                $table->string('source')->nullable()->after('condition_code');
            }

            if (! Schema::hasColumn('weather_forecasts', 'source_date')) {
                $table->date('source_date')->nullable()->after('source');
            }

            if (! Schema::hasColumn('weather_forecasts', 'source_city')) {
                $table->string('source_city')->nullable()->after('source_date');
            }
        });

        DB::table('weather_forecasts')
            ->whereNull('source')
            ->update([
                'source' => DB::raw("CASE WHEN is_seasonal_fallback = true THEN 'seasonal' ELSE 'legacy_unknown' END"),
            ]);
    }

    public function down(): void
    {
        Schema::table('weather_forecasts', function (Blueprint $table) {
            foreach (['source_city', 'source_date', 'source'] as $column) {
                if (Schema::hasColumn('weather_forecasts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
