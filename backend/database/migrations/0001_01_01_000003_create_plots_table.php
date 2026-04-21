<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plots', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city');
            $table->decimal('plot_size', 10, 2);
            $table->date('creation_date');
            $table->text('description')->nullable();
            $table->boolean('share')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plots');
    }
};
