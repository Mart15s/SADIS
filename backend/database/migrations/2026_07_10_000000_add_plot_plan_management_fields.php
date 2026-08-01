<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ZONE_PALETTE = [
        '#4F7A5A',
        '#A06B3B',
        '#3F7C78',
        '#7A659A',
        '#9A5C54',
        '#667A3F',
        '#4B6F8A',
        '#8A7048',
    ];

    public function up(): void
    {
        Schema::table('plant_zones', function (Blueprint $table) {
            $table->string('color_hex', 7)->nullable()->after('name');
            $table->timestamp('archived_at')->nullable()->after('geometry');
            $table->index(['plot_id', 'archived_at'], 'plant_zones_plot_archive_index');
        });

        DB::table('plant_zones')
            ->orderBy('fk_plot_id')
            ->orderBy('id')
            ->get(['id', 'fk_plot_id'])
            ->groupBy('fk_plot_id')
            ->each(function ($zones): void {
                foreach ($zones->values() as $index => $zone) {
                    DB::table('plant_zones')
                        ->where('id', $zone->id)
                        ->update(['color_hex' => self::ZONE_PALETTE[$index % count(self::ZONE_PALETTE)]]);
                }
            });

        Schema::table('plants', function (Blueprint $table) {
            $table->string('variety')->nullable()->after('name');
            $table->decimal('quantity', 10, 2)->nullable()->after('plant_size');
            $table->decimal('occupied_area', 10, 2)->nullable()->after('quantity');
            $table->string('season', 40)->nullable()->after('occupied_area');
            $table->date('harvest_date')->nullable()->after('season');
            $table->text('notes')->nullable()->after('disease_notes');
        });
    }

    public function down(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            $table->dropColumn(['variety', 'quantity', 'occupied_area', 'season', 'harvest_date', 'notes']);
        });

        Schema::table('plant_zones', function (Blueprint $table) {
            $table->dropIndex('plant_zones_plot_archive_index');
            $table->dropColumn(['color_hex', 'archived_at']);
        });
    }
};
