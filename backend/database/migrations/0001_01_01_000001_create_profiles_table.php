<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('surname');
            $table->timestamp('last_login')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
