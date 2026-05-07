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
use App\Services\Plot\RotationPlannerService;
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
        $this->assertSame(2, $secondCounts['plots']);
        $this->assertSame(16, $secondCounts['zones']);
        $this->assertSame(27, $secondCounts['plants']);
        $this->assertSame(31, $secondCounts['catalog_plants']);
        $this->assertSame(12, $secondCounts['inventory_items']);
        $this->assertSame(2, $secondCounts['shared_access_records']);
        $this->assertSame(1, $secondCounts['rotation_drafts']);
        $this->assertSame(8, $secondCounts['harvest_records']);
        $this->assertGreaterThanOrEqual(15, $secondCounts['condition_history_records']);
        $this->assertGreaterThanOrEqual(18, $secondCounts['tasks']);
        $this->assertDemoRotationPlansAreUsable();

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
                ->where('canonical_name', 'garlic')
                ->whereDoesntHave('plants')
            ->exists()
        );
    }

    private function assertDemoRotationPlansAreUsable(): void
    {
        $planner = app(RotationPlannerService::class);
        $primary = Plot::query()
            ->where('name', 'Oakridge Kitchen Garden')
            ->firstOrFail();
        $secondary = Plot::query()
            ->where('name', 'South Fence Berry and Orchard Strip')
            ->firstOrFail();

        $primaryPlan = $planner->evaluatePlot($primary, now()->toDateString());
        $secondaryPlan = $planner->evaluatePlot($secondary, now()->toDateString());

        $this->assertSame('ready', $primaryPlan['status']);
        $this->assertGreaterThan(0, $primaryPlan['summary']['annual_plant_count']);
        $this->assertSame(0, $primaryPlan['summary']['unresolved_plant_count']);
        $this->assertGreaterThan(0, $primaryPlan['summary']['assigned_plant_count']);

        $this->assertSame('ready', $secondaryPlan['status']);
        $this->assertSame(0, $secondaryPlan['summary']['annual_plant_count']);
        $this->assertSame(4, $secondaryPlan['summary']['permanent_plant_count']);
        $this->assertSame(0, $secondaryPlan['summary']['unresolved_plant_count']);
        $this->assertTrue(collect($secondaryPlan['plants'])->every(
            fn (array $entry): bool => ($entry['is_rotatable'] ?? true) === false
                && ($entry['exclusion_reason'] ?? '') !== ''
        ));
    }

    /**
     * @return array<string, int>
     */
    private function demoCounts(): array
    {
        $demoUser = User::query()
            ->where('email', 'demo.owner@example.test')
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
                ->where('source_provider', 'current-version-demo')
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
