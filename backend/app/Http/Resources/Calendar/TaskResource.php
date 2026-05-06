<?php

namespace App\Http\Resources\Calendar;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plant = $this->relationLoaded('plant') ? $this->plant : null;
        $taskStatus = $this->state?->value ?? $this->status;
        $isPending = $taskStatus === 'pending';
        $inventoryContext = $this->live_inventory_context ?? $this->inventory_context;
        $isReplenishmentTask = ($this->task_type ?? $this->type) === 'buy';
        $requiredResources = $isReplenishmentTask
            ? collect()
            : TaskResourceRequirementResource::collection($this->whenLoaded('requiredResources'));
        $actualCondition = $plant?->condition?->value ?? $plant?->condition;
        $lifecycleTransition = data_get($this->simulated_state, 'transition');

        if (! $lifecycleTransition && data_get($this->workflow_context, 'kind') === 'lifecycle_review') {
            $lifecycleTransition = [
                'from' => data_get($this->workflow_context, 'review.from_phase'),
                'to' => data_get($this->workflow_context, 'review.target_condition'),
                'is_transition_day' => true,
            ];
        }

        $zone = $this->relationLoaded('plantZone') && $this->plantZone
            ? $this->plantZone
            : ($plant?->relationLoaded('plantZone') && $plant->plantZone
                ? $plant->plantZone
                : ($this->relationLoaded('usedOn') ? $this->usedOn->first()?->plantZone : null));

        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'name' => $this->name,
            'type' => $this->task_type ?? $this->type,
            'task_type' => $this->task_type ?? $this->type,
            'priority' => $this->priority?->value ?? $this->priority,
            'reason' => $this->reason,
            'status' => $taskStatus,
            'state' => $taskStatus,
            'comment' => $this->comment,
            'item' => $this->item,
            'item_quantity' => $this->item_quantity === null ? null : (float) $this->item_quantity,
            'required_resources' => $requiredResources,
            'resource_requirements' => $requiredResources,
            'weather_context' => $this->weather_context,
            'inventory_context' => $inventoryContext,
            'inventory_mode' => $isPending
                ? ($inventoryContext['inventory_mode']
                    ?? ($isReplenishmentTask ? 'replenishment' : (($inventoryContext['status'] ?? null) === 'not_required' ? 'not_required' : ($inventoryContext['status'] ?? 'available'))))
                : 'not_required',
            'inventory_shortages' => $isPending && $isReplenishmentTask
                ? [[
                    'resource_name' => $this->item,
                    'shortage_quantity' => data_get($inventoryContext, 'shortage_quantity', $this->item_quantity === null ? null : (float) $this->item_quantity),
                    'unit' => data_get($inventoryContext, 'unit'),
                    'blocked_task_names' => data_get($inventoryContext, 'planned_for_tasks', []),
                    'blocked_task_count' => data_get($inventoryContext, 'planned_for_task_count'),
                ]]
                : ($inventoryContext['missing_resources'] ?? []),
            'is_replenishment_task' => $isReplenishmentTask,
            'can_complete' => $isPending && (bool) ($this->can_complete_now ?? ($inventoryContext['is_actionable'] ?? true)),
            'simulated_state' => $this->simulated_state,
            'simulated_phase' => data_get($this->simulated_state, 'simulated_phase'),
            'actual_condition' => $actualCondition,
            'lifecycle_transition' => $lifecycleTransition,
            'workflow_context' => $this->workflow_context,
            'plant_id' => $this->plant_id ?? $this->fk_plant_id,
            'plant_condition' => $actualCondition,
            'zone_id' => $zone?->id,
            'plant_name' => $plant?->name,
            'zone_name' => $zone?->name,
        ];
    }
}
