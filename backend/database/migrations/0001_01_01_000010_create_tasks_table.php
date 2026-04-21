<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name');
            $table->text('comment')->nullable();
            $table->string('item')->nullable();
            $table->decimal('item_quantity', 10, 2)->nullable();
            $table->foreignId('fk_task_calendar_id')->constrained('task_calendars')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
