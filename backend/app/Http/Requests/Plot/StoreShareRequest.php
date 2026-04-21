<?php

namespace App\Http\Requests\Plot;

use Illuminate\Foundation\Http\FormRequest;

class StoreShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient_email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', 'in:viewer,editor'],
        ];
    }
}
