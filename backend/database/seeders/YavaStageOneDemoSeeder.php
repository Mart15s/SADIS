<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\CommunityInvitation;
use App\Models\CommunityJoinRequest;
use App\Models\CommunityMembership;
use App\Models\Crop;
use App\Models\CropConditionRecord;
use App\Models\CropHarvest;
use App\Models\CropSeason;
use App\Models\CropVariety;
use App\Models\Farm;
use App\Models\FarmCommunityLink;
use App\Models\FarmMembership;
use App\Models\Field;
use App\Models\FieldZone;
use App\Models\InventoryMovement;
use App\Models\Profile;
use App\Models\ResourceReservation;
use App\Models\SharedResource;
use App\Models\StockItem;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class YavaStageOneDemoSeeder extends Seeder
{
    private const PASSWORD = 'YavaDemo!2026';

    public function run(): void
    {
        DB::transaction(function (): void {
            $owner = $this->user('yava.owner@example.com', 'Asha', 'Patel', '+919876543210');
            $manager = $this->user('yava.manager@example.com', 'Ravi', 'Kumar', '+919876543211');
            $communityAdmin = $this->user('yava.community@example.com', 'Meera', 'Singh', '+919876543212');
            $viewer = $this->user('yava.viewer@example.com', 'Noor', 'Iyer', '+919876543213');
            $applicant = $this->user('yava.applicant@example.com', 'Leela', 'Das', '+919876543214');

            $community = Community::query()->updateOrCreate(['slug' => 'green-village-cooperative'], [
                'name' => 'Green Village Cooperative', 'description' => 'Shared equipment and agricultural knowledge.',
                'timezone' => 'Asia/Kolkata', 'country_code' => 'IN', 'state_code' => 'KA', 'district' => 'Mysuru',
                'created_by_user_id' => $communityAdmin->id,
            ]);
            foreach ([[$communityAdmin, 'admin'], [$owner, 'member'], [$manager, 'resource_manager'], [$viewer, 'member']] as [$user, $role]) {
                CommunityMembership::query()->updateOrCreate(['community_id' => $community->id, 'user_id' => $user->id], [
                    'role' => $role, 'status' => 'active', 'approved_by_user_id' => $communityAdmin->id, 'joined_at' => now(),
                ]);
            }
            CommunityInvitation::query()->updateOrCreate([
                'community_id' => $community->id, 'email' => 'invited.grower@example.com',
            ], [
                'invited_by_user_id' => $communityAdmin->id, 'role' => 'member',
                'code_hash' => hash('sha256', 'YAVA-DEMO-INVITE-2026'), 'status' => 'pending',
                'expires_at' => now()->addMonth(), 'accepted_at' => null,
            ]);
            CommunityJoinRequest::query()->updateOrCreate([
                'community_id' => $community->id, 'user_id' => $applicant->id,
            ], [
                'message' => 'I would like to join the shared equipment programme.',
                'status' => 'pending', 'decided_by_user_id' => null, 'decided_at' => null,
            ]);

            $farm = Farm::query()->updateOrCreate(['slug' => 'sunrise-organic-farm'], [
                'name' => 'Sunrise Organic Farm', 'area_square_metres' => 24500, 'timezone' => 'Asia/Kolkata',
                'country_code' => 'IN', 'state_code' => 'KA', 'district' => 'Mysuru', 'taluk' => 'Nanjangud',
                'created_by_user_id' => $owner->id,
            ]);
            FarmMembership::query()->updateOrCreate(['farm_id' => $farm->id, 'user_id' => $owner->id], ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
            FarmMembership::query()->updateOrCreate(['farm_id' => $farm->id, 'user_id' => $manager->id], ['role' => 'manager', 'status' => 'active', 'joined_at' => now()]);
            FarmMembership::query()->updateOrCreate(['farm_id' => $farm->id, 'user_id' => $viewer->id], ['role' => 'viewer', 'status' => 'active', 'joined_at' => now()]);
            FarmCommunityLink::query()->updateOrCreate(['farm_id' => $farm->id, 'community_id' => $community->id], [
                'status' => 'active', 'linked_by_user_id' => $owner->id, 'approved_by_user_id' => $communityAdmin->id,
                'analytics_scopes' => ['crop_summary', 'harvest_summary'], 'farm_access_permissions' => [],
                'requested_at' => now(), 'approved_at' => now(),
            ]);

            $secondFarm = Farm::query()->updateOrCreate(['slug' => 'riverside-market-garden'], [
                'name' => 'Riverside Market Garden', 'area_square_metres' => 9800, 'timezone' => 'Asia/Kolkata',
                'country_code' => 'IN', 'state_code' => 'KA', 'district' => 'Mysuru', 'taluk' => 'Tirumakudalu Narasipura',
                'created_by_user_id' => $owner->id,
            ]);
            FarmMembership::query()->updateOrCreate(['farm_id' => $secondFarm->id, 'user_id' => $owner->id], ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
            FarmCommunityLink::query()->updateOrCreate(['farm_id' => $secondFarm->id, 'community_id' => $community->id], [
                'status' => 'pending', 'linked_by_user_id' => $owner->id, 'approved_by_user_id' => null,
                'analytics_scopes' => ['crop_summary'], 'farm_access_permissions' => [],
                'requested_at' => now(), 'approved_at' => null, 'revoked_at' => null,
            ]);

            $field = Field::query()->updateOrCreate(['farm_id' => $farm->id, 'name' => 'North Field'], [
                'area_square_metres' => 12000, 'soil_type' => 'loam',
                'boundary' => ['type' => 'Polygon', 'coordinates' => [[[76.62, 12.30], [76.63, 12.30], [76.63, 12.31], [76.62, 12.30]]]],
            ]);
            $zone = FieldZone::query()->updateOrCreate(['field_id' => $field->id, 'name' => 'Block A'], [
                'area_square_metres' => 6000, 'colour' => '#EE6D23', 'is_whole_field' => false,
                'boundary' => ['type' => 'Polygon', 'coordinates' => [[[76.622, 12.302], [76.627, 12.302], [76.627, 12.307], [76.622, 12.302]]]],
            ]);
            Field::query()->updateOrCreate(['farm_id' => $secondFarm->id, 'name' => 'River Field'], [
                'area_square_metres' => 5200, 'soil_type' => 'sandy loam',
                'boundary' => ['type' => 'Polygon', 'coordinates' => [[[76.66, 12.25], [76.667, 12.25], [76.667, 12.257], [76.66, 12.25]]]],
            ]);
            $crop = Crop::query()->updateOrCreate(['farm_id' => $farm->id, 'name' => 'Finger Millet'], [
                'scientific_name' => 'Eleusine coracana', 'category' => 'cereal', 'is_global' => false, 'created_by_user_id' => $owner->id,
            ]);
            $variety = CropVariety::query()->updateOrCreate([
                'crop_id' => $crop->id, 'farm_id' => $farm->id, 'name' => 'GPU-28',
            ], [
                'description' => 'A locally common finger millet variety for the demo season.', 'is_global' => false,
            ]);
            $season = CropSeason::query()->updateOrCreate(['legacy_group_key' => 'demo-finger-millet-2026'], [
                'farm_id' => $farm->id, 'field_id' => $field->id, 'field_zone_id' => $zone->id,
                'crop_id' => $crop->id, 'crop_variety_id' => $variety->id, 'name' => 'Kharif Finger Millet', 'starts_on' => '2026-06-15',
                'expected_ends_on' => '2026-10-15', 'planted_area_square_metres' => 6000, 'status' => 'active',
                'created_by_user_id' => $owner->id,
            ]);
            CropConditionRecord::query()->updateOrCreate([
                'crop_season_id' => $season->id, 'notes' => 'Demo healthy crop inspection',
            ], [
                'recorded_by_user_id' => $manager->id, 'condition' => 'healthy', 'severity' => 1,
                'observations' => ['canopy' => 'even', 'pest_pressure' => 'low'], 'observed_at' => now()->subDays(2),
            ]);
            CropHarvest::query()->updateOrCreate([
                'crop_season_id' => $season->id, 'notes' => 'Demo early harvest record',
            ], [
                'recorded_by_user_id' => $owner->id, 'quantity' => 850, 'unit' => 'kg',
                'harvested_on' => '2026-08-01', 'quality_grade' => 'A',
            ]);
            WorkTask::query()->updateOrCreate(['farm_id' => $farm->id, 'title' => 'Inspect irrigation lines'], [
                'field_id' => $field->id, 'crop_season_id' => $season->id, 'assigned_to_user_id' => $manager->id,
                'created_by_user_id' => $owner->id, 'status' => 'pending', 'priority' => 'high', 'due_at' => now()->addDays(2),
            ]);
            WorkTask::query()->updateOrCreate(['farm_id' => $farm->id, 'title' => 'Prepare millet seed bed'], [
                'field_id' => $field->id, 'crop_season_id' => $season->id, 'assigned_to_user_id' => $manager->id,
                'created_by_user_id' => $owner->id, 'status' => 'completed', 'priority' => 'medium',
                'due_at' => now()->subDay(), 'completed_at' => now()->subDay(),
            ]);
            $farmStock = StockItem::query()->updateOrCreate(['farm_id' => $farm->id, 'name' => 'Neem oil'], [
                'category' => 'crop protection', 'quantity' => 25, 'unit' => 'litre', 'reorder_level' => 5,
            ]);
            InventoryMovement::query()->updateOrCreate([
                'stock_item_id' => $farmStock->id, 'notes' => 'Demo opening farm stock',
            ], [
                'actor_user_id' => $owner->id, 'field_id' => $field->id, 'crop_season_id' => $season->id,
                'type' => 'receipt', 'quantity' => 25, 'balance_after' => 25, 'occurred_at' => now()->subDays(3),
            ]);
            $communityStock = StockItem::query()->updateOrCreate([
                'community_id' => $community->id, 'name' => 'Tractor diesel',
            ], [
                'category' => 'fuel', 'quantity' => 80, 'unit' => 'litre', 'reorder_level' => 20,
            ]);
            InventoryMovement::query()->updateOrCreate([
                'stock_item_id' => $communityStock->id, 'notes' => 'Demo opening community stock',
            ], [
                'actor_user_id' => $communityAdmin->id, 'type' => 'receipt', 'quantity' => 80,
                'balance_after' => 80, 'occurred_at' => now()->subDays(3),
            ]);
            $resource = SharedResource::query()->updateOrCreate(['community_id' => $community->id, 'name' => 'Two-wheel tractor'], [
                'type' => 'equipment', 'status' => 'available', 'timezone' => 'Asia/Kolkata',
                'requires_approval' => true, 'created_by_user_id' => $communityAdmin->id,
            ]);
            $bookingStart = now()->addWeek()->startOfDay()->addHours(8);
            ResourceReservation::query()->updateOrCreate([
                'shared_resource_id' => $resource->id, 'purpose' => 'Approved demo booking',
            ], [
                'requested_by_user_id' => $owner->id, 'farm_id' => $farm->id, 'field_id' => $field->id,
                'status' => 'approved', 'starts_at' => $bookingStart, 'ends_at' => $bookingStart->copy()->addHours(4),
                'decided_by_user_id' => $communityAdmin->id, 'decided_at' => now(),
            ]);
            ResourceReservation::query()->updateOrCreate([
                'shared_resource_id' => $resource->id, 'purpose' => 'Pending non-overlapping demo',
            ], [
                'requested_by_user_id' => $owner->id, 'farm_id' => $farm->id, 'field_id' => $field->id,
                'status' => 'pending', 'starts_at' => $bookingStart->copy()->addHours(4),
                'ends_at' => $bookingStart->copy()->addHours(7), 'decided_by_user_id' => null, 'decided_at' => null,
            ]);
            ResourceReservation::query()->updateOrCreate([
                'shared_resource_id' => $resource->id, 'purpose' => 'Pending conflicting demo',
            ], [
                'requested_by_user_id' => $owner->id, 'farm_id' => $farm->id, 'field_id' => $field->id,
                'status' => 'pending', 'starts_at' => $bookingStart->copy()->addHours(2),
                'ends_at' => $bookingStart->copy()->addHours(5), 'decided_by_user_id' => null, 'decided_at' => null,
            ]);
        });

        $this->command?->info('Yava Stage 1 demo data is ready.');
        $this->command?->line('Demo password for all accounts: '.self::PASSWORD);
        $this->command?->line('Accounts: yava.owner@example.com, yava.manager@example.com, yava.community@example.com, yava.viewer@example.com, yava.applicant@example.com');
    }

    private function user(string $email, string $name, string $surname, string $phone): User
    {
        $user = User::query()->updateOrCreate(['email' => $email], [
            'password' => self::PASSWORD, 'role' => UserRole::Owner, 'locale' => 'en',
            'phone' => $phone, 'phone_verified_at' => now(), 'status' => 'active', 'deactivated_at' => null,
        ]);
        Profile::query()->updateOrCreate(['user_id' => $user->id], ['name' => $name, 'surname' => $surname]);

        return $user;
    }
}
