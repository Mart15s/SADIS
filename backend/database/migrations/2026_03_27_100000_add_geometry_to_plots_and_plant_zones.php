<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plots', function (Blueprint $table) {
            $table->jsonb('geometry')->nullable()->after('share');
        });

        Schema::table('plant_zones', function (Blueprint $table) {
            $table->jsonb('geometry')->nullable()->after('fk_plot_id');
        });
    }

    public function down(): void
    {
        Schema::table('plant_zones', function (Blueprint $table) {
            $table->dropColumn('geometry');
        });

        Schema::table('plots', function (Blueprint $table) {
            $table->dropColumn('geometry');
        });
    }
};
