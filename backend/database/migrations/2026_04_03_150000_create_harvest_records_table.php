<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harvest_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plot_id')->constrained('plots')->cascadeOnDelete();
            $table->foreignId('plant_id')->constrained('plants')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('garden_owner_id')->nullable()->constrained('garden_owners')->nullOnDelete();
            $table->double('quantity');
            $table->date('harvested_on');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harvest_records');
    }
};
