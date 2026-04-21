<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plant_care', 'mature_duration_end_days')) {
            Schema::table('plant_care', function (Blueprint $table) {
                $table->unsignedInteger('mature_duration_end_days')
                    ->nullable()
                    ->after('mature_duration_days');
            });
        }

        if (Schema::hasColumn('plant_care', 'mature_end_duration_days')) {
            DB::table('plant_care')
                ->whereNull('mature_duration_end_days')
                ->update([
                    'mature_duration_end_days' => DB::raw('mature_end_duration_days'),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plant_care', 'mature_duration_end_days')) {
            Schema::table('plant_care', function (Blueprint $table) {
                $table->dropColumn('mature_duration_end_days');
            });
        }
    }
};
