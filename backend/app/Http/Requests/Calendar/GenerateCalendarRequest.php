<?php

namespace App\Http\Requests\Calendar;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class GenerateCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if (! $this->filled('start_date') || ! $this->filled('end_date')) {
                    return;
                }

                $start = Carbon::parse($this->input('start_date'))->startOfDay();
                $end = Carbon::parse($this->input('end_date'))->startOfDay();

                if ($start->diffInDays($end) > 180) {
                    $validator->errors()->add('end_date', 'Kalendoriaus generavimo laikotarpis negali viršyti 180 dienų.');
                }
            },
        ];
    }
}
