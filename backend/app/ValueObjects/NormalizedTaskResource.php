<?php

namespace App\ValueObjects;

use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use App\Models\TaskResourceRequirement;

class NormalizedTaskResource
{
    public function __construct(
        public readonly string $resourceName,
        public readonly string $normalizedName,
        public readonly InventoryItemType $inventoryItemType,
        public readonly InventoryUnit $unit,
        public readonly string $resourceMode,
        public readonly float $requiredQuantity,
        public readonly float $shortageQuantity = 0.0,
        public readonly ?int $requirementId = null,
    ) {
    }

    /**
     * @param  array<string, mixed>|TaskResourceRequirement  $resource
     */
    public static function from(array|TaskResourceRequirement $resource): self
    {
        $resourceName = (string) ($resource instanceof TaskResourceRequirement
            ? $resource->resource_name
            : ($resource['resource_name'] ?? $resource['name'] ?? ''));
        $normalizedName = mb_strtolower(trim((string) ($resource instanceof TaskResourceRequirement
            ? ($resource->normalized_name ?: $resourceName)
            : ($resource['normalized_name'] ?? $resourceName))));
        $type = $resource instanceof TaskResourceRequirement
            ? ($resource->inventory_item_type ?? InventoryItemType::Material)
            : ($resource['inventory_item_type'] ?? $resource['type'] ?? InventoryItemType::Material);
        $unit = $resource instanceof TaskResourceRequirement
            ? ($resource->unit ?? InventoryUnit::Unit)
            : ($resource['unit'] ?? InventoryUnit::Unit);
        $resourceMode = self::normalizeResourceMode($resource instanceof TaskResourceRequirement
            ? ['resource_mode' => $resource->is_consumed ? 'consumable' : 'reusable']
            : $resource);

        return new self(
            resourceName: $resourceName,
            normalizedName: $normalizedName,
            inventoryItemType: $type instanceof InventoryItemType ? $type : InventoryItemType::from((string) $type),
            unit: $unit instanceof InventoryUnit ? $unit : InventoryUnit::from((string) $unit),
            resourceMode: $resourceMode,
            requiredQuantity: round((float) ($resource instanceof TaskResourceRequirement
                ? $resource->required_quantity
                : ($resource['required_quantity'] ?? $resource['quantity'] ?? 0)), 2),
            shortageQuantity: round((float) ($resource instanceof TaskResourceRequirement
                ? $resource->shortage_quantity
                : ($resource['shortage_quantity'] ?? 0)), 2),
            requirementId: $resource instanceof TaskResourceRequirement ? $resource->id : ($resource['requirement_id'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    public static function normalizeResourceMode(array $resource): string
    {
        $mode = $resource['resource_mode'] ?? null;

        if (is_string($mode) && in_array($mode, ['consumable', 'reusable'], true)) {
            return $mode;
        }

        return (bool) ($resource['is_consumed'] ?? true)
            ? 'consumable'
            : 'reusable';
    }

    public function key(): string
    {
        return implode('|', [
            $this->inventoryItemType->value,
            $this->unit->value,
            $this->resourceMode,
            $this->normalizedName,
        ]);
    }

    public function isConsumable(): bool
    {
        return $this->resourceMode === 'consumable';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resource_key' => $this->key(),
            'resource_name' => $this->resourceName,
            'normalized_name' => $this->normalizedName,
            'inventory_item_type' => $this->inventoryItemType->value,
            'unit' => $this->unit->value,
            'resource_mode' => $this->resourceMode,
            'required_quantity' => $this->requiredQuantity,
            'shortage_quantity' => $this->shortageQuantity,
            'is_consumed' => $this->isConsumable(),
            'requirement_id' => $this->requirementId,
        ];
    }
}
