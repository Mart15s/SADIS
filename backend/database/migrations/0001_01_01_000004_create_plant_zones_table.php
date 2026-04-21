<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plant_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('zone_size', 10, 2);
            $table->string('soil_type');
            $table->unsignedInteger('rotation_stage')->default(0);
            $table->date('last_planting_date')->nullable();
            $table->foreignId('fk_plot_id')->constrained('plots')->cascadeOnDelete();
            $table->unique(['id', 'fk_plot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_zones');
    }
};
