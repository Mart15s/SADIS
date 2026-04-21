<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garden_owners', function (Blueprint $table) {
            $table->foreignId('id_user')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fk_profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->primary(['id_user', 'fk_profile_id']);
            $table->unique('id_user');
            $table->unique('fk_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garden_owners');
    }
};
