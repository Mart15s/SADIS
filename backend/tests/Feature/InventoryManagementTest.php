<?php

namespace Tests\Feature;

use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Models\GardenOwner;
use App\Models\HasInventory;
use App\Models\HasPlot;
use App\Models\InventoryItem;
use App\Models\Plot;
use App\Models\Profile;
use App\Models\Task;
use App\Models\TaskCalendar;
use App\Models\TaskResourceRequirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_owner_can_list_only_their_inventory_items(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        [, $otherOwner] = $this->createGardenOwner('other@example.com');

        $ownedItem = $this->createInventoryItemForOwner($owner, [
            'name' => 'Kastuvas',
        ]);
        $this->createInventoryItemForOwner($otherOwner, [
            'name' => 'Svetimas purkstuvas',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/inventory');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedItem->id)
            ->assertJsonPath('data.0.name', 'Kastuvas');
    }

    public function test_authenticated_owner_can_create_an_inventory_item(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/inventory', [
            'name' => '  Trasos  ',
            'quantity' => 12.5,
            'type' => 'material',
            'unit' => 'kg',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Trasos')
            ->assertJsonPath('data.quantity', 12.5)
            ->assertJsonPath('data.type', 'material')
            ->assertJsonPath('data.unit', 'kg');

        $this->assertDatabaseHas('inventory_items', [
            'name' => 'Trasos',
            'quantity' => 12.5,
            'type' => 'material',
            'unit' => 'kg',
        ]);

        $itemId = $response->json('data.id');

        $this->assertDatabaseHas('inventory_items', [
            'id' => $itemId,
            'garden_owner_id' => $owner->id,
        ]);
    }

    public function test_creating_an_inventory_item_also_creates_the_owner_association(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/inventory', [
            'name' => 'Laistytuvas',
            'quantity' => 1,
            'type' => 'tool',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('inventory_items', [
            'id' => $response->json('data.id'),
            'garden_owner_id' => $owner->id,
        ]);
    }

    public function test_owner_cannot_see_another_owners_inventory_item(): void
    {
        [$user] = $this->createGardenOwner('owner@example.com');
        [, $otherOwner] = $this->createGardenOwner('other@example.com');
        $otherItem = $this->createInventoryItemForOwner($otherOwner);

        Sanctum::actingAs($user);

        $this->getJson("/api/inventory/{$otherItem->id}")
            ->assertNotFound();
    }

    public function test_owner_cannot_update_another_owners_inventory_item(): void
    {
        [$user] = $this->createGardenOwner('owner@example.com');
        [, $otherOwner] = $this->createGardenOwner('other@example.com');
        $otherItem = $this->createInventoryItemForOwner($otherOwner);

        Sanctum::actingAs($user);

        $this->patchJson("/api/inventory/{$otherItem->id}", [
            'name' => 'Bandymas',
        ])->assertNotFound();
    }

    public function test_owner_cannot_delete_another_owners_inventory_item(): void
    {
        [$user] = $this->createGardenOwner('owner@example.com');
        [, $otherOwner] = $this->createGardenOwner('other@example.com');
        $otherItem = $this->createInventoryItemForOwner($otherOwner);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/inventory/{$otherItem->id}")
            ->assertNotFound();
    }

    public function test_valid_update_changes_name_quantity_and_type_correctly(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $item = $this->createInventoryItemForOwner($owner, [
            'name' => 'Senos trasos',
            'quantity' => 4,
            'type' => InventoryItemType::Material,
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/inventory/{$item->id}", [
            'name' => 'Naujos trasos',
            'quantity' => 0,
            'type' => 'tool',
            'unit' => 'unit',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Naujos trasos')
            ->assertJsonPath('data.quantity', 0)
            ->assertJsonPath('data.type', 'tool')
            ->assertJsonPath('data.unit', 'unit');

        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'name' => 'Naujos trasos',
            'quantity' => 0,
            'type' => 'tool',
            'unit' => 'unit',
        ]);
    }

    public function test_task_context_overrides_manual_type_and_unit_with_requirement_metadata(): void
    {
        [$user, $owner, $plot] = $this->createOwnedPlotContext();
        Sanctum::actingAs($user);

        $task = $this->createTaskForPlot($plot, [
            'name' => 'Buy protective cover',
            'type' => 'buy',
            'task_type' => 'buy',
        ]);

        $requirement = TaskResourceRequirement::query()->create([
            'task_id' => $task->id,
            'resource_name' => 'Protective cover',
            'normalized_name' => 'protective cover',
            'inventory_item_type' => InventoryItemType::Tool,
            'unit' => InventoryUnit::Unit,
            'required_quantity' => 2,
            'shortage_quantity' => 2,
            'is_consumed' => false,
        ]);

        $response = $this->postJson('/api/inventory', [
            'name' => 'Protective cover',
            'quantity' => 2,
            'type' => 'material',
            'unit' => 'kg',
            'source_task_id' => $task->id,
            'source_requirement_id' => $requirement->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'tool')
            ->assertJsonPath('data.unit', 'unit');

        $this->assertDatabaseHas('inventory_items', [
            'garden_owner_id' => $owner->id,
            'name' => 'Protective cover',
            'type' => 'tool',
            'unit' => 'unit',
        ]);
    }

    public function test_delete_removes_item_and_owner_association_for_owned_item(): void
    {
        [$user, $owner] = $this->createGardenOwner('owner@example.com');
        $item = $this->createInventoryItemForOwner($owner);

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/inventory/{$item->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Inventoriaus irasas sekmingai pasalintas');

        $this->assertDatabaseMissing('inventory_items', [
            'id' => $item->id,
        ]);
    }

    public function test_validation_fails_for_missing_name(): void
    {
        [$user] = $this->createGardenOwner('owner@example.com');
        Sanctum::actingAs($user);

        $this->postJson('/api/inventory', [
            'quantity' => 1,
            'type' => 'material',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_validation_fails_for_negative_quantity(): void
    {
        [$user] = $this->createGardenOwner('owner@example.com');
        Sanctum::actingAs($user);

        $this->postJson('/api/inventory', [
            'name' => 'Trasos',
            'quantity' => -1,
            'type' => 'material',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_validation_fails_for_invalid_type(): void
    {
        [$user] = $this->createGardenOwner('owner@example.com');
        Sanctum::actingAs($user);

        $this->postJson('/api/inventory', [
            'name' => 'Trasos',
            'quantity' => 5,
            'type' => 'seed',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_validation_fails_for_tool_with_non_unit_measurement(): void
    {
        [$user] = $this->createGardenOwner('owner@example.com');
        Sanctum::actingAs($user);

        $this->postJson('/api/inventory', [
            'name' => 'Kastuvas',
            'quantity' => 1,
            'type' => 'tool',
            'unit' => 'kg',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['unit']);
    }

    public function test_inventory_remains_compatible_with_phase_five_task_completion(): void
    {
        [$user, $owner, $plot] = $this->createOwnedPlotContext();
        $item = $this->createInventoryItemForOwner($owner, [
            'name' => 'Trasos',
            'quantity' => 5,
            'type' => InventoryItemType::Material,
        ]);
        $task = $this->createTaskForPlot($plot, [
            'item' => 'Trasos',
            'item_quantity' => 2,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/tasks/{$task->id}/complete", [
            'materials_used' => [
                [
                    'name' => 'Trasos',
                    'quantity' => 2,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('task.status', 'completed');

        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'quantity' => 3,
        ]);

        $this->getJson('/api/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.quantity', 3);
    }

    private function createGardenOwner(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $profile = Profile::query()->create([
            'name' => 'Vardenis',
            'surname' => 'Pavardenis',
        ]);

        $owner = GardenOwner::query()->create([
            'id_user' => $user->id,
            'fk_profile_id' => $profile->id,
        ]);

        return [$user, $owner, $profile];
    }

    private function createInventoryItemForOwner(GardenOwner $owner, array $overrides = []): InventoryItem
    {
        $type = $overrides['inventory_item_type'] ?? $overrides['type'] ?? InventoryItemType::Material;
        $normalizedType = $type instanceof InventoryItemType ? $type : InventoryItemType::from((string) $type);
        $unit = $overrides['unit'] ?? ($normalizedType === InventoryItemType::Tool ? InventoryUnit::Unit : InventoryUnit::Kilogram);

        $item = InventoryItem::query()->create(array_merge([
            'garden_owner_id' => $owner->id,
            'name' => 'Trasos',
            'normalized_name' => 'trasos',
            'quantity' => 10,
            'type' => $normalizedType,
            'inventory_item_type' => $normalizedType,
            'unit' => $unit,
        ], $overrides));

        HasInventory::query()->create([
            'fk_inventory_item_id' => $item->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return $item;
    }

    private function createOwnedPlotContext(): array
    {
        [$user, $owner] = $this->createGardenOwner('inventory-owner@example.com');

        $plot = Plot::query()->create([
            'garden_owner_id' => $owner->id,
            'name' => 'Inventoriaus sklypas',
            'city' => 'Vilnius',
            'plot_size' => 80,
            'creation_date' => '2026-03-20',
            'description' => 'Bandymu sklypas',
            'share' => false,
        ]);

        HasPlot::query()->create([
            'fk_plot_id' => $plot->id,
            'fk_owner_id' => $owner->id_user,
            'fk_profile_id' => $owner->fk_profile_id,
        ]);

        return [$user, $owner, $plot];
    }

    private function createTaskForPlot(Plot $plot, array $overrides = []): Task
    {
        $calendar = TaskCalendar::query()->create([
            'creation_date' => now(),
            'start_date' => '2026-03-20',
            'end_date' => '2026-03-20',
            'fk_plot_id' => $plot->id,
        ]);

        return Task::query()->create(array_merge([
            'date' => '2026-03-20',
            'name' => 'Patresti lysve',
            'type' => 'fertilize',
            'status' => 'pending',
            'fk_task_calendar_id' => $calendar->id,
            'fk_plant_id' => null,
        ], $overrides));
    }
}
