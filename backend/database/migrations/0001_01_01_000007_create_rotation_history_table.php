<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rotation_history', function (Blueprint $table) {
            $table->id();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->foreignId('fk_plot_id')->constrained('plots')->cascadeOnDelete();
            $table->foreignId('fk_plant_zone_id');
            $table->foreignId('fk_plot_via_zone');
            $table->foreignId('fk_plant_id')->constrained('plants')->cascadeOnDelete();
            $table->foreign(['fk_plant_zone_id', 'fk_plot_via_zone'])
                ->references(['id', 'fk_plot_id'])
                ->on('plant_zones')
                ->cascadeOnDelete();
            $table->foreign('fk_plot_via_zone')->references('id')->on('plots')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotation_history');
    }
};
