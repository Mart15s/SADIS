<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendInventoryItems();
        $this->createTaskResourceRequirements();
        $this->createInventoryUsageLogs();
        $this->applyPostgreSqlConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_usage_logs');
        Schema::dropIfExists('task_resource_requirements');

        Schema::table('inventory_items', function (Blueprint $table) {
            foreach (['normalized_name', 'unit', 'minimum_quantity'] as $column) {
                if (Schema::hasColumn('inventory_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function extendInventoryItems(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_items', 'normalized_name')) {
                $table->string('normalized_name')->nullable()->after('name');
            }

            if (! Schema::hasColumn('inventory_items', 'unit')) {
                $table->string('unit')->nullable()->after('inventory_item_type');
            }

            if (! Schema::hasColumn('inventory_items', 'minimum_quantity')) {
                $table->decimal('minimum_quantity', 10, 2)->nullable()->after('quantity');
            }
        });

        DB::table('inventory_items')->update([
            'normalized_name' => DB::raw("LOWER(TRIM(name))"),
        ]);

        DB::table('inventory_items')
            ->whereNull('unit')
            ->update(['unit' => 'unit']);

        DB::table('inventory_items')
            ->whereNull('minimum_quantity')
            ->update(['minimum_quantity' => 0]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_items ALTER COLUMN normalized_name SET NOT NULL');
            DB::statement('ALTER TABLE inventory_items ALTER COLUMN unit SET NOT NULL');
            DB::statement('ALTER TABLE inventory_items ALTER COLUMN minimum_quantity SET NOT NULL');
        }
    }

    private function createTaskResourceRequirements(): void
    {
        if (Schema::hasTable('task_resource_requirements')) {
            return;
        }

        Schema::create('task_resource_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('resource_name');
            $table->string('normalized_name');
            $table->string('inventory_item_type');
            $table->string('unit');
            $table->decimal('required_quantity', 10, 2);
            $table->decimal('shortage_quantity', 10, 2)->default(0);
            $table->boolean('is_consumed')->default(true);

            $table->index(['task_id']);
            $table->index(['normalized_name', 'inventory_item_type', 'unit'], 'task_resource_requirements_lookup_idx');
        });
    }

    private function createInventoryUsageLogs(): void
    {
        if (Schema::hasTable('inventory_usage_logs')) {
            return;
        }

        Schema::create('inventory_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('task_resource_requirement_id')
                ->nullable()
                ->constrained('task_resource_requirements')
                ->nullOnDelete();
            $table->foreignId('garden_owner_id')->constrained('garden_owners')->cascadeOnDelete();
            $table->string('change_type');
            $table->decimal('quantity_before', 10, 2);
            $table->decimal('quantity_delta', 10, 2);
            $table->decimal('quantity_after', 10, 2);
            $table->string('unit');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['inventory_item_id', 'created_at']);
            $table->index(['task_id', 'created_at']);
        });
    }

    private function applyPostgreSqlConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_quantity_non_negative_spec');
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_quantity_non_negative_spec CHECK (quantity >= 0)');

        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_minimum_quantity_non_negative_spec');
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_minimum_quantity_non_negative_spec CHECK (minimum_quantity >= 0)');

        DB::statement('ALTER TABLE task_resource_requirements DROP CONSTRAINT IF EXISTS task_resource_requirements_item_type_spec');
        DB::statement("ALTER TABLE task_resource_requirements ADD CONSTRAINT task_resource_requirements_item_type_spec CHECK (inventory_item_type IN ('material', 'tool'))");

        DB::statement('ALTER TABLE task_resource_requirements DROP CONSTRAINT IF EXISTS task_resource_requirements_quantity_non_negative_spec');
        DB::statement('ALTER TABLE task_resource_requirements ADD CONSTRAINT task_resource_requirements_quantity_non_negative_spec CHECK (required_quantity > 0 AND shortage_quantity >= 0)');
    }
};
