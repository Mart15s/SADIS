<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_calendars', function (Blueprint $table) {
            if (Schema::hasColumn('task_calendars', 'fk_plant_care_id')) {
                $table->dropForeign(['fk_plant_care_id']);
                $table->dropColumn('fk_plant_care_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('task_calendars', function (Blueprint $table) {
            if (! Schema::hasColumn('task_calendars', 'fk_plant_care_id')) {
                $table->unsignedBigInteger('fk_plant_care_id')->nullable()->after('fk_plot_id');
                $table->foreign('fk_plant_care_id')
                    ->references('id')
                    ->on('plant_care')
                    ->restrictOnDelete();
            }
        });
    }
};
