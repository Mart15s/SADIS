<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'type' => ['required_without:inventory_item_type', Rule::enum(InventoryItemType::class)],
            'inventory_item_type' => ['sometimes', Rule::enum(InventoryItemType::class)],
            'unit' => ['nullable', Rule::enum(InventoryUnit::class)],
            'source_task_id' => ['sometimes', 'integer', 'exists:tasks,id'],
            'source_requirement_id' => ['sometimes', 'integer', 'exists:task_resource_requirements,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $type = $this->input('inventory_item_type', $this->input('type'));
        $unit = $this->input('unit');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'type' => is_string($type) ? trim(strtolower($type)) : $type,
            'inventory_item_type' => is_string($type) ? trim(strtolower($type)) : $type,
            'unit' => is_string($unit) ? trim(strtolower($unit)) : $unit,
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->validatedEnumType();

            if (! $type) {
                return;
            }

            $unit = $this->input('unit') ?: InventoryUnit::Unit->value;
            $quantity = $this->input('quantity');
            if ($type === InventoryItemType::Tool && $unit !== InventoryUnit::Unit->value) {
                $validator->errors()->add('unit', 'Irankiams leidziamas tik vienetu matavimo vienetas.');
            }

            if ($type === InventoryItemType::Tool && $quantity !== null && floor((float) $quantity) !== (float) $quantity) {
                $validator->errors()->add('quantity', 'Irankiu kiekis turi buti sveikas vienetu skaicius.');
            }
        });
    }

    private function validatedEnumType(): ?InventoryItemType
    {
        $type = $this->input('inventory_item_type') ?? $this->input('type');

        if (! is_string($type) || $type === '') {
            return null;
        }

        try {
            return InventoryItemType::from($type);
        } catch (\ValueError) {
            return null;
        }
    }
}
