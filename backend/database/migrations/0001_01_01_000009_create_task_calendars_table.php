<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_calendars', function (Blueprint $table) {
            $table->id();
            $table->timestamp('creation_date');
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('fk_plot_id')->constrained('plots')->cascadeOnDelete();
            $table->foreignId('fk_plant_care_id')->constrained('plant_care')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_calendars');
    }
};
