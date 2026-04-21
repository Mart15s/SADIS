<?php

namespace App\Http\Requests\Harvest;

use Illuminate\Foundation\Http\FormRequest;

class StoreHarvestRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plant_id' => ['required', 'integer', 'exists:plants,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'harvested_on' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
