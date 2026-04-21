<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

class ListCalendarTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'plant_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
        ];
    }
}
