<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            if (! Schema::hasColumn('plants', 'plant_care_overrides')) {
                $table->json('plant_care_overrides')->nullable()->after('fk_plant_care_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            if (Schema::hasColumn('plants', 'plant_care_overrides')) {
                $table->dropColumn('plant_care_overrides');
            }
        });
    }
};
