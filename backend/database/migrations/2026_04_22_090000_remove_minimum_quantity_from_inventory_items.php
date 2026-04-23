<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_items', 'minimum_quantity')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_minimum_quantity_non_negative_spec');
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('minimum_quantity');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_items', 'minimum_quantity')) {
            return;
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('minimum_quantity', 10, 2)->default(0)->after('quantity');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_minimum_quantity_non_negative_spec');
            DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_minimum_quantity_non_negative_spec CHECK (minimum_quantity >= 0)');
        }
    }
};
