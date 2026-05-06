<?php

namespace Tests\Feature;

use App\Models\CatalogPlant;
use App\Models\GardenOwner;
use App\Models\HarvestRecord;
use App\Models\InventoryItem;
use App\Models\Plant;
use App\Models\PlantConditionHistory;
use App\Models\PlantZone;
use App\Models\Plot;
use App\Models\RotationPlanDraft;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\FullFlowDemoAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FullFlowDemoAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_demo_seeder_is_safe_and_rerunnable(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'existing.owner@example.test',
        ]);

        Artisan::call('db:seed', [
            '--class' => FullFlowDemoAccountSeeder::class,
        ]);

        $firstCounts = $this->demoCounts();

        Artisan::call('db:seed', [
            '--class' => FullFlowDemoAccountSeeder::class,
        ]);

        $secondCounts = $this->demoCounts();

        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'email' => 'existing.owner@example.test',
        ]);

        $this->assertSame($firstCounts, $secondCounts);
        $this->assertSame(4, $secondCounts['plots']);
        $this->assertSame(8, $secondCounts['zones']);
        $this->assertSame(12, $secondCounts['plants']);
        $this->assertSame(11, $secondCounts['catalog_plants']);
        $this->assertSame(11, $secondCounts['inventory_items']);
        $this->assertSame(2, $secondCounts['shared_access_records']);
        $this->assertSame(1, $secondCounts['rotation_drafts']);
        $this->assertSame(3, $secondCounts['harvest_records']);
        $this->assertGreaterThanOrEqual(8, $secondCounts['condition_history_records']);
        $this->assertGreaterThan(0, $secondCounts['tasks']);

        $this->assertTrue(
            Task::query()
                ->where('task_type', 'buy')
                ->where('status', 'completed')
                ->exists()
        );

        $this->assertTrue(
            Task::query()
                ->where('task_type', 'buy')
                ->where('status', 'pending')
                ->exists()
        );

        $this->assertTrue(
            Task::query()
                ->where('status', 'canceled')
                ->exists()
        );

        $this->assertTrue(
            CatalogPlant::query()
                ->where('canonical_name', 'demo-krapai')
                ->whereDoesntHave('plants')
                ->exists()
        );
    }

    /**
     * @return array<string, int>
     */
    private function demoCounts(): array
    {
        $demoUser = User::query()
            ->where('email', 'demo.garden@example.test')
            ->firstOrFail();

        $demoOwner = GardenOwner::query()
            ->where('id', $demoUser->id)
            ->firstOrFail();

        $plotIds = Plot::query()
            ->where('garden_owner_id', $demoOwner->id)
            ->pluck('id');

        return [
            'plots' => $plotIds->count(),
            'zones' => PlantZone::query()->whereIn('plot_id', $plotIds)->count(),
            'plants' => Plant::query()->whereIn('fk_plot_id', $plotIds)->count(),
            'catalog_plants' => CatalogPlant::query()
                ->where('source_provider', 'demo')
                ->count(),
            'inventory_items' => InventoryItem::query()
                ->where('garden_owner_id', $demoOwner->id)
                ->count(),
            'tasks' => Task::query()
                ->whereHas('taskCalendar', fn ($query) => $query->whereIn('plot_id', $plotIds))
                ->count(),
            'harvest_records' => HarvestRecord::query()
                ->where('garden_owner_id', $demoOwner->id)
                ->count(),
            'condition_history_records' => PlantConditionHistory::query()
                ->whereIn('plant_id', Plant::query()->whereIn('fk_plot_id', $plotIds)->pluck('id'))
                ->count(),
            'shared_access_records' => \App\Models\AccessRight::query()
                ->whereIn('plot_id', $plotIds)
                ->count(),
            'rotation_drafts' => RotationPlanDraft::query()
                ->where('garden_owner_id', $demoOwner->id)
                ->count(),
        ];
    }
}
