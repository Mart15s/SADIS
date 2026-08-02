<?php

namespace Tests\Feature\Yava;

use App\Models\CommunityInvitation;
use App\Models\CommunityJoinRequest;
use App\Models\CropConditionRecord;
use App\Models\CropHarvest;
use App\Models\CropVariety;
use App\Models\Farm;
use App\Models\FarmCommunityLink;
use App\Models\FieldZone;
use App\Models\InventoryMovement;
use App\Models\ResourceReservation;
use App\Models\StockItem;
use App\Models\User;
use Database\Seeders\YavaStageOneDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class YavaStageOneDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_is_repeatable_and_contains_user_testing_scenarios(): void
    {
        $this->seed(YavaStageOneDemoSeeder::class);
        $this->seed(YavaStageOneDemoSeeder::class);

        $this->assertSame(2, Farm::query()->count());
        $this->assertSame(1, FarmCommunityLink::query()->where('status', 'active')->count());
        $this->assertSame(1, FarmCommunityLink::query()->where('status', 'pending')->count());
        $this->assertSame(1, CommunityInvitation::query()->where('status', 'pending')->count());
        $this->assertSame(1, CommunityJoinRequest::query()->where('status', 'pending')->count());
        $this->assertSame(1, CropVariety::query()->count());
        $this->assertSame(1, CropConditionRecord::query()->count());
        $this->assertSame(1, CropHarvest::query()->where('quantity', 850)->count());
        $this->assertSame(1, FieldZone::query()->whereNotNull('boundary')->count());
        $this->assertSame(1, StockItem::query()->whereNotNull('farm_id')->count());
        $this->assertSame(1, StockItem::query()->whereNotNull('community_id')->count());
        $this->assertSame(2, InventoryMovement::query()->count());
        $this->assertSame(3, ResourceReservation::query()->count());

        $approved = ResourceReservation::query()->where('purpose', 'Approved demo booking')->firstOrFail();
        $nonOverlapping = ResourceReservation::query()->where('purpose', 'Pending non-overlapping demo')->firstOrFail();
        $conflicting = ResourceReservation::query()->where('purpose', 'Pending conflicting demo')->firstOrFail();

        $this->assertSame('approved', $approved->status);
        $this->assertSame('pending', $nonOverlapping->status);
        $this->assertSame('pending', $conflicting->status);
        $this->assertTrue($approved->ends_at->equalTo($nonOverlapping->starts_at));
        $this->assertTrue($approved->starts_at->lessThan($conflicting->ends_at));
        $this->assertTrue($approved->ends_at->greaterThan($conflicting->starts_at));

        $viewer = User::query()->where('email', 'yava.viewer@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('YavaDemo!2026', $viewer->password));
        $this->assertDatabaseHas('farm_memberships', [
            'user_id' => $viewer->id, 'role' => 'viewer', 'status' => 'active',
        ]);
    }
}
