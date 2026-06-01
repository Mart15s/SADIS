<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weather_forecasts', function (Blueprint $table) {
            if (! Schema::hasColumn('weather_forecasts', 'fetched_at')) {
                $table->timestamp('fetched_at')->nullable()->after('city');
            }
        });
    }

    public function down(): void
    {
        Schema::table('weather_forecasts', function (Blueprint $table) {
            if (Schema::hasColumn('weather_forecasts', 'fetched_at')) {
                $table->dropColumn('fetched_at');
            }
        });
    }
};
