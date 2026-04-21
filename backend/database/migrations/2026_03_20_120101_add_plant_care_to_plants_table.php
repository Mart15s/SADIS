<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            if (! Schema::hasColumn('plants', 'fk_plant_care_id')) {
                $table->unsignedBigInteger('fk_plant_care_id')->nullable()->after('condition');
                $table->foreign('fk_plant_care_id')
                    ->references('id')
                    ->on('plant_care')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            if (Schema::hasColumn('plants', 'fk_plant_care_id')) {
                $table->dropForeign(['fk_plant_care_id']);
                $table->dropColumn('fk_plant_care_id');
            }
        });
    }
};
