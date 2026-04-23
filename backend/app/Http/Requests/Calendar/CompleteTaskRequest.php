<?php

namespace App\Http\Requests\Calendar;

use App\Enums\ConditionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'materials_used' => ['sometimes', 'array'],
            'materials_used.*.name' => ['required_with:materials_used', 'string', 'max:255'],
            'materials_used.*.quantity' => ['required_with:materials_used', 'numeric', 'gt:0'],
            'condition_review' => ['sometimes', 'array'],
            'condition_review.action' => ['required_with:condition_review', Rule::in(['confirm', 'keep_current', 'adjust'])],
            'condition_review.condition' => ['nullable', Rule::enum(ConditionType::class)],
            'condition_review.measured_at' => ['nullable', 'date'],
            'condition_review.notes' => ['nullable', 'string'],
            'harvest' => ['sometimes', 'array'],
            'harvest.quantity' => ['required_with:harvest', 'numeric', 'gt:0'],
            'harvest.harvested_on' => ['required_with:harvest', 'date'],
            'harvest.notes' => ['nullable', 'string'],
        ];
    }
}
