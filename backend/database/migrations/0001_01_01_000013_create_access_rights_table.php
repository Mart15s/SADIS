<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_rights', function (Blueprint $table) {
            $table->id();
            $table->timestamp('granted_at');
            $table->string('role');
            $table->foreignId('fk_plot_id')->constrained('plots')->cascadeOnDelete();
            $table->foreignId('fk_grantor_owner_id');
            $table->foreignId('fk_grantor_profile_id');
            $table->foreignId('fk_recipient_owner_id');
            $table->foreignId('fk_recipient_profile_id');

            $table->foreign(['fk_grantor_owner_id', 'fk_grantor_profile_id'])
                ->references(['id_user', 'fk_profile_id'])
                ->on('garden_owners')
                ->cascadeOnDelete();

            $table->foreign(['fk_recipient_owner_id', 'fk_recipient_profile_id'])
                ->references(['id_user', 'fk_profile_id'])
                ->on('garden_owners')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_rights');
    }
};
