<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('used_on', function (Blueprint $table) {
            $table->foreignId('fk_plant_zone_id');
            $table->foreignId('fk_plot_id');
            $table->foreignId('fk_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->primary(['fk_plant_zone_id', 'fk_plot_id', 'fk_task_id']);
            $table->foreign(['fk_plant_zone_id', 'fk_plot_id'])
                ->references(['id', 'fk_plot_id'])
                ->on('plant_zones')
                ->cascadeOnDelete();
            $table->foreign('fk_plot_id')->references('id')->on('plots')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_on');
    }
};
