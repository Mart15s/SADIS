<?php

namespace App\Http\Requests\Community;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommunityPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'text' => ['required', 'string'],
            'share' => ['required', 'boolean'],
            'fk_plot_id' => ['nullable', 'exists:plots,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $text = $this->input('text');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'text' => is_string($text) ? trim($text) : $text,
        ]);
    }
}
