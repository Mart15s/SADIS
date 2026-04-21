<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_task_type_check_spec');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_task_type_check_spec CHECK (task_type IN ('buy', 'fertilize', 'harvest', 'planting', 'rest', 'spray', 'transplant', 'watering'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_task_type_check_spec');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_task_type_check_spec CHECK (task_type IN ('fertilize', 'harvest', 'planting', 'rest', 'spray', 'transplant', 'watering'))");
    }
};
