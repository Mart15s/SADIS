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

        DB::statement("UPDATE tasks SET status = 'canceled' WHERE status = 'cancelled'");
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check_spec');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check_spec CHECK (status IN ('pending', 'completed', 'canceled'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("UPDATE tasks SET status = 'cancelled' WHERE status = 'canceled'");
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check_spec');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('pending', 'completed', 'cancelled'))");
    }
};
