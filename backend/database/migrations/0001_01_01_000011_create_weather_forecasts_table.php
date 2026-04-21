<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_forecasts', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->decimal('temperature', 6, 2);
            $table->decimal('precipitation', 6, 2);
            $table->decimal('humidity', 6, 2);
            $table->string('city');
            $table->foreignId('fk_task_calendar_id')->constrained('task_calendars')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_forecasts');
    }
};
