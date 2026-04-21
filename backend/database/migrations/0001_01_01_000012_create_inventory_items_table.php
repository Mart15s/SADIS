<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('quantity', 10, 2);
            $table->string('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
