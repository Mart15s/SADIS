<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('has_plot', function (Blueprint $table) {
            $table->foreignId('fk_plot_id')->constrained('plots')->cascadeOnDelete();
            $table->foreignId('fk_owner_id');
            $table->foreignId('fk_profile_id');
            $table->primary(['fk_plot_id', 'fk_owner_id', 'fk_profile_id']);
            $table->foreign(['fk_owner_id', 'fk_profile_id'])
                ->references(['id_user', 'fk_profile_id'])
                ->on('garden_owners')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('has_plot');
    }
};
