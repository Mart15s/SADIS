<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plant_condition_history', function (Blueprint $table) {
            $table->id();
            $table->timestamp('measured_at');
            $table->text('notes')->nullable();
            $table->string('photo_url')->nullable();
            $table->string('condition');
            $table->foreignId('fk_plant_id')->constrained('plants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_condition_history');
    }
};
