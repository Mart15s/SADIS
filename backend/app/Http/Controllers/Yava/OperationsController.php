<?php

namespace App\Http\Controllers\Yava;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\CropSeason;
use App\Models\Farm;
use App\Models\FarmCommunityLink;
use App\Models\FarmMembership;
use App\Models\Field;
use App\Models\Recommendation;
use App\Models\ResourceReservation;
use App\Models\SharedResource;
use App\Models\StockItem;
use App\Models\WorkTask;
use App\Services\Yava\AnalyticsService;
use App\Services\Yava\InventoryMovementService;
use App\Services\Yava\PermissionService;
use App\Services\Yava\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationsController extends Controller
{
    public function tasks(Request $request, PermissionService $permissions)
    {
        $query = WorkTask::query();
        if ($request->filled('farm_id')) {
            $permissions->authorizeFarm($request->user(), $request->integer('farm_id'), 'view_farm');
            $query->where('farm_id', $request->integer('farm_id'));
        } elseif ($request->filled('community_id')) {
            $permissions->authorizeCommunity($request->user(), $request->integer('community_id'));
            $query->where('community_id', $request->integer('community_id'));
        } else {
            $query->where(fn ($q) => $q
                ->whereHas('farm.memberships', fn ($m) => $m->where('user_id', $request->user()->id)->where('status', 'active'))
                ->orWhereHas('community.memberships', fn ($m) => $m->where('user_id', $request->user()->id)->where('status', 'active')));
        }

        return response()->json(['data' => $query->orderByRaw('due_at IS NULL')->orderBy('due_at')->get()]);
    }

    public function storeTask(Request $request, PermissionService $permissions)
    {
        $data = $request->validate($this->taskRules());
        $this->requireOneScope($data);
        $this->authorizeScope($request, $permissions, $data, 'manage_tasks');
        $this->validateTaskScope($data);
        $task = WorkTask::query()->create($data + ['created_by_user_id' => $request->user()->id]);

        return response()->json(['data' => $task], 201);
    }

    public function showTask(Request $request, WorkTask $task, PermissionService $permissions)
    {
        $this->authorizeScope($request, $permissions, $task->toArray(), 'view_farm');

        return response()->json(['data' => $task]);
    }

    public function updateTask(Request $request, WorkTask $task, PermissionService $permissions)
    {
        $this->authorizeScope($request, $permissions, $task->toArray(), 'manage_tasks');
        $data = $request->validate($this->taskRules(true));
        foreach (['farm_id', 'community_id'] as $scope) {
            if (array_key_exists($scope, $data) && (int) ($data[$scope] ?? 0) !== (int) ($task->{$scope} ?? 0)) {
                throw ValidationException::withMessages([$scope => ['Tasks cannot be moved between farm or community scopes.']]);
            }
            unset($data[$scope]);
        }
        $this->validateTaskScope($data + $task->only(['farm_id', 'community_id', 'field_id', 'crop_season_id', 'assigned_to_user_id']));
        $task->update($data);

        return response()->json(['data' => $task->fresh()]);
    }

    public function completeTask(Request $request, WorkTask $task, PermissionService $permissions)
    {
        $this->authorizeScope($request, $permissions, $task->toArray(), 'manage_tasks');
        $task->update(['status' => 'completed', 'completed_at' => now()]);

        return response()->json(['data' => $task->fresh()]);
    }

    public function destroyTask(Request $request, WorkTask $task, PermissionService $permissions)
    {
        $this->authorizeScope($request, $permissions, $task->toArray(), 'manage_tasks');
        $task->delete();

        return response()->json(null, 204);
    }

    public function inventories(Request $request, PermissionService $permissions)
    {
        $query = StockItem::query()->with(['movements' => fn ($movement) => $movement->latest('occurred_at')->latest('id')]);
        if ($request->filled('farm_id')) {
            $permissions->authorizeFarm($request->user(), $request->integer('farm_id'), 'view_farm');
            $query->where('farm_id', $request->integer('farm_id'));
        } elseif ($request->filled('community_id')) {
            $permissions->authorizeCommunity($request->user(), $request->integer('community_id'));
            $query->where('community_id', $request->integer('community_id'));
        } else {
            abort(422, 'farm_id or community_id is required.');
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function storeInventory(Request $request, PermissionService $permissions)
    {
        $data = $request->validate($this->inventoryRules());
        $this->requireOneScope($data);
        $this->authorizeScope($request, $permissions, $data, 'manage_inventory');

        return response()->json(['data' => StockItem::query()->create($data)], 201);
    }

    public function showInventory(Request $request, StockItem $inventory, PermissionService $permissions)
    {
        $this->authorizeScope($request, $permissions, $inventory->toArray(), 'view_farm');

        return response()->json(['data' => $inventory->load('movements')]);
    }

    public function updateInventory(Request $request, StockItem $inventory, PermissionService $permissions)
    {
        $this->authorizeScope($request, $permissions, $inventory->toArray(), 'manage_inventory');
        $data = $request->validate($this->inventoryRules(true));
        if (array_key_exists('quantity', $data)
            && abs((float) $data['quantity'] - (float) $inventory->quantity) > 0.000001) {
            throw ValidationException::withMessages([
                'quantity' => ['Record an inventory movement to change the available quantity.'],
            ]);
        }
        unset($data['quantity']);
        foreach (['farm_id', 'community_id'] as $scope) {
            if (array_key_exists($scope, $data) && (int) ($data[$scope] ?? 0) !== (int) ($inventory->{$scope} ?? 0)) {
                throw ValidationException::withMessages([$scope => ['Inventory cannot be moved between farm or community scopes.']]);
            }
            unset($data[$scope]);
        }
        $inventory->update($data);

        return response()->json(['data' => $inventory->fresh()]);
    }

    public function destroyInventory(Request $request, StockItem $inventory, PermissionService $permissions)
    {
        $this->authorizeScope($request, $permissions, $inventory->toArray(), 'manage_inventory');
        abort_if($inventory->movements()->exists(), 422, 'Inventory with movement history cannot be deleted.');
        $inventory->delete();

        return response()->json(null, 204);
    }

    public function movement(Request $request, StockItem $inventory, PermissionService $permissions, InventoryMovementService $service)
    {
        $this->authorizeScope($request, $permissions, $inventory->toArray(), 'manage_inventory');
        $data = $request->validate([
            'type' => ['required', 'in:receipt,issue,consumption,return,adjustment_in,adjustment_out'],
            'quantity' => ['required', 'numeric', 'gt:0'], 'work_task_id' => ['nullable', 'exists:work_tasks,id'],
            'field_id' => ['nullable', 'exists:fields,id'], 'crop_season_id' => ['nullable', 'exists:crop_seasons,id'],
            'notes' => ['nullable', 'string'], 'occurred_at' => ['sometimes', 'date'],
        ]);
        if (! empty($data['work_task_id'])) {
            $task = WorkTask::findOrFail($data['work_task_id']);
            abort_unless((int) ($task->farm_id ?? 0) === (int) ($inventory->farm_id ?? 0)
                && (int) ($task->community_id ?? 0) === (int) ($inventory->community_id ?? 0), 422,
                'The linked task must belong to the same inventory scope.');
        }
        if (! empty($data['field_id'])) {
            abort_unless($inventory->farm_id
                && Field::query()->where('farm_id', $inventory->farm_id)->whereKey($data['field_id'])->exists(), 422,
                'The field must belong to the inventory farm.');
        }
        if (! empty($data['crop_season_id'])) {
            $season = CropSeason::findOrFail($data['crop_season_id']);
            abort_unless($inventory->farm_id && (int) $season->farm_id === (int) $inventory->farm_id
                && (empty($data['field_id']) || (int) $season->field_id === (int) $data['field_id']), 422,
                'The crop season must belong to the inventory farm and selected field.');
        }

        return response()->json(['data' => $service->record($request->user(), $inventory, $data)], 201);
    }

    public function storeMovement(Request $request, PermissionService $permissions, InventoryMovementService $service)
    {
        $request->validate(['inventory_id' => ['required', 'exists:stock_items,id']]);

        return $this->movement($request, StockItem::findOrFail($request->integer('inventory_id')), $permissions, $service);
    }

    public function resources(Request $request, PermissionService $permissions)
    {
        $data = $request->validate([
            'community_id' => ['nullable', 'integer', 'exists:communities,id'],
            'farm_id' => ['nullable', 'integer', 'exists:farms,id'],
        ]);
        if ((isset($data['community_id']) ? 1 : 0) + (isset($data['farm_id']) ? 1 : 0) !== 1) {
            throw ValidationException::withMessages(['scope' => ['Choose exactly one farm or community.']]);
        }

        $query = SharedResource::query();
        if (isset($data['community_id'])) {
            $permissions->authorizeCommunity($request->user(), (int) $data['community_id']);
            $query->where('community_id', $data['community_id']);
        } else {
            $permissions->authorizeFarm($request->user(), (int) $data['farm_id'], 'view_farm');
            $communityIds = FarmCommunityLink::query()
                ->where('farm_id', $data['farm_id'])->where('status', 'active')->pluck('community_id');
            $query->whereIn('community_id', $communityIds);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function storeResource(Request $request, PermissionService $permissions)
    {
        $data = $request->validate($this->resourceRules());
        $permissions->authorizeCommunity($request->user(), (int) $data['community_id'], 'manage_resources');

        return response()->json(['data' => SharedResource::query()->create($data + ['created_by_user_id' => $request->user()->id])], 201);
    }

    public function showResource(Request $request, SharedResource $resource, PermissionService $permissions)
    {
        $this->authorizeResourceView($request, $permissions, $resource);

        return response()->json(['data' => $resource]);
    }

    public function updateResource(Request $request, SharedResource $resource, PermissionService $permissions)
    {
        $permissions->authorizeCommunity($request->user(), $resource->community_id, 'manage_resources');
        $data = $request->validate($this->resourceRules(true));
        if (isset($data['community_id']) && (int) $data['community_id'] !== (int) $resource->community_id) {
            throw ValidationException::withMessages(['community_id' => ['Resources cannot be moved between communities.']]);
        }
        unset($data['community_id']);
        $resource->update($data);

        return response()->json(['data' => $resource->fresh()]);
    }

    public function destroyResource(Request $request, SharedResource $resource, PermissionService $permissions)
    {
        $permissions->authorizeCommunity($request->user(), $resource->community_id, 'manage_resources');
        abort_if(ResourceReservation::query()->where('shared_resource_id', $resource->id)->exists(), 422,
            'A resource with reservation history cannot be deleted. Retire it instead.');
        $resource->delete();

        return response()->json(null, 204);
    }

    public function reservations(Request $request, PermissionService $permissions)
    {
        $query = ResourceReservation::query()->with('resource');
        if ($request->filled('resource_id')) {
            $resource = SharedResource::findOrFail($request->integer('resource_id'));
            $this->authorizeResourceView($request, $permissions, $resource);
            $query->where('shared_resource_id', $resource->id);
            if (! $permissions->hasCommunityPermission($request->user(), $resource->community_id, 'manage_resources')) {
                $query->where('requested_by_user_id', $request->user()->id);
            }
        } elseif ($request->filled('community_id')) {
            $communityId = $request->integer('community_id');
            $permissions->authorizeCommunity($request->user(), $communityId);
            $query->whereHas('resource', fn ($resource) => $resource->where('community_id', $communityId));
            if (! $permissions->hasCommunityPermission($request->user(), $communityId, 'manage_resources')) {
                $query->where('requested_by_user_id', $request->user()->id);
            }
        } else {
            $query->where('requested_by_user_id', $request->user()->id);
        }

        return response()->json(['data' => $query->orderByDesc('starts_at')->get()]);
    }

    public function storeReservation(Request $request, PermissionService $permissions, ReservationService $service)
    {
        $data = $request->validate([
            'resource_id' => ['required', 'exists:shared_resources,id'], 'farm_id' => ['nullable', 'exists:farms,id'],
            'field_id' => ['nullable', 'exists:fields,id'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date'], 'purpose' => ['nullable', 'string', 'max:1000'],
        ]);
        $resource = SharedResource::findOrFail($data['resource_id']);
        abort_unless($resource->status === 'available', 422, 'Only available resources can be reserved.');
        if (isset($data['farm_id'])) {
            $permissions->authorizeFarm($request->user(), (int) $data['farm_id'], 'view_farm');
            abort_unless(FarmCommunityLink::query()
                ->where('farm_id', $data['farm_id'])
                ->where('community_id', $resource->community_id)
                ->where('status', 'active')->exists(), 422,
                'The requesting farm must have an active link to the resource community.');
        } else {
            $permissions->authorizeCommunity($request->user(), $resource->community_id);
        }
        if (isset($data['field_id'])) {
            abort_unless(isset($data['farm_id'])
                && Field::query()->where('farm_id', $data['farm_id'])->whereKey($data['field_id'])->exists(), 422,
                'The field must belong to the requesting farm.');
        }

        return response()->json(['data' => $service->request($request->user(), $resource, $data)], 201);
    }

    public function showReservation(Request $request, ResourceReservation $reservation, PermissionService $permissions)
    {
        $resource = SharedResource::withTrashed()->findOrFail($reservation->shared_resource_id);
        if ($reservation->requested_by_user_id !== $request->user()->id) {
            $permissions->authorizeCommunity($request->user(), $resource->community_id, 'manage_resources');
        }

        return response()->json(['data' => $reservation]);
    }

    public function reservationTransition(Request $request, ResourceReservation $reservation, string $transition, PermissionService $permissions, ReservationService $service)
    {
        $resource = SharedResource::withTrashed()->findOrFail($reservation->shared_resource_id);
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:1000']])['notes'] ?? null;
        if (in_array($transition, ['approve', 'reject'], true)) {
            $permissions->authorizeCommunity($request->user(), $resource->community_id, 'manage_resources');
            $result = $service->decide($request->user(), $reservation, $transition === 'approve' ? 'approved' : 'rejected', $notes);
        } elseif ($transition === 'cancel') {
            abort_unless($reservation->requested_by_user_id === $request->user()->id || $permissions->hasCommunityPermission($request->user(), $resource->community_id, 'manage_resources'), 403);
            abort_unless(in_array($reservation->status, ['pending', 'approved'], true), 422, 'Only pending or approved reservations can be cancelled.');
            $reservation->update(['status' => 'cancelled', 'decision_notes' => $notes]);
            $result = $reservation->fresh();
        } elseif ($transition === 'complete') {
            $permissions->authorizeCommunity($request->user(), $resource->community_id, 'manage_resources');
            abort_unless($reservation->status === 'approved', 422, 'Only approved reservations can be completed.');
            $reservation->update(['status' => 'completed', 'decided_by_user_id' => $request->user()->id, 'decided_at' => now()]);
            $result = $reservation->fresh();
        } else {
            abort(404);
        }

        return response()->json(['data' => $result]);
    }

    public function recommendations(Request $request, PermissionService $permissions)
    {
        $farmId = $request->integer('farm_id');
        abort_unless($farmId, 422, 'farm_id is required.');
        $permissions->authorizeFarm($request->user(), $farmId, 'view_farm');

        return response()->json(['data' => Recommendation::query()->where('farm_id', $farmId)->where('status', 'active')->latest()->get()]);
    }

    public function planningHistory(Request $request, PermissionService $permissions)
    {
        $farmId = $request->integer('farm_id');
        abort_unless($farmId, 422, 'farm_id is required.');
        $permissions->authorizeFarm($request->user(), $farmId, 'view_farm');

        $history = DB::table('planning_history')
            ->leftJoin('fields', 'fields.id', '=', 'planning_history.field_id')
            ->where('planning_history.farm_id', $farmId)
            ->select([
                'planning_history.id', 'planning_history.field_id', 'fields.name as field_name',
                'planning_history.event', 'planning_history.subject_type', 'planning_history.subject_id',
                'planning_history.before', 'planning_history.after', 'planning_history.created_at',
            ])
            ->orderByDesc('planning_history.created_at')
            ->orderByDesc('planning_history.id')
            ->limit(100)
            ->get()
            ->map(function ($item): array {
                $payload = (array) $item;
                $payload['before'] = $item->before ? json_decode($item->before, true) : null;
                $payload['after'] = $item->after ? json_decode($item->after, true) : null;

                return $payload;
            });

        return response()->json(['data' => $history]);
    }

    public function farmAnalytics(Request $request, Farm $farm, PermissionService $permissions, AnalyticsService $analytics)
    {
        $permissions->authorizeFarm($request->user(), $farm, 'view_analytics');

        return response()->json(['data' => $analytics->farm($farm)]);
    }

    public function communityAnalytics(Request $request, Community $community, PermissionService $permissions, AnalyticsService $analytics)
    {
        $permissions->authorizeCommunity($request->user(), $community);

        return response()->json(['data' => $analytics->community($community)]);
    }

    private function authorizeScope(Request $request, PermissionService $permissions, array $data, string $farmPermission): void
    {
        if (! empty($data['farm_id'])) {
            $permissions->authorizeFarm($request->user(), (int) $data['farm_id'], $farmPermission);
        } elseif (! empty($data['community_id'])) {
            $permissions->authorizeCommunity($request->user(), (int) $data['community_id'], $farmPermission === 'view_farm' ? 'view' : $farmPermission);
        } else {
            throw ValidationException::withMessages(['scope' => ['A farm or community scope is required.']]);
        }
    }

    private function authorizeResourceView(Request $request, PermissionService $permissions, SharedResource $resource): void
    {
        if ($permissions->hasCommunityPermission($request->user(), $resource->community_id)) {
            return;
        }

        $hasLinkedFarm = FarmCommunityLink::query()
            ->where('community_id', $resource->community_id)
            ->where('status', 'active')
            ->whereHas('farm.memberships', fn ($query) => $query
                ->where('user_id', $request->user()->id)
                ->where('status', 'active'))
            ->exists();
        abort_unless($hasLinkedFarm, 403, 'You do not have permission to view this shared resource.');
    }

    private function requireOneScope(array $data): void
    {
        if ((isset($data['farm_id']) ? 1 : 0) + (isset($data['community_id']) ? 1 : 0) !== 1) {
            throw ValidationException::withMessages(['scope' => ['Choose exactly one farm or community.']]);
        }
    }

    private function validateTaskScope(array $data): void
    {
        $farmId = $data['farm_id'] ?? null;
        $communityId = $data['community_id'] ?? null;
        if (! empty($data['field_id'])) {
            abort_unless($farmId && Field::query()->where('farm_id', $farmId)->whereKey($data['field_id'])->exists(), 422, 'The field must belong to the task farm.');
        }
        if (! empty($data['crop_season_id'])) {
            $season = CropSeason::findOrFail($data['crop_season_id']);
            abort_unless($farmId && (int) $season->farm_id === (int) $farmId
                && (empty($data['field_id']) || (int) $season->field_id === (int) $data['field_id']), 422,
                'The crop season must belong to the task farm and field.');
        }
        if (! empty($data['assigned_to_user_id'])) {
            $valid = $farmId
                ? FarmMembership::query()->where('farm_id', $farmId)->where('user_id', $data['assigned_to_user_id'])->where('status', 'active')->exists()
                : CommunityMembership::query()->where('community_id', $communityId)->where('user_id', $data['assigned_to_user_id'])->where('status', 'active')->exists();
            abort_unless($valid, 422, 'The assignee must be an active member of the task scope.');
        }
        if (! empty($data['shared_resource_id'])) {
            $resource = SharedResource::findOrFail($data['shared_resource_id']);
            $valid = $communityId
                ? (int) $resource->community_id === (int) $communityId
                : FarmCommunityLink::query()->where('farm_id', $farmId)
                    ->where('community_id', $resource->community_id)->where('status', 'active')->exists();
            abort_unless($valid, 422, 'The shared resource must belong to the task community or an active linked community.');
        }
    }

    private function taskRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'farm_id' => ['nullable', 'exists:farms,id'], 'community_id' => ['nullable', 'exists:communities,id'],
            'field_id' => ['nullable', 'exists:fields,id'], 'crop_season_id' => ['nullable', 'exists:crop_seasons,id'],
            'assigned_to_user_id' => ['nullable', 'exists:users,id'], 'title' => [$required, 'string', 'max:255'],
            'task_type' => ['sometimes', 'in:sowing,planting,fertilizing,spraying,irrigation,ploughing,cultivation,weeding,mowing,harvesting,soil_testing,field_inspection,machinery_maintenance,custom'],
            'shared_resource_id' => ['nullable', 'exists:shared_resources,id'],
            'description' => ['nullable', 'string'], 'status' => ['sometimes', 'in:pending,in_progress,completed,cancelled'],
            'priority' => ['sometimes', 'in:low,medium,high,urgent'], 'starts_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'], 'materials' => ['nullable', 'string', 'max:5000'],
            'weather_warning' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function inventoryRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'farm_id' => ['nullable', 'exists:farms,id'], 'community_id' => ['nullable', 'exists:communities,id'],
            'name' => [$required, 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:100'],
            'quantity' => ['sometimes', 'numeric', 'min:0'], 'unit' => [$required, 'string', 'max:32'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function resourceRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'community_id' => [$required, 'exists:communities,id'], 'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'], 'type' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'in:available,maintenance,retired'], 'timezone' => ['sometimes', 'timezone:all'],
            'requires_approval' => ['sometimes', 'boolean'],
        ];
    }
}
