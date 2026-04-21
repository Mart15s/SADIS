<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plant_care', function (Blueprint $table) {
            $table->id();
            $table->text('description');
            $table->text('conditions')->nullable();
            $table->unsignedInteger('growing_duration_days')->nullable();
            $table->unsignedInteger('flowering_duration_days')->nullable();
            $table->unsignedInteger('germinating_duration_days')->nullable();
            $table->unsignedInteger('mature_duration_days')->nullable();
            $table->unsignedInteger('mature_end_duration_days')->nullable();
            $table->unsignedInteger('regenerating_duration_days')->nullable();
            $table->boolean('reusable')->default(false);
            $table->string('plant_name');
            $table->string('task_type');
            $table->string('plant_type');
            $table->string('condition');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_care');
    }
};
