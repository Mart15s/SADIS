<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\Crop;
use App\Models\CropSeason;
use App\Models\Farm;
use App\Models\FarmCommunityLink;
use App\Models\FarmMembership;
use App\Models\Field;
use App\Models\FieldZone;
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
    public function run(): void
    {
        DB::transaction(function (): void {
            $owner = $this->user('yava.owner@example.com', 'Asha', 'Patel');
            $manager = $this->user('yava.manager@example.com', 'Ravi', 'Kumar');
            $communityAdmin = $this->user('yava.community@example.com', 'Meera', 'Singh');

            $community = Community::query()->updateOrCreate(['slug' => 'green-village-cooperative'], [
                'name' => 'Green Village Cooperative', 'description' => 'Shared equipment and agricultural knowledge.',
                'timezone' => 'Asia/Kolkata', 'country_code' => 'IN', 'state_code' => 'KA', 'district' => 'Mysuru',
                'created_by_user_id' => $communityAdmin->id,
            ]);
            foreach ([[$communityAdmin, 'admin'], [$owner, 'member'], [$manager, 'resource_manager']] as [$user, $role]) {
                CommunityMembership::query()->updateOrCreate(['community_id' => $community->id, 'user_id' => $user->id], [
                    'role' => $role, 'status' => 'active', 'approved_by_user_id' => $communityAdmin->id, 'joined_at' => now(),
                ]);
            }

            $farm = Farm::query()->updateOrCreate(['slug' => 'sunrise-organic-farm'], [
                'name' => 'Sunrise Organic Farm', 'area_square_metres' => 24500, 'timezone' => 'Asia/Kolkata',
                'country_code' => 'IN', 'state_code' => 'KA', 'district' => 'Mysuru', 'taluk' => 'Nanjangud',
                'created_by_user_id' => $owner->id,
            ]);
            FarmMembership::query()->updateOrCreate(['farm_id' => $farm->id, 'user_id' => $owner->id], ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
            FarmMembership::query()->updateOrCreate(['farm_id' => $farm->id, 'user_id' => $manager->id], ['role' => 'manager', 'status' => 'active', 'joined_at' => now()]);
            FarmCommunityLink::query()->updateOrCreate(['farm_id' => $farm->id, 'community_id' => $community->id], [
                'status' => 'active', 'linked_by_user_id' => $owner->id, 'approved_by_user_id' => $communityAdmin->id,
                'analytics_scopes' => ['crop_summary', 'harvest_summary'], 'farm_access_permissions' => [],
                'requested_at' => now(), 'approved_at' => now(),
            ]);

            $field = Field::query()->updateOrCreate(['farm_id' => $farm->id, 'name' => 'North Field'], [
                'area_square_metres' => 12000, 'soil_type' => 'loam',
                'boundary' => ['type' => 'Polygon', 'coordinates' => [[[76.62, 12.30], [76.63, 12.30], [76.63, 12.31], [76.62, 12.30]]]],
            ]);
            $zone = FieldZone::query()->updateOrCreate(['field_id' => $field->id, 'name' => 'Block A'], [
                'area_square_metres' => 6000, 'colour' => '#EE6D23', 'is_whole_field' => false,
            ]);
            $crop = Crop::query()->updateOrCreate(['farm_id' => $farm->id, 'name' => 'Finger Millet'], [
                'scientific_name' => 'Eleusine coracana', 'category' => 'cereal', 'is_global' => false, 'created_by_user_id' => $owner->id,
            ]);
            $season = CropSeason::query()->updateOrCreate(['legacy_group_key' => 'demo-finger-millet-2026'], [
                'farm_id' => $farm->id, 'field_id' => $field->id, 'field_zone_id' => $zone->id,
                'crop_id' => $crop->id, 'name' => 'Kharif Finger Millet', 'starts_on' => '2026-06-15',
                'expected_ends_on' => '2026-10-15', 'planted_area_square_metres' => 6000, 'status' => 'active',
                'created_by_user_id' => $owner->id,
            ]);
            WorkTask::query()->updateOrCreate(['farm_id' => $farm->id, 'title' => 'Inspect irrigation lines'], [
                'field_id' => $field->id, 'crop_season_id' => $season->id, 'assigned_to_user_id' => $manager->id,
                'created_by_user_id' => $owner->id, 'status' => 'pending', 'priority' => 'high', 'due_at' => now()->addDays(2),
            ]);
            StockItem::query()->updateOrCreate(['farm_id' => $farm->id, 'name' => 'Neem oil'], [
                'category' => 'crop protection', 'quantity' => 25, 'unit' => 'litre', 'reorder_level' => 5,
            ]);
            $resource = SharedResource::query()->updateOrCreate(['community_id' => $community->id, 'name' => 'Two-wheel tractor'], [
                'type' => 'equipment', 'status' => 'available', 'timezone' => 'Asia/Kolkata',
                'requires_approval' => true, 'created_by_user_id' => $communityAdmin->id,
            ]);
            ResourceReservation::query()->updateOrCreate([
                'shared_resource_id' => $resource->id, 'requested_by_user_id' => $owner->id,
                'starts_at' => now()->addWeek()->startOfDay(),
            ], [
                'farm_id' => $farm->id, 'status' => 'approved', 'ends_at' => now()->addWeek()->startOfDay()->addHours(4),
                'purpose' => 'Prepare the North Field', 'decided_by_user_id' => $communityAdmin->id, 'decided_at' => now(),
            ]);
        });
    }

    private function user(string $email, string $name, string $surname): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['password' => 'YavaDemo!2026', 'role' => UserRole::Owner, 'locale' => 'en']);
        Profile::query()->updateOrCreate(['user_id' => $user->id], ['name' => $name, 'surname' => $surname]);

        return $user;
    }
}
