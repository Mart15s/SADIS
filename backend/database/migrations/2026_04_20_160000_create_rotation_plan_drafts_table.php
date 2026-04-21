<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rotation_plan_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plot_id')->constrained('plots')->cascadeOnDelete();
            $table->foreignId('garden_owner_id')->nullable()->constrained('garden_owners')->nullOnDelete();
            $table->date('planning_date');
            $table->json('plan');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotation_plan_drafts');
    }
};
