<?php

namespace App\Http\Controllers\Yava;

use App\Http\Controllers\Controller;
use App\Models\CommunityMembership;
use App\Models\FarmMembership;
use Illuminate\Http\Request;

class ContextController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $farms = FarmMembership::query()->with('farm')->where('user_id', $user->id)->where('status', 'active')->get()
            ->map(fn ($membership) => ['id' => $membership->farm_id, 'type' => 'farm', 'name' => $membership->farm->name, 'role' => $membership->role, 'timezone' => $membership->farm->timezone]);
        $communities = CommunityMembership::query()->with('community')->where('user_id', $user->id)->where('status', 'active')->get()
            ->map(fn ($membership) => ['id' => $membership->community_id, 'type' => 'community', 'name' => $membership->community->name, 'role' => $membership->role, 'timezone' => $membership->community->timezone]);

        return response()->json(['data' => $farms->concat($communities)->values()]);
    }
}
