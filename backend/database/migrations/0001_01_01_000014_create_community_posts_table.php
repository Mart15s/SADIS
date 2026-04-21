<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_posts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('text');
            $table->boolean('share')->default(true);
            $table->timestamp('created_at');
            $table->foreignId('fk_owner_id');
            $table->foreignId('fk_profile_id');
            $table->foreignId('fk_plot_id')->nullable()->constrained('plots')->nullOnDelete();

            $table->foreign(['fk_owner_id', 'fk_profile_id'])
                ->references(['id_user', 'fk_profile_id'])
                ->on('garden_owners')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_posts');
    }
};
