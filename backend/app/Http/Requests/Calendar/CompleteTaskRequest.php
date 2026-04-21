<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
