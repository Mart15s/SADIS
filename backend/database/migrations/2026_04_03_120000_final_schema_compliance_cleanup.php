<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->alignGardenOwners();
        $this->normalizeTaskStructure();
        $this->preparePostgreSqlConstraints();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check_spec');
        DB::statement('ALTER TABLE access_rights DROP CONSTRAINT IF EXISTS access_rights_role_check_spec');
        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_type_check_spec');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_state_check_spec');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check_spec');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_task_type_check_spec');
        DB::statement('ALTER TABLE garden_owners DROP CONSTRAINT IF EXISTS garden_owners_id_user_sync_spec');
    }

    private function alignGardenOwners(): void
    {
        DB::table('garden_owners')
            ->whereNull('user_id')
            ->update(['user_id' => DB::raw('COALESCE(id, id_user)')]);

        DB::table('garden_owners')
            ->whereNull('id')
            ->update(['id' => DB::raw('COALESCE(user_id, id_user)')]);

        DB::table('garden_owners')
            ->whereNull('id_user')
            ->update(['id_user' => DB::raw('COALESCE(user_id, id)')]);

        DB::table('profiles')
            ->whereNull('user_id')
            ->whereExists(function ($query) {
                $query
                    ->select(DB::raw(1))
                    ->from('garden_owners')
                    ->whereColumn('garden_owners.fk_profile_id', 'profiles.id');
            })
            ->update([
                'user_id' => DB::raw('(SELECT garden_owners.user_id FROM garden_owners WHERE garden_owners.fk_profile_id = profiles.id LIMIT 1)'),
            ]);

        DB::table('plots')
            ->whereNull('garden_owner_id')
            ->whereExists(function ($query) {
                $query
                    ->select(DB::raw(1))
                    ->from('has_plot')
                    ->whereColumn('has_plot.fk_plot_id', 'plots.id');
            })
            ->update([
                'garden_owner_id' => DB::raw('(SELECT MIN(has_plot.fk_owner_id) FROM has_plot WHERE has_plot.fk_plot_id = plots.id)'),
            ]);

        DB::table('inventory_items')
            ->whereNull('garden_owner_id')
            ->whereExists(function ($query) {
                $query
                    ->select(DB::raw(1))
                    ->from('has_inventory')
                    ->whereColumn('has_inventory.fk_inventory_item_id', 'inventory_items.id');
            })
            ->update([
                'garden_owner_id' => DB::raw('(SELECT MIN(has_inventory.fk_owner_id) FROM has_inventory WHERE has_inventory.fk_inventory_item_id = inventory_items.id)'),
            ]);
    }

    private function normalizeTaskStructure(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'task_calendar_id')) {
                $table->foreignId('task_calendar_id')->nullable()->constrained('task_calendars');
            }

            if (! Schema::hasColumn('tasks', 'plant_id')) {
                $table->foreignId('plant_id')->nullable()->constrained('plants')->nullOnDelete();
            }

            if (! Schema::hasColumn('tasks', 'plant_zone_id')) {
                $table->foreignId('plant_zone_id')->nullable()->constrained('plant_zones')->nullOnDelete();
            }

            if (! Schema::hasColumn('tasks', 'state')) {
                $table->string('state')->nullable();
            }

            if (! Schema::hasColumn('tasks', 'task_type')) {
                $table->string('task_type')->nullable();
            }
        });

        DB::statement("
            UPDATE tasks
            SET task_calendar_id = COALESCE(task_calendar_id, fk_task_calendar_id),
                plant_id = COALESCE(plant_id, fk_plant_id),
                state = CASE
                    WHEN LOWER(COALESCE(state, status, 'pending')) = 'completed' THEN 'completed'
                    WHEN LOWER(COALESCE(state, status, 'pending')) IN ('canceled', 'cancelled') THEN 'canceled'
                    ELSE 'pending'
                END,
                status = CASE
                    WHEN LOWER(COALESCE(state, status, 'pending')) = 'completed' THEN 'completed'
                    WHEN LOWER(COALESCE(state, status, 'pending')) IN ('canceled', 'cancelled') THEN 'canceled'
                    ELSE 'pending'
                END,
                task_type = CASE
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'fertilize' THEN 'fertilize'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'harvest' THEN 'harvest'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'planting' THEN 'planting'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'rest' THEN 'rest'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) IN ('spray', 'pest_check') THEN 'spray'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'transplant' THEN 'transplant'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'watering' THEN 'watering'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'weather_protection' THEN 'rest'
                    ELSE 'rest'
                END,
                type = CASE
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'fertilize' THEN 'fertilize'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'harvest' THEN 'harvest'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'planting' THEN 'planting'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'rest' THEN 'rest'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) IN ('spray', 'pest_check') THEN 'spray'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'transplant' THEN 'transplant'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'watering' THEN 'watering'
                    WHEN LOWER(COALESCE(task_type, type, 'rest')) = 'weather_protection' THEN 'rest'
                    ELSE 'rest'
                END
        ");

        DB::statement("
            UPDATE tasks
            SET plant_zone_id = COALESCE(
                plant_zone_id,
                (SELECT plants.plant_zone_id FROM plants WHERE plants.id = tasks.plant_id),
                (SELECT MIN(used_on.fk_plant_zone_id) FROM used_on WHERE used_on.fk_task_id = tasks.id)
            )
        ");
    }

    private function preparePostgreSqlConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! DB::table('garden_owners')->whereNull('id')->exists()) {
            DB::statement('ALTER TABLE garden_owners ALTER COLUMN id SET NOT NULL');
        }

        if (! DB::table('garden_owners')->whereNull('user_id')->exists()) {
            DB::statement('ALTER TABLE garden_owners ALTER COLUMN user_id SET NOT NULL');
        }

        if (! DB::table('plots')->whereNull('garden_owner_id')->exists()) {
            DB::statement('ALTER TABLE plots ALTER COLUMN garden_owner_id SET NOT NULL');
        }

        if (! DB::table('inventory_items')->whereNull('garden_owner_id')->exists()) {
            DB::statement('ALTER TABLE inventory_items ALTER COLUMN garden_owner_id SET NOT NULL');
        }

        if (! DB::table('tasks')->whereNull('task_calendar_id')->exists()) {
            DB::statement('ALTER TABLE tasks ALTER COLUMN task_calendar_id SET NOT NULL');
        }

        if (! DB::table('tasks')->whereNull('state')->exists()) {
            DB::statement('ALTER TABLE tasks ALTER COLUMN state SET NOT NULL');
        }

        if (! DB::table('tasks')->whereNull('task_type')->exists()) {
            DB::statement('ALTER TABLE tasks ALTER COLUMN task_type SET NOT NULL');
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check_spec');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check_spec CHECK (role IN ('owner', 'admin'))");

        DB::statement('ALTER TABLE access_rights DROP CONSTRAINT IF EXISTS access_rights_role_check_spec');
        DB::statement("ALTER TABLE access_rights ADD CONSTRAINT access_rights_role_check_spec CHECK (role IN ('viewer', 'editor'))");

        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_type_check_spec');
        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_type_check_spec CHECK (inventory_item_type IN ('material', 'tool'))");

        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_state_check_spec');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_state_check_spec CHECK (state IN ('pending', 'completed', 'canceled'))");

        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check_spec');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check_spec CHECK (status IN ('pending', 'completed', 'canceled'))");

        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_task_type_check_spec');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_task_type_check_spec CHECK (task_type IN ('fertilize', 'harvest', 'planting', 'rest', 'spray', 'transplant', 'watering'))");

        DB::statement('ALTER TABLE garden_owners DROP CONSTRAINT IF EXISTS garden_owners_id_user_sync_spec');
        DB::statement('ALTER TABLE garden_owners ADD CONSTRAINT garden_owners_id_user_sync_spec CHECK (id = user_id)');
    }
};
