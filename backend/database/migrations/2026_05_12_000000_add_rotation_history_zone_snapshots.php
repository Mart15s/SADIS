<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rotation_history', function (Blueprint $table) {
            if (! Schema::hasColumn('rotation_history', 'from_plant_zone_id')) {
                $table->foreignId('from_plant_zone_id')->nullable()->after('plant_zone_id')->constrained('plant_zones')->nullOnDelete();
            }

            if (! Schema::hasColumn('rotation_history', 'from_zone_name')) {
                $table->string('from_zone_name')->nullable()->after('from_plant_zone_id');
            }

            if (! Schema::hasColumn('rotation_history', 'to_zone_name')) {
                $table->string('to_zone_name')->nullable()->after('from_zone_name');
            }

            if (! Schema::hasColumn('rotation_history', 'decision_status')) {
                $table->string('decision_status')->nullable()->after('to_zone_name');
            }

            if (! Schema::hasColumn('rotation_history', 'decision_note')) {
                $table->text('decision_note')->nullable()->after('decision_status');
            }
        });

        DB::table('rotation_history')
            ->whereNull('to_zone_name')
            ->orderBy('id')
            ->get(['id', 'fk_plant_zone_id'])
            ->each(function (object $history): void {
                $zoneName = DB::table('plant_zones')
                    ->where('id', $history->fk_plant_zone_id)
                    ->value('name');

                if ($zoneName) {
                    DB::table('rotation_history')
                        ->where('id', $history->id)
                        ->update(['to_zone_name' => $zoneName]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('rotation_history', function (Blueprint $table) {
            if (Schema::hasColumn('rotation_history', 'decision_note')) {
                $table->dropColumn('decision_note');
            }

            if (Schema::hasColumn('rotation_history', 'decision_status')) {
                $table->dropColumn('decision_status');
            }

            if (Schema::hasColumn('rotation_history', 'to_zone_name')) {
                $table->dropColumn('to_zone_name');
            }

            if (Schema::hasColumn('rotation_history', 'from_zone_name')) {
                $table->dropColumn('from_zone_name');
            }

            if (Schema::hasColumn('rotation_history', 'from_plant_zone_id')) {
                $table->dropConstrainedForeignId('from_plant_zone_id');
            }
        });
    }
};
