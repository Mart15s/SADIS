<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            $table->decimal('marker_position_x', 7, 6)->nullable()->after('notes');
            $table->decimal('marker_position_y', 7, 6)->nullable()->after('marker_position_x');
        });
    }

    public function down(): void
    {
        Schema::table('plants', function (Blueprint $table) {
            $table->dropColumn(['marker_position_x', 'marker_position_y']);
        });
    }
};
