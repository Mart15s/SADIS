<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->timestamp('invalidated_at')->nullable()->after('verified_at');
        });

        Schema::table('work_tasks', function (Blueprint $table) {
            $table->string('task_type', 64)->default('custom')->after('title');
            $table->text('materials')->nullable()->after('description');
            $table->foreignId('shared_resource_id')->nullable()->after('crop_season_id')
                ->constrained('shared_resources')->nullOnDelete();
            $table->text('weather_warning')->nullable()->after('materials');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('field_id')->nullable()->after('work_task_id')->constrained()->nullOnDelete();
            $table->foreignId('crop_season_id')->nullable()->after('field_id')->constrained()->nullOnDelete();
        });

        Schema::table('resource_reservations', function (Blueprint $table) {
            $table->foreignId('field_id')->nullable()->after('farm_id')->constrained()->nullOnDelete();
        });

        DB::table('legacy_migration_audits')
            ->select('event', 'legacy_type', 'legacy_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('legacy_type')->whereNotNull('legacy_id')
            ->groupBy('event', 'legacy_type', 'legacy_id')->havingRaw('COUNT(*) > 1')
            ->orderBy('keep_id')->get()->each(function ($duplicate): void {
                DB::table('legacy_migration_audits')
                    ->where('event', $duplicate->event)
                    ->where('legacy_type', $duplicate->legacy_type)
                    ->where('legacy_id', $duplicate->legacy_id)
                    ->where('id', '!=', $duplicate->keep_id)
                    ->delete();
            });

        Schema::table('legacy_migration_audits', function (Blueprint $table) {
            $table->unique(
                ['event', 'legacy_type', 'legacy_id'],
                'legacy_migration_audits_subject_event_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('resource_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('field_id');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crop_season_id');
            $table->dropConstrainedForeignId('field_id');
        });

        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shared_resource_id');
            $table->dropColumn(['task_type', 'materials', 'weather_warning']);
        });

        Schema::table('legacy_migration_audits', function (Blueprint $table) {
            $table->dropUnique('legacy_migration_audits_subject_event_unique');
        });

        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->dropColumn('invalidated_at');
        });
    }
};
