<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->alignUsers();
        $this->alignProfilesAndGardenOwners();
        $this->alignOwnershipColumns();
        $this->alignPlantStructures();
        $this->alignTaskStructures();
        $this->createPlotSnapshots();
    }

    public function down(): void
    {
        Schema::dropIfExists('plot_snapshots');
    }

    private function alignUsers(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        $now = now();

        DB::table('users')
            ->where(function ($query) {
                $query
                    ->whereNull('role')
                    ->orWhere('role', 'user');
            })
            ->update([
                'role' => 'owner',
                'created_at' => DB::raw("COALESCE(created_at, '{$now->toDateTimeString()}')"),
                'updated_at' => $now,
            ]);

        DB::table('users')
            ->whereNotIn('role', ['owner', 'admin'])
            ->update([
                'role' => 'owner',
                'updated_at' => $now,
            ]);

        DB::table('users')
            ->whereNull('created_at')
            ->update(['created_at' => $now]);

        DB::table('users')
            ->whereNull('updated_at')
            ->update(['updated_at' => $now]);
    }

    private function alignProfilesAndGardenOwners(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('profiles', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('garden_owners', function (Blueprint $table) {
            if (! Schema::hasColumn('garden_owners', 'id')) {
                $table->unsignedBigInteger('id')->nullable();
            }

            if (! Schema::hasColumn('garden_owners', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            }
        });

        $owners = DB::table('garden_owners')->get();

        foreach ($owners as $owner) {
            DB::table('garden_owners')
                ->where('id_user', $owner->id_user)
                ->where('fk_profile_id', $owner->fk_profile_id)
                ->update([
                    'id' => $owner->id_user,
                    'user_id' => $owner->id_user,
                ]);

            DB::table('profiles')
                ->where('id', $owner->fk_profile_id)
                ->update([
                    'user_id' => $owner->id_user,
                ]);
        }

        Schema::table('profiles', function (Blueprint $table) {
            $table->unique('user_id', 'profiles_user_id_unique_spec');
        });

        Schema::table('garden_owners', function (Blueprint $table) {
            $table->unique('id', 'garden_owners_id_unique_spec');
            $table->unique('user_id', 'garden_owners_user_id_unique_spec');
        });
    }

    private function alignOwnershipColumns(): void
    {
        Schema::table('plots', function (Blueprint $table) {
            if (! Schema::hasColumn('plots', 'garden_owner_id')) {
                $table->foreignId('garden_owner_id')->nullable()->constrained('garden_owners');
            }
        });

        $primaryOwners = DB::table('has_plot')
            ->select('fk_plot_id', DB::raw('MIN(fk_owner_id) AS owner_id'))
            ->groupBy('fk_plot_id')
            ->get();

        foreach ($primaryOwners as $primaryOwner) {
            DB::table('plots')
                ->where('id', $primaryOwner->fk_plot_id)
                ->update(['garden_owner_id' => $primaryOwner->owner_id]);
        }

        Schema::table('access_rights', function (Blueprint $table) {
            if (! Schema::hasColumn('access_rights', 'garden_owner_id')) {
                $table->foreignId('garden_owner_id')->nullable()->constrained('garden_owners');
            }

            if (! Schema::hasColumn('access_rights', 'plot_id')) {
                $table->foreignId('plot_id')->nullable()->constrained('plots');
            }
        });

        DB::table('access_rights')
            ->update([
                'garden_owner_id' => DB::raw('fk_recipient_owner_id'),
                'plot_id' => DB::raw('fk_plot_id'),
            ]);

        Schema::table('plant_zones', function (Blueprint $table) {
            if (! Schema::hasColumn('plant_zones', 'plot_id')) {
                $table->foreignId('plot_id')->nullable()->constrained('plots');
            }
        });

        DB::table('plant_zones')->update(['plot_id' => DB::raw('fk_plot_id')]);

        Schema::table('task_calendars', function (Blueprint $table) {
            if (! Schema::hasColumn('task_calendars', 'plot_id')) {
                $table->foreignId('plot_id')->nullable()->constrained('plots');
            }
        });

        DB::table('task_calendars')->update(['plot_id' => DB::raw('fk_plot_id')]);

        Schema::table('inventory_items', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_items', 'garden_owner_id')) {
                $table->foreignId('garden_owner_id')->nullable()->constrained('garden_owners');
            }

            if (! Schema::hasColumn('inventory_items', 'inventory_item_type')) {
                $table->string('inventory_item_type')->nullable();
            }
        });

        $inventoryOwners = DB::table('has_inventory')
            ->select('fk_inventory_item_id', DB::raw('MIN(fk_owner_id) AS owner_id'))
            ->groupBy('fk_inventory_item_id')
            ->get();

        foreach ($inventoryOwners as $inventoryOwner) {
            DB::table('inventory_items')
                ->where('id', $inventoryOwner->fk_inventory_item_id)
                ->update(['garden_owner_id' => $inventoryOwner->owner_id]);
        }

        DB::table('inventory_items')
            ->whereNull('inventory_item_type')
            ->update(['inventory_item_type' => DB::raw('type')]);

        Schema::table('community_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('community_posts', 'garden_owner_id')) {
                $table->foreignId('garden_owner_id')->nullable()->constrained('garden_owners');
            }

            if (! Schema::hasColumn('community_posts', 'plot_id')) {
                $table->foreignId('plot_id')->nullable()->constrained('plots')->nullOnDelete();
            }
        });

        DB::table('community_posts')->update([
            'garden_owner_id' => DB::raw('fk_owner_id'),
            'plot_id' => DB::raw('fk_plot_id'),
        ]);
    }

    private function alignPlantStructures(): void
    {
        if (Schema::hasColumn('plants', 'disease') && ! Schema::hasColumn('plants', 'disease_notes')) {
            Schema::table('plants', function (Blueprint $table) {
                $table->renameColumn('disease', 'disease_notes');
            });
        }

        Schema::table('plants', function (Blueprint $table) {
            if (! Schema::hasColumn('plants', 'disease')) {
                $table->boolean('disease')->default(false);
            }

            if (! Schema::hasColumn('plants', 'plant_zone_id')) {
                $table->foreignId('plant_zone_id')->nullable()->constrained('plant_zones');
            }

            if (! Schema::hasColumn('plants', 'photo_url')) {
                $table->string('photo_url')->nullable();
            }

            if (! Schema::hasColumn('plants', 'reusable')) {
                $table->boolean('reusable')->default(false);
            }
        });

        $plants = DB::table('plants')->get();

        foreach ($plants as $plant) {
            $hasDisease = false;
            $legacyValue = $plant->disease_notes ?? null;

            if ($legacyValue !== null) {
                $normalized = strtolower(trim((string) $legacyValue));
                $hasDisease = $normalized !== '' && ! in_array($normalized, ['0', 'false', 'no', 'healthy'], true);
            }

            $reusable = false;

            if (isset($plant->fk_plant_care_id) && $plant->fk_plant_care_id) {
                $care = DB::table('plant_care')->where('id', $plant->fk_plant_care_id)->first();
                $reusable = (bool) ($care->reusable ?? false);
            }

            DB::table('plants')
                ->where('id', $plant->id)
                ->update([
                    'disease' => $hasDisease,
                    'plant_zone_id' => $plant->fk_plant_zone_id,
                    'reusable' => $reusable,
                ]);
        }

        Schema::table('plant_condition_history', function (Blueprint $table) {
            if (! Schema::hasColumn('plant_condition_history', 'plant_id')) {
                $table->foreignId('plant_id')->nullable()->constrained('plants');
            }

            if (! Schema::hasColumn('plant_condition_history', 'condition_type')) {
                $table->string('condition_type')->nullable();
            }
        });

        DB::table('plant_condition_history')->update([
            'plant_id' => DB::raw('fk_plant_id'),
            'condition_type' => DB::raw('condition'),
        ]);

        Schema::table('rotation_history', function (Blueprint $table) {
            if (! Schema::hasColumn('rotation_history', 'plant_zone_id')) {
                $table->foreignId('plant_zone_id')->nullable()->constrained('plant_zones');
            }
        });

        DB::table('rotation_history')->update([
            'plant_zone_id' => DB::raw('fk_plant_zone_id'),
        ]);
    }

    private function alignTaskStructures(): void
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

        $taskZoneLinks = DB::table('used_on')
            ->select('fk_task_id', DB::raw('MIN(fk_plant_zone_id) AS plant_zone_id'))
            ->groupBy('fk_task_id')
            ->get()
            ->keyBy('fk_task_id');

        $tasks = DB::table('tasks')->get();

        foreach ($tasks as $task) {
            $zoneId = $taskZoneLinks[$task->id]->plant_zone_id ?? null;
            $legacyStatus = $task->status ?? null;
            $legacyType = $task->type ?? null;

            DB::table('tasks')
                ->where('id', $task->id)
                ->update([
                    'task_calendar_id' => $task->fk_task_calendar_id,
                    'plant_id' => $task->fk_plant_id ?? null,
                    'plant_zone_id' => $zoneId,
                    'state' => $legacyStatus === 'cancelled' ? 'canceled' : ($legacyStatus ?? 'pending'),
                    'task_type' => $legacyType,
                ]);
        }

        Schema::table('weather_forecasts', function (Blueprint $table) {
            if (! Schema::hasColumn('weather_forecasts', 'task_calendar_id')) {
                $table->foreignId('task_calendar_id')->nullable()->constrained('task_calendars')->nullOnDelete();
            }
        });

        DB::table('weather_forecasts')->update([
            'task_calendar_id' => DB::raw('fk_task_calendar_id'),
        ]);
    }

    private function createPlotSnapshots(): void
    {
        if (Schema::hasTable('plot_snapshots')) {
            return;
        }

        Schema::create('plot_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plot_id')->constrained('plots')->cascadeOnDelete();
            $table->foreignId('garden_owner_id')->nullable()->constrained('garden_owners')->nullOnDelete();
            $table->string('action');
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
    }
};
