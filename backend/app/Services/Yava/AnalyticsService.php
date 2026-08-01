<?php

namespace App\Services\Yava;

use App\Models\Community;
use App\Models\CropHarvest;
use App\Models\CropSeason;
use App\Models\Farm;
use App\Models\FarmCommunityLink;
use App\Models\Field;
use App\Models\StockItem;
use App\Models\WorkTask;

class AnalyticsService
{
    public function farm(Farm $farm): array
    {
        return [
            'farm_id' => $farm->id,
            'area_square_metres' => (float) $farm->area_square_metres,
            'fields' => Field::query()->where('farm_id', $farm->id)->count(),
            'active_crop_seasons' => CropSeason::query()->where('farm_id', $farm->id)->whereIn('status', ['planned', 'active'])->count(),
            'open_tasks' => WorkTask::query()->where('farm_id', $farm->id)->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'inventory_items' => StockItem::query()->where('farm_id', $farm->id)->count(),
            'harvest_quantity' => (float) CropHarvest::query()->whereHas('cropSeason', fn ($query) => $query->where('farm_id', $farm->id))->sum('quantity'),
        ];
    }

    public function community(Community $community): array
    {
        // Query only approved links and only approved aggregate scopes. Private
        // rows are never loaded and later filtered in the client.
        $links = FarmCommunityLink::query()->with('farm:id,name,area_square_metres,state_code,district')
            ->where('community_id', $community->id)->where('status', 'active')->get();

        return [
            'community_id' => $community->id,
            'farms' => $links->map(function (FarmCommunityLink $link): array {
                $minimum = [
                    'id' => $link->farm->id, 'name' => $link->farm->name,
                    'area_square_metres' => (float) $link->farm->area_square_metres,
                    'location' => array_filter(['state_code' => $link->farm->state_code, 'district' => $link->farm->district]),
                    'link_status' => $link->status,
                ];
                $scopes = $link->analytics_scopes ?? [];
                if (in_array('crop_summary', $scopes, true)) {
                    $minimum['active_crop_seasons'] = CropSeason::query()->where('farm_id', $link->farm_id)->where('status', 'active')->count();
                }
                if (in_array('harvest_summary', $scopes, true)) {
                    $minimum['harvest_quantity'] = (float) CropHarvest::query()->whereHas('cropSeason', fn ($q) => $q->where('farm_id', $link->farm_id))->sum('quantity');
                }

                return $minimum;
            })->values(),
        ];
    }
}
