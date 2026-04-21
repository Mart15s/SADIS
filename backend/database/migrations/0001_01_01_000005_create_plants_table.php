<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('growing_time_days')->nullable();
            $table->decimal('recommended_temperature', 6, 2)->nullable();
            $table->decimal('recommended_humidity', 6, 2)->nullable();
            $table->date('plant_date');
            $table->string('disease')->nullable();
            $table->unsignedInteger('rest_time_days')->nullable();
            $table->decimal('plant_size', 10, 2)->nullable();
            $table->string('type');
            $table->string('condition');
            $table->foreignId('fk_plant_zone_id');
            $table->foreignId('fk_plot_id')->constrained('plots')->cascadeOnDelete();
            $table->foreign(['fk_plant_zone_id', 'fk_plot_id'])
                ->references(['id', 'fk_plot_id'])
                ->on('plant_zones')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plants');
    }
};
