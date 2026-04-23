<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryItemType;
use App\Enums\InventoryUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'type' => ['sometimes', 'required_without:inventory_item_type', Rule::enum(InventoryItemType::class)],
            'inventory_item_type' => ['sometimes', Rule::enum(InventoryItemType::class)],
            'unit' => ['sometimes', 'nullable', Rule::enum(InventoryUnit::class)],
            'source_task_id' => ['sometimes', 'integer', 'exists:tasks,id'],
            'source_requirement_id' => ['sometimes', 'integer', 'exists:task_resource_requirements,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->exists('name')) {
            $name = $this->input('name');
            $data['name'] = is_string($name) ? trim($name) : $name;
        }

        if ($this->exists('type')) {
            $type = $this->input('type');
            $data['type'] = is_string($type) ? trim(strtolower($type)) : $type;
            $data['inventory_item_type'] = $data['type'];
        }

        if ($this->exists('inventory_item_type')) {
            $type = $this->input('inventory_item_type');
            $data['inventory_item_type'] = is_string($type) ? trim(strtolower($type)) : $type;
            $data['type'] = $data['inventory_item_type'];
        }

        if ($this->exists('unit')) {
            $unit = $this->input('unit');
            $data['unit'] = is_string($unit) ? trim(strtolower($unit)) : $unit;
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $typeValue = $this->input('inventory_item_type', $this->input('type'));

            if (! $typeValue || ! is_string($typeValue)) {
                return;
            }

            try {
                $type = InventoryItemType::from((string) $typeValue);
            } catch (\ValueError) {
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
}
